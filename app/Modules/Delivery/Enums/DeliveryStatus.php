<?php

declare(strict_types=1);

namespace App\Modules\Delivery\Enums;

enum DeliveryStatus: string
{
    case Pending = 'pending';
    case Assigned = 'assigned';
    case InTransit = 'in_transit';
    case Delivered = 'delivered';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pendiente',
            self::Assigned => 'Asignado',
            self::InTransit => 'En tránsito',
            self::Delivered => 'Entregado',
            self::Failed => 'Fallido',
        };
    }

    public function isFinal(): bool
    {
        return in_array($this, [self::Delivered, self::Failed], true);
    }
}
