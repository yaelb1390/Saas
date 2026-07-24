<?php

declare(strict_types=1);

namespace App\Modules\Loans\Enums;

enum InstallmentStatus: string
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

    /** Una cuota admite abonos mientras no esté saldada. */
    public function canBePaid(): bool
    {
        return $this !== self::Paid;
    }
}
