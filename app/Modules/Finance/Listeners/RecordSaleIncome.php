<?php

declare(strict_types=1);

namespace App\Modules\Finance\Listeners;

use App\Modules\Core\Tenancy\CompanyScope;
use App\Modules\Finance\Enums\MovementType;
use App\Modules\Finance\Models\Account;
use App\Modules\Finance\Services\FinanceService;
use App\Modules\Sales\Events\SaleCompleted;
use Throwable;

/**
 * Automatización: al completarse una venta, registra el ingreso en la cuenta por defecto.
 * Es defensivo: un fallo contable nunca debe abortar la venta ya realizada.
 */
final class RecordSaleIncome
{
    public function __construct(private readonly FinanceService $finance) {}

    public function handle(SaleCompleted $event): void
    {
        $sale = $event->sale;

        $account = Account::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $sale->company_id)
            ->where('is_default', true)
            ->first();

        if ($account === null) {
            return;
        }

        try {
            $this->finance->record(
                $account,
                MovementType::Income,
                (string) $sale->total,
                "Venta {$sale->code}",
                ['reference' => $sale],
            );
        } catch (Throwable $e) {
            report($e);
        }
    }
}
