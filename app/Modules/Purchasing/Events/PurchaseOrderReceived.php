<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Events;

use App\Modules\Purchasing\Models\PurchaseOrder;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Se dispara cuando una orden de compra se recibe y el stock ya fue incrementado.
 * Punto de enganche para automatizaciones (n8n), cuentas por pagar y notificaciones.
 */
final class PurchaseOrderReceived
{
    use Dispatchable;

    public function __construct(public readonly PurchaseOrder $purchaseOrder) {}
}
