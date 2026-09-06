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

        /*
         * SE APUNTA LO QUE DE VERDAD HA ENTRADO, no el total de la venta.
         *
         * Para todo lo que existía hoy el número es idéntico: una venta cobrada imputa su total, y
         * una a crédito imputa cero y se salta igual que antes —ese dinero se anota cuando el
         * motorista liquida—. Lo que cambia es el día que una venta se cobre mitad al contado y
         * mitad a crédito: apuntar el total entero enseñaría al dueño dinero que sigue en la calle.
         */
        $cobrado = $sale->desglose()->cobradoAhora();

        if (bccomp($cobrado, '0', 2) <= 0) {
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
                $cobrado,
                "Venta {$sale->code}",
                ['reference' => $sale],
            );
        } catch (Throwable $e) {
            report($e);
        }
    }
}
