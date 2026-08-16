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
 *
 * SALVO SI LA VENTA NO SE HA COBRADO. Un pedido a domicilio que paga el cliente en la puerta se
 * registra a crédito y con «pagado 0»: el negocio hizo la venta, pero el dinero está en la calle. Si
 * se anotara aquí, el saldo de «Caja General» diría que tiene unos pesos que nadie ha traído todavía,
 * y el dueño vería más dinero del que puede contar.
 *
 * La regla del sistema es una sola frase: el dinero se anota cuando llega. Lo de estas ventas lo
 * anota `DeliveryService::settle()` cuando el motorista lo entrega.
 */
final class RecordSaleIncome
{
    public function __construct(private readonly FinanceService $finance) {}

    public function handle(SaleCompleted $event): void
    {
        $sale = $event->sale;

        if (! $sale->estaCobrada()) {
            return;
        }

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
