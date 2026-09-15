<?php

declare(strict_types=1);

namespace App\Modules\Rental\Listeners;

use App\Modules\Core\Tenancy\CompanyScope;
use App\Modules\Finance\Enums\MovementType;
use App\Modules\Finance\Models\Account;
use App\Modules\Finance\Services\FinanceService;
use App\Modules\Rental\Enums\PaymentKind;
use App\Modules\Rental\Events\RentalPaymentRegistered;
use Throwable;

/**
 * Al cobrar un abono de alquiler, registra el ingreso en la cuenta por defecto.
 *
 * Sigue el patrón de `RecordSaleIncome`/`RecordLoanPayment` y NO el de `RecordVehicleExpense`: pasa
 * el MODELO real como referencia (`$payment`, no un string armado a mano), para que
 * `FinancialMovement` tenga un vínculo polimórfico de verdad y se pueda ir del movimiento al abono
 * que lo originó.
 */
final class RecordRentalIncome
{
    public function __construct(private readonly FinanceService $finance) {}

    public function handle(RentalPaymentRegistered $event): void
    {
        $payment = $event->payment;

        if (bccomp((string) $payment->amount, '0', 2) <= 0) {
            return;
        }

        // Sin scope de tenant: puede correr en cola, sin «empresa activa» en el contexto de la
        // petición que la disparó.
        $account = Account::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $payment->company_id)
            ->where('is_default', true)
            ->first();

        if ($account === null) {
            return;
        }

        $kind = $payment->kind instanceof PaymentKind ? $payment->kind->label() : 'Alquiler';
        $codigo = $payment->rental?->code ?? "ALQ-{$payment->vehicle_rental_id}";

        try {
            $this->finance->record(
                $account,
                MovementType::Income,
                (string) $payment->amount,
                "{$kind} {$codigo}",
                ['reference' => $payment],
            );
        } catch (Throwable $e) {
            report($e);
        }
    }
}
