<?php

declare(strict_types=1);

namespace App\Modules\Loans\Listeners;

use App\Modules\Core\Tenancy\CompanyScope;
use App\Modules\Finance\Enums\MovementType;
use App\Modules\Finance\Models\Account;
use App\Modules\Finance\Services\FinanceService;
use App\Modules\Loans\Events\LoanDisbursed;
use Throwable;

/**
 * Automatización: al desembolsar un préstamo, registra el EGRESO del capital en la cuenta por
 * defecto (el dinero salió de la caja). Defensivo: un fallo contable no aborta el préstamo.
 */
final class RecordLoanDisbursement
{
    public function __construct(private readonly FinanceService $finance) {}

    public function handle(LoanDisbursed $event): void
    {
        $loan = $event->loan;

        $account = Account::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $loan->company_id)
            ->where('is_default', true)
            ->first();

        if ($account === null) {
            return;
        }

        try {
            $this->finance->record(
                $account,
                MovementType::Expense,
                (string) $loan->principal,
                "Desembolso préstamo {$loan->code}",
                ['reference' => $loan],
            );
        } catch (Throwable $e) {
            report($e);
        }
    }
}
