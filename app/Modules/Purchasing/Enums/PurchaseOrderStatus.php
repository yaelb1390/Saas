<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Enums;

enum PurchaseOrderStatus: string
{
    case Draft = 'draft';
    case Ordered = 'ordered';
    case Received = 'received';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Borrador',
            self::Ordered => 'Ordenada',
            self::Received => 'Recibida',
            self::Cancelled => 'Cancelada',
        };
    }

    public function canBeReceived(): bool
    {
        return in_array($this, [self::Draft, self::Ordered], true);
    }
}
