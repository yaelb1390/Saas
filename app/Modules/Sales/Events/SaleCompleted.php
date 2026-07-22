<?php

declare(strict_types=1);

namespace App\Modules\Sales\Events;

use App\Modules\Sales\Models\Sale;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Se dispara cuando una venta se completa y el stock ya fue descontado. Punto de enganche para
 * automatizaciones (n8n), facturación DGII, CRM y notificaciones por WhatsApp.
 */
final class SaleCompleted
{
    use Dispatchable;

    public function __construct(public readonly Sale $sale) {}
}
