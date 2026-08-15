<?php

declare(strict_types=1);

namespace App\Modules\Loans\Services;

use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\CRM\Models\Customer;
use App\Modules\Loans\DTOs\CreateApplicationData;
use App\Modules\Loans\DTOs\CreateLoanData;
use App\Modules\Loans\Enums\LoanApplicationStatus;
use App\Modules\Loans\Exceptions\LoanException;
use App\Modules\Loans\Models\Loan;
use App\Modules\Loans\Models\LoanApplication;
use Illuminate\Support\Facades\DB;

/**
 * Ciclo de vida de una solicitud de préstamo: recibirla, evaluarla, decidirla y desembolsarla.
 *
 * LA REGLA QUE GOBIERNA TODO ESTO: una solicitud no mueve dinero. Solo el desembolso lo mueve.
 *
 * Es lo que la separa de lo que ya había. Hoy aprobar y entregar el efectivo son el mismo acto —el
 * préstamo nace ya desembolsado—, y por eso no hay forma de revisar una aprobación antes de que el
 * dinero salga de la caja. Aquí son dos pasos, y solo el segundo toca Finanzas.
 *
 * El desembolso NO reimplementa nada: arma un `CreateLoanData` y llama a `LoanService::create()`,
 * que ya calcula la amortización, dispara `LoanDisbursed` y registra el egreso. Ese es el punto de
 * unión y es deliberado: el motor que funciona no se toca.
 *
 * Las transiciones se validan aquí y no en el controlador, porque una regla que vive en el
 * controlador solo protege a quien entra por esa puerta.
 */
final class LoanApplicationService
{
    private const SCALE = 2;

    public function __construct(private readonly LoanService $loans) {}

    public function create(CreateApplicationData $data): LoanApplication
    {
        return DB::transaction(function () use ($data): LoanApplication {
            $companyId = app(CurrentCompany::class)->id() ?? 0;
            $customer = $this->resolveCustomer($data->customerId, $companyId);

            $solicitud = new LoanApplication([
                'company_id' => $companyId,
                'customer_id' => $customer->id,
                'code' => $this->nextCode($companyId),
                'customer_name' => $customer->name,
                'principal' => $this->normalize($data->principal),
                'interest_rate' => $this->normalize($data->interestRate),
                'interest_amount' => $data->interestAmount !== null ? $this->normalize($data->interestAmount) : null,
                'frequency' => $data->frequency,
                'installments_count' => max(1, $data->installmentsCount),
                'late_fee_rate' => $data->lateFeeRate !== null ? $this->normalize($data->lateFeeRate) : null,
                'start_date' => $data->startDate,
                'collateral' => $data->collateral,
                'purpose' => $data->purpose,
                'notes' => $data->notes,
                'status' => LoanApplicationStatus::Received,
                'user_id' => auth()->id(),
            ]);
            $solicitud->save();

            return $solicitud;
        });
    }

    /**
     * Guarda los datos de la evaluación y deja la solicitud «en evaluación».
     *
     * Se puede llamar varias veces: los ingresos hoy, la cédula del garante cuando el cliente la
     * traiga. Para que eso sea verdad, SOLO se tocan los campos que vienen en la petición: un campo
     * ausente se deja como estaba, no se borra.
     *
     * La diferencia importa. El formulario del panel envía siempre los ocho campos, así que por ahí
     * da igual; pero con el atajo obvio —leer cada campo con `?? null`— cualquier otra llamada que
     * mandara solo el garante borraría los ingresos sin decir nada. Un campo que llega VACÍO sí se
     * borra, que es como se corrige un ingreso mal tecleado.
     *
     * @param  array<string, mixed>  $datos
     */
    public function evaluate(LoanApplication $solicitud, array $datos): LoanApplication
    {
        return DB::transaction(function () use ($solicitud, $datos): LoanApplication {
            $solicitud = $this->bloquear($solicitud);

            if (! $solicitud->status->admiteEdicion()) {
                throw LoanException::solicitudNoEditable(mb_strtolower($solicitud->status->label()));
            }

            $dinero = ['monthly_income', 'monthly_expenses', 'other_debts'];
            $texto = ['employment', 'guarantor_name', 'guarantor_phone', 'guarantor_cedula', 'evaluation_notes'];

            foreach ([...$dinero, ...$texto] as $campo) {
                if (! array_key_exists($campo, $datos)) {
                    continue;
                }

                $solicitud->{$campo} = in_array($campo, $dinero, true)
                    ? $this->opcional($datos[$campo])
                    : ($datos[$campo] === '' ? null : $datos[$campo]);
            }

            // Recibida pasa a «en evaluación» sola: si alguien ya está tomando los ingresos y el
            // garante, la solicitud está en evaluación aunque nadie pulse un botón que lo diga.
            if ($solicitud->status === LoanApplicationStatus::Received) {
                $solicitud->status = LoanApplicationStatus::UnderReview;
            }

            $solicitud->save();

            return $solicitud;
        });
    }

    /**
     * Aprueba la solicitud, opcionalmente con términos distintos a los pedidos.
     *
     * NO mueve dinero ni crea el préstamo: eso es el desembolso. Aprobado y sin desembolsar es un
     * estado real —está concedido y el cliente todavía no ha venido a buscar el efectivo—.
     *
     * @param  array{principal?: ?string, installments_count?: ?int, interest_rate?: ?string, notes?: ?string}  $ajustes
     */
    public function approve(LoanApplication $solicitud, array $ajustes = []): LoanApplication
    {
        return DB::transaction(function () use ($solicitud, $ajustes): LoanApplication {
            $solicitud = $this->bloquear($solicitud);
            $this->exigirDecidible($solicitud);

            $principal = $this->opcional($ajustes['principal'] ?? null);
            $tasa = $this->opcional($ajustes['interest_rate'] ?? null);
            $cuotas = $ajustes['installments_count'] ?? null;

            if ($principal !== null && bccomp($principal, '0', self::SCALE) <= 0) {
                throw LoanException::invalidAmount();
            }

            $solicitud->fill([
                // Solo se guarda lo que de verdad se ajustó. Copiar los pedidos aquí haría creer,
                // al leer el expediente, que hubo una negociación que no existió.
                'approved_principal' => $principal,
                'approved_installments_count' => $cuotas !== null ? max(1, (int) $cuotas) : null,
                'approved_interest_rate' => $tasa,
                'status' => LoanApplicationStatus::Approved,
                'decided_at' => now(),
                'decided_by' => auth()->id(),
                'decision_notes' => $ajustes['notes'] ?? null,
            ]);
            $solicitud->save();

            return $solicitud;
        });
    }

    public function reject(LoanApplication $solicitud, ?string $motivo = null): LoanApplication
    {
        return DB::transaction(function () use ($solicitud, $motivo): LoanApplication {
            $solicitud = $this->bloquear($solicitud);
            $this->exigirDecidible($solicitud);

            $solicitud->fill([
                'status' => LoanApplicationStatus::Rejected,
                'decided_at' => now(),
                'decided_by' => auth()->id(),
                'decision_notes' => $motivo,
            ]);
            $solicitud->save();

            return $solicitud;
        });
    }

    /**
     * Devuelve una solicitud decidida a evaluación.
     *
     * Solo mientras el dinero no haya salido. Deshacer un desembolso exigiría reversar el egreso de
     * caja y el préstamo ya creado, que tiene sus propias reglas en `LoanService::cancel()`.
     */
    public function reopen(LoanApplication $solicitud): LoanApplication
    {
        return DB::transaction(function () use ($solicitud): LoanApplication {
            $solicitud = $this->bloquear($solicitud);

            if (! $solicitud->status->admiteReapertura()) {
                throw LoanException::solicitudNoReabrible(mb_strtolower($solicitud->status->label()));
            }

            $solicitud->fill([
                'status' => LoanApplicationStatus::UnderReview,
                'decided_at' => null,
                'decided_by' => null,
                'decision_notes' => null,
                'approved_principal' => null,
                'approved_installments_count' => null,
                'approved_interest_rate' => null,
            ]);
            $solicitud->save();

            return $solicitud;
        });
    }

    /**
     * Entrega el dinero: crea el préstamo con los términos APROBADOS y lo enlaza a la solicitud.
     *
     * Este es el único método de la clase que mueve dinero, y por eso es el único que puede hacer
     * daño de verdad: dos desembolsos de la misma solicitud serían dos préstamos y dos egresos por
     * una sola operación. Contra eso hay tres cosas, no una:
     *
     *   1. El `lockForUpdate()` de `bloquear()`, que serializa dos peticiones simultáneas.
     *   2. La comprobación del estado, que solo admite «aprobada».
     *   3. La comprobación de `loan_id`, que es la prueba material de que ya se desembolsó.
     *
     * La tercera parece redundante con la segunda y no lo es: si un día alguien añade un camino que
     * deje el estado en «aprobada» con préstamo ya creado, es la que lo detiene.
     */
    public function disburse(LoanApplication $solicitud): Loan
    {
        return DB::transaction(function () use ($solicitud): Loan {
            $solicitud = $this->bloquear($solicitud);

            if ($solicitud->loan_id !== null) {
                /** @var Loan|null $ya */
                $ya = Loan::query()->withTrashed()->find($solicitud->loan_id);
                throw LoanException::solicitudYaDesembolsada($ya?->code ?? (string) $solicitud->loan_id);
            }

            if ($solicitud->status !== LoanApplicationStatus::Approved) {
                throw LoanException::solicitudNoDesembolsable(mb_strtolower($solicitud->status->label()));
            }

            // Se traduce a lo que el motor de préstamos ya sabe hacer. Los términos que viajan son
            // los EFECTIVOS: si se aprobaron 30.000 de los 50.000 pedidos, salen 30.000.
            $prestamo = $this->loans->create(new CreateLoanData(
                customerId: $solicitud->customer_id,
                principal: $solicitud->capitalEfectivo(),
                installmentsCount: $solicitud->cuotasEfectivas(),
                frequency: $solicitud->frequency,
                startDate: $solicitud->start_date->toDateString(),
                interestRate: $solicitud->tasaEfectiva(),
                interestAmount: $solicitud->interest_amount !== null ? (string) $solicitud->interest_amount : null,
                lateFeeRate: $solicitud->late_fee_rate !== null ? (string) $solicitud->late_fee_rate : null,
                collateral: $solicitud->collateral,
                notes: $solicitud->notes,
            ));

            $solicitud->fill([
                'status' => LoanApplicationStatus::Disbursed,
                'loan_id' => $prestamo->id,
            ]);
            $solicitud->save();

            return $prestamo;
        });
    }

    /** El cliente desistió. No es un rechazo: la agencia no dijo que no. */
    public function cancel(LoanApplication $solicitud, ?string $motivo = null): LoanApplication
    {
        return DB::transaction(function () use ($solicitud, $motivo): LoanApplication {
            $solicitud = $this->bloquear($solicitud);

            if ($solicitud->status === LoanApplicationStatus::Disbursed) {
                throw LoanException::solicitudNoReabrible('desembolsada');
            }

            $solicitud->fill([
                'status' => LoanApplicationStatus::Cancelled,
                'decided_at' => now(),
                'decided_by' => auth()->id(),
                'decision_notes' => $motivo,
            ]);
            $solicitud->save();

            return $solicitud;
        });
    }

    /**
     * Relee la solicitud con bloqueo de fila dentro de la transacción.
     *
     * Sin esto, dos pulsaciones del botón de desembolsar leerían las dos el estado «aprobada» antes
     * de que ninguna lo cambiara, y ambas crearían su préstamo. Es la misma protección que ya usa
     * `LoanService::registerPayment()` sobre el saldo.
     */
    private function bloquear(LoanApplication $solicitud): LoanApplication
    {
        /** @var LoanApplication $fresca */
        $fresca = LoanApplication::query()->whereKey($solicitud->id)->lockForUpdate()->firstOrFail();

        return $fresca;
    }

    private function exigirDecidible(LoanApplication $solicitud): void
    {
        if (! $solicitud->status->admiteDecision()) {
            throw LoanException::solicitudNoDecidible(mb_strtolower($solicitud->status->label()));
        }
    }

    private function resolveCustomer(int $customerId, int $companyId): Customer
    {
        /** @var Customer|null $customer */
        $customer = Customer::withoutCompanyScope()
            ->where('company_id', $companyId)
            ->whereKey($customerId)
            ->first();

        if ($customer === null) {
            throw LoanException::customerNotInCompany($customerId, $companyId);
        }

        return $customer;
    }

    private function nextCode(int $companyId): string
    {
        $count = LoanApplication::withoutCompanyScope()
            ->withTrashed()
            ->where('company_id', $companyId)
            ->count();

        return 'SOL-'.str_pad((string) ($count + 1), 6, '0', STR_PAD_LEFT);
    }

    /** Vacío o nulo significa «no se indicó», que no es lo mismo que cero. */
    private function opcional(mixed $valor): ?string
    {
        return $valor === null || $valor === '' ? null : $this->normalize((string) $valor);
    }

    private function normalize(string $value): string
    {
        return bcadd($value === '' ? '0' : $value, '0', self::SCALE);
    }
}
