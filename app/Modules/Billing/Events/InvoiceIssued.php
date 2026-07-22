<?php

declare(strict_types=1);

namespace App\Modules\Billing\Events;

use App\Modules\Billing\Models\Invoice;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Se dispara al emitir una factura con NCF. Punto de enganche para el envío del e-CF a la DGII,
 * automatizaciones (n8n) y el envío al cliente por WhatsApp/correo.
 */
final class InvoiceIssued
{
    use Dispatchable;

    public function __construct(public readonly Invoice $invoice) {}
}
