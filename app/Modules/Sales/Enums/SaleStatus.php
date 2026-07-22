<?php

declare(strict_types=1);

namespace App\Modules\Sales\Enums;

enum SaleStatus: string
{
    case Draft = 'draft';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Borrador',
            self::Completed => 'Completada',
            self::Cancelled => 'Cancelada',
        };
    }
}
