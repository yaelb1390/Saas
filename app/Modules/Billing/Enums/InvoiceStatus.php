<?php

declare(strict_types=1);

namespace App\Modules\Billing\Enums;

enum InvoiceStatus: string
{
    case Issued = 'issued';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Issued => 'Emitida',
            self::Cancelled => 'Anulada',
        };
    }

    public function badge(): string
    {
        return match ($this) {
            self::Issued => 'badge-green',
            self::Cancelled => 'badge-red',
        };
    }
}
