<?php

declare(strict_types=1);

namespace App\Modules\Finance\Listeners;

use App\Modules\Finance\Services\ReceivableService;
use App\Modules\Sales\Events\SaleCompleted;
use Throwable;

/**
 * Automatización: al completarse una venta que no se cobró del todo (a crédito, o parte al
 * contado y parte a crédito), nace sola su cuenta por cobrar con lo que falta por cobrar.
 *
 * Es defensivo, como `RecordSaleIncome`: un fallo aquí nunca debe abortar la venta ya realizada.
 * `ReceivableService::crearDesdeVenta()` ya se protege contra duplicados si el evento se
 * disparase dos veces para la misma venta.
 */
final class CreateReceivableFromSale
{
    public function __construct(private readonly ReceivableService $receivables) {}

    public function handle(SaleCompleted $event): void
    {
        $sale = $event->sale;

        $pendiente = bcsub((string) $sale->total, $sale->desglose()->cobradoAhora(), 2);

        if (bccomp($pendiente, '0', 2) <= 0) {
            return;
        }

        try {
            $this->receivables->crearDesdeVenta($sale, $pendiente);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
