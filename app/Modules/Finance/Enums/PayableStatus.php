<?php

declare(strict_types=1);

namespace App\Modules\Finance\Enums;

enum PayableStatus: string
{
    case Pending = 'pending'; // sin abonar
    case Partial = 'partial'; // abonada en parte
    case Paid = 'paid';       // saldada

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pendiente',
            self::Partial => 'Parcial',
            self::Paid => 'Pagada',
        };
    }

    public function badge(): string
    {
        return match ($this) {
            self::Pending => 'badge-amber',
            self::Partial => 'badge-blue',
            self::Paid => 'badge-green',
        };
    }

    /** Admite abonos mientras no esté saldada. */
    public function canBePaid(): bool
    {
        return $this !== self::Paid;
    }
}
