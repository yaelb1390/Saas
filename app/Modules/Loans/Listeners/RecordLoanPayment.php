<?php

declare(strict_types=1);

namespace App\Modules\Loans\Listeners;

use App\Modules\Core\Tenancy\CompanyScope;
use App\Modules\Finance\Enums\MovementType;
use App\Modules\Finance\Models\Account;
use App\Modules\Finance\Services\FinanceService;
use App\Modules\Loans\Events\LoanPaymentRegistered;
use Throwable;

/**
 * Automatización: cada abono/cobro de un préstamo entra como INGRESO en la cuenta por defecto.
 * Defensivo: un fallo contable no aborta el cobro ya registrado.
 */
final class RecordLoanPayment
{
    public function __construct(private readonly FinanceService $finance) {}

    public function handle(LoanPaymentRegistered $event): void
    {
        $payment = $event->payment;

        $account = Account::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $payment->company_id)
            ->where('is_default', true)
            ->first();

        if ($account === null) {
            return;
        }

        try {
            $this->finance->record(
                $account,
                MovementType::Income,
                (string) $payment->amount,
                "Cobro préstamo {$payment->loan->code}",
                ['reference' => $payment],
            );
        } catch (Throwable $e) {
            report($e);
        }
    }
}
