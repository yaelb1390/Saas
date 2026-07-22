<?php

declare(strict_types=1);

namespace App\Modules\Delivery\Events;

use App\Modules\Delivery\Enums\DeliveryStatus;
use App\Modules\Delivery\Models\Delivery;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Se dispara al cambiar el estado de una entrega. Punto de enganche para notificar al cliente
 * por WhatsApp y automatizaciones (n8n).
 */
final class DeliveryStatusChanged
{
    use Dispatchable;

    public function __construct(
        public readonly Delivery $delivery,
        public readonly DeliveryStatus $from,
        public readonly DeliveryStatus $to,
    ) {}
}
