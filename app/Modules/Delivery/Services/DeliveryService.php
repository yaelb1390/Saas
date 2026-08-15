<?php

declare(strict_types=1);

namespace App\Modules\Delivery\Services;

use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\CRM\Models\Customer;
use App\Modules\Delivery\Enums\DeliveryStatus;
use App\Modules\Delivery\Events\DeliveryStatusChanged;
use App\Modules\Delivery\Exceptions\DeliveryException;
use App\Modules\Delivery\Models\Delivery;
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

    public function transition(Delivery $delivery, DeliveryStatus $to): Delivery
    {
        $from = $delivery->status;

        if (! $from->admiteIr($to)) {
            throw DeliveryException::transicionInvalida($from->label(), $to->label());
        }

        $delivery->status = $to;

        if ($to === DeliveryStatus::Delivered || $to === DeliveryStatus::Failed) {
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
     * Liquida de golpe todo lo que un repartidor tiene cobrado y sin entregar en caja.
     *
     * Devuelve cuántas entregas y cuánto dinero, que es lo que el cajero necesita para contar antes
     * de dar el visto bueno.
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

            return ['entregas' => $pendientes->count(), 'total' => $total];
        });
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
