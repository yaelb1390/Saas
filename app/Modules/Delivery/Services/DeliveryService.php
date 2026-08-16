<?php

declare(strict_types=1);

namespace App\Modules\Delivery\Services;

use App\Modules\Cash\Enums\CashMovementType;
use App\Modules\Cash\Enums\CashSessionStatus;
use App\Modules\Cash\Models\CashSession;
use App\Modules\Cash\Services\CashService;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\CRM\Models\Customer;
use App\Modules\Delivery\Enums\DeliveryOutcomeReason;
use App\Modules\Delivery\Enums\DeliveryStatus;
use App\Modules\Delivery\Events\DeliveryStatusChanged;
use App\Modules\Delivery\Exceptions\DeliveryException;
use App\Modules\Delivery\Models\Delivery;
use App\Modules\Finance\Enums\MovementType;
use App\Modules\Finance\Models\Account;
use App\Modules\Finance\Services\FinanceService;
use App\Modules\HR\Models\Employee;
use App\Modules\Sales\Models\Sale;
use Illuminate\Support\Facades\DB;

/**
 * Ciclo de vida de una entrega: se crea, se asigna a un repartidor, sale, llega, se cobra y se
 * liquida.
 *
 * Los tres primeros métodos existían y NO LOS LLAMABA NADIE: el módulo era una tabla y una pantalla
 * de solo lectura, sin una sola ruta que pudiera escribir en ella. Aquí se completan y se les añade
 * lo que faltaba para que sirvieran: el repartidor como empleado, el dinero que lleva encima y la
 * liquidación.
 *
 * SOBRE EL DINERO, que es la parte delicada:
 *
 * Liquidar NO registra ningún ingreso. La venta ya lo anotó al cobrarse —tanto en la cuenta como en
 * el cajón—, así que apuntarlo otra vez al liquidar contaría el mismo dinero dos veces. Lo que la
 * liquidación resuelve es de QUIÉN es la custodia: mientras el motorista no vuelva, ese efectivo
 * está en su mochila y no en la caja, y el arqueo lo canta como faltante sin que nadie sepa por qué.
 * Marcar la liquidación es decir «ya lo trajo».
 */
final class DeliveryService
{
    public function __construct(
        private readonly FinanceService $finance,
        private readonly CashService $cash,
    ) {}

    public function create(
        string $address,
        ?string $customerName = null,
        ?string $phone = null,
        ?Sale $sale = null,
        ?Customer $customer = null,
        ?string $amountToCollect = null,
        ?string $notes = null,
    ): Delivery {
        $companyId = (int) ($sale !== null ? $sale->company_id : app(CurrentCompany::class)->id());

        // El cliente explícito manda; si no viene, se hereda el de la venta que origina el reparto.
        $customer ??= $sale?->customer;

        $delivery = new Delivery([
            'company_id' => $companyId,
            'customer_id' => $customer?->id,
            'sale_id' => $sale?->id,
            'code' => $this->nextCode($companyId),
            'status' => DeliveryStatus::Pending,
            'customer_name' => $customerName ?? $sale?->customer_name ?? $customer?->name,
            'phone' => $phone ?? $customer?->phone,
            'address' => $address,
            // Si nace de una venta ya cobrada, no hay nada que cobrar en la puerta.
            'amount_to_collect' => $this->normalizar($amountToCollect ?? '0'),
            'notes' => $notes,
            'user_id' => auth()->id(),
        ]);
        $delivery->save();

        return $delivery;
    }

    /**
     * Asigna la entrega a un empleado.
     *
     * Se guarda TAMBIÉN el nombre en `driver_name`: si mañana se borra la ficha del empleado, la
     * entrega tiene que seguir diciendo quién la llevó. Es el mismo criterio que usan las ventas con
     * el nombre del cliente.
     */
    public function assign(Delivery $delivery, Employee $employee): Delivery
    {
        if ($delivery->status->esFinal()) {
            throw DeliveryException::yaCerrada($delivery->status->label());
        }

        $delivery->fill([
            'employee_id' => $employee->id,
            'driver_name' => $employee->name,
            'assigned_at' => now(),
        ]);

        return $this->transition($delivery, DeliveryStatus::Assigned);
    }

    /**
     * Le busca repartidor sola: el empleado activo con MENOS entregas abiertas encima.
     *
     * Reparte la carga en vez de amontonarla. Con «el primero de la lista» o «el que lleva menos
     * tiempo», uno acabaría saliendo con seis pedidos mientras otro espera de brazos cruzados —y el
     * cliente del sexto pedido paga esa espera—.
     *
     * Si no hay nadie activo NO se inventa un repartidor: la entrega se queda sin asignar y aparece
     * en la lista para hacerlo a mano. Colgársela a alguien que no está es peor que dejarla visible.
     */
    public function assignAutomatically(Delivery $delivery): Delivery
    {
        $candidato = Employee::query()
            ->where('is_active', true)
            ->withCount(['deliveries' => fn ($q) => $q->whereIn('status', DeliveryStatus::abiertas())])
            ->orderBy('deliveries_count')
            // Desempate estable: sin él, dos motoristas a cero saldrían en un orden que depende de la
            // base de datos y el reparto parecería caprichoso.
            ->orderBy('id')
            ->first();

        if ($candidato === null) {
            return $delivery;
        }

        return $this->assign($delivery, $candidato);
    }

    public function transition(Delivery $delivery, DeliveryStatus $to): Delivery
    {
        $from = $delivery->status;

        if (! $from->admiteIr($to)) {
            throw DeliveryException::transicionInvalida($from->label(), $to->label());
        }

        $delivery->status = $to;

        // `delivered_at` es CUÁNDO SE CERRÓ, no cuándo se entregó (ver el modelo). Sellar los tres
        // finales y no solo dos: sin la fecha, una entrega cancelada no salía en «cerradas hoy» del
        // portal y desaparecía de la pantalla del repartidor sin dejar rastro, que es exactamente lo
        // que esa lista existe para evitar cuando se pulsa el botón equivocado.
        if ($to->isFinal()) {
            $delivery->delivered_at = now();
        }

        $delivery->save();

        DeliveryStatusChanged::dispatch($delivery, $from, $to);

        return $delivery;
    }

    /**
     * El motorista entregó y cobró.
     *
     * Se separa de «entregada» porque no toda entrega se cobra en la puerta: la que ya venía pagada
     * se entrega y punto. Mezclarlos obligaría a inventar un cobro de cero para cerrar el pedido.
     */
    public function markCollected(Delivery $delivery): Delivery
    {
        if (bccomp((string) $delivery->amount_to_collect, '0', 2) <= 0) {
            throw DeliveryException::nadaQueCobrar();
        }

        // Repetir el cobro sumaría dos veces el mismo dinero al saldo del motorista: pediría en caja
        // el doble de lo que lleva y el cuadre no saldría nunca.
        if ($delivery->collected_at !== null) {
            throw DeliveryException::yaCobrada();
        }

        if ($delivery->status !== DeliveryStatus::Delivered) {
            $this->transition($delivery, DeliveryStatus::Delivered);
        }

        $delivery->forceFill(['collected_at' => now()])->save();

        return $delivery;
    }

    /**
     * Cierra la entrega con lo que pasó de verdad.
     *
     * Es el único camino a los tres finales, y existe porque el repartidor no elige un estado: elige
     * un MOTIVO —«no estaba nadie», «la rechazó en la puerta»— y el estado sale de él. Dejar que la
     * pantalla mandara el estado por su cuenta abriría la puerta a cerrar como «entregada» algo que
     * el motivo dice que no se entregó, y ese desacuerdo nadie lo notaría hasta cuadrar la caja.
     *
     * `$cobro` solo se atiende si la entrega cobra en la puerta y de verdad se entregó: no se le
     * puede cobrar a quien no abrió.
     */
    public function close(
        Delivery $delivery,
        DeliveryOutcomeReason $reason,
        ?string $note = null,
        bool $cobro = false,
    ): Delivery {
        return DB::transaction(function () use ($delivery, $reason, $note, $cobro): Delivery {
            // Se relee con bloqueo: esto se pulsa en un móvil, con el pulgar y con prisa, y el doble
            // toque no es un caso raro sino el normal. Sin el bloqueo, las dos peticiones leen la
            // entrega abierta y las dos la cierran.
            $entrega = Delivery::query()->whereKey($delivery->getKey())->lockForUpdate()->firstOrFail();

            // Cerrar es una vez. `admiteIr` deja pasar el mismo estado —hace falta para que reasignar
            // un repartidor no falle—, así que aquí se corta antes: si no, el segundo toque volvería
            // a sellar la hora del cobro y a machacar el motivo que ya se había anotado.
            if ($entrega->status->isFinal()) {
                throw DeliveryException::yaCerrada($entrega->status->label());
            }

            $destino = $reason->status();

            if (! $entrega->status->admiteIr($destino)) {
                throw DeliveryException::transicionInvalida($entrega->status->label(), $destino->label());
            }

            $entrega->forceFill([
                'outcome_reason' => $reason,
                'outcome_note' => $note,
            ]);

            $this->transition($entrega, $destino);

            if ($cobro && $destino === DeliveryStatus::Delivered && $entrega->cobraEnLaPuerta()) {
                $entrega->forceFill(['collected_at' => now()])->save();
            }

            return $entrega;
        });
    }

    /**
     * Liquida de golpe todo lo que un repartidor tiene cobrado y sin entregar en caja.
     *
     * AQUÍ ES DONDE ENTRA EL DINERO, y esto es una corrección: antes solo se sellaba la fecha y los
     * pesos que el motorista dejaba sobre el mostrador no aparecían en ningún sitio —ni en el saldo
     * de la cuenta ni en el arqueo del turno—. Se escribió así razonando que «la venta ya lo anotó»,
     * y eso solo era cierto para una entrega de una venta ya pagada, que no cobra nada en la puerta.
     * Una entrega con dinero a cobrar nunca lo tuvo anotado.
     *
     * La regla es una sola frase: EL DINERO SE ANOTA CUANDO LLEGA.
     *
     *   · Pedido pagado en el mostrador → ya se anotó al cobrar, y nace con «a cobrar 0», así que ni
     *     siquiera llega hasta aquí.
     *   · Pedido pagado en la puerta    → no se anotó nada al venderlo; se anota ahora.
     *
     * Se anota en los dos sitios porque son dos preguntas distintas: el INGRESO dice que el negocio
     * ganó ese dinero, y el MOVIMIENTO DE CAJA dice que está físicamente en el cajón. Sin el primero
     * las ganancias salen cortas; sin el segundo, el cierre del turno canta un sobrante.
     *
     * El movimiento de caja solo se anota si hay un turno abierto: si el motorista llega con la caja
     * ya cerrada, el ingreso queda igual y el efectivo se cuadra en el turno siguiente. Cerrar la
     * liquidación por eso dejaría el dinero en la calle una noche entera.
     *
     * @return array{entregas: int, total: string}
     */
    public function settle(Employee $employee): array
    {
        return DB::transaction(function () use ($employee): array {
            $pendientes = Delivery::query()
                ->where('employee_id', $employee->id)
                ->whereNotNull('collected_at')
                ->whereNull('settled_at')
                ->lockForUpdate()
                ->get();

            if ($pendientes->isEmpty()) {
                throw DeliveryException::nadaQueLiquidar($employee->name);
            }

            $total = $pendientes->reduce(
                static fn (string $suma, Delivery $d): string => bcadd($suma, (string) $d->amount_to_collect, 2),
                '0.00',
            );

            $ahora = now();

            foreach ($pendientes as $entrega) {
                $entrega->forceFill(['settled_at' => $ahora, 'settled_by' => auth()->id()])->save();
            }

            $this->anotarElDinero($employee, $total, $pendientes->count());

            return ['entregas' => $pendientes->count(), 'total' => $total];
        });
    }

    /** El efectivo que el motorista acaba de entregar: en las ganancias y en el cajón. */
    private function anotarElDinero(Employee $employee, string $total, int $entregas): void
    {
        if (bccomp($total, '0', 2) <= 0) {
            return;
        }

        $concepto = "Liquidación de {$employee->name}: {$entregas} ".($entregas === 1 ? 'entrega' : 'entregas');

        $cuenta = Account::query()->where('is_default', true)->first();

        if ($cuenta !== null) {
            $this->finance->record($cuenta, MovementType::Income, $total, $concepto);
        }

        $turno = CashSession::query()->where('status', CashSessionStatus::Open)->latest('opened_at')->first();

        if ($turno !== null) {
            $this->cash->registerMovement($turno, CashMovementType::Income, $total, ['notes' => $concepto]);
        }
    }

    /**
     * `withTrashed()` porque la entrega se archiva, no se destruye: sin contar las archivadas, la
     * siguiente reutilizaría un código ya usado y chocaría contra el índice único de
     * `(company_id, code)`.
     */
    private function nextCode(int $companyId): string
    {
        $count = Delivery::withoutCompanyScope()->withTrashed()->where('company_id', $companyId)->count();

        return 'ENV-'.str_pad((string) ($count + 1), 6, '0', STR_PAD_LEFT);
    }

    private function normalizar(string $valor): string
    {
        return bcadd($valor === '' ? '0' : $valor, '0', 2);
    }
}
