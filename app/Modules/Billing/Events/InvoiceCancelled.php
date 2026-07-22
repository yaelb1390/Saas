<?php

declare(strict_types=1);

namespace App\Modules\Billing\Events;

use App\Modules\Billing\Models\Invoice;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Se dispara al anular un comprobante. Punto de enganche para reversar inventario, notificar al
 * cliente y alimentar automatizaciones (n8n). El NCF queda inutilizado y va al formato 608.
 */
final class InvoiceCancelled
{
    use Dispatchable;

    public function __construct(public readonly Invoice $invoice) {}
}
