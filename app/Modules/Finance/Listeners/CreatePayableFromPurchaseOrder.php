<?php

declare(strict_types=1);

namespace App\Modules\Finance\Listeners;

use App\Modules\Finance\Services\PayableService;
use App\Modules\Purchasing\Events\PurchaseOrderReceived;
use Throwable;

/**
 * Automatización: al recibirse una orden de compra, nace sola su cuenta por pagar. Es el listener
 * que el propio evento ya anticipaba en su docblock ("punto de enganche [...] para cuentas por
 * pagar"). Defensivo: un fallo aquí nunca debe deshacer la recepción de mercancía ya hecha.
 */
final class CreatePayableFromPurchaseOrder
{
    public function __construct(private readonly PayableService $payables) {}

    public function handle(PurchaseOrderReceived $event): void
    {
        try {
            $this->payables->crearDesdeOrden($event->purchaseOrder);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
