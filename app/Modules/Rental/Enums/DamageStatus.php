<?php

declare(strict_types=1);

namespace App\Modules\Rental\Enums;

/** Qué se decidió sobre un daño: cobrarlo, condonarlo, o todavía nada. */
enum DamageStatus: string
{
    case Pending = 'pending';
    case Charged = 'charged';
    case Waived = 'waived';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pendiente',
            self::Charged => 'Cobrado',
            self::Waived => 'Condonado',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Pending => 'badge-amber',
            self::Charged => 'badge-blue',
            self::Waived => 'badge-gray',
        };
    }
}
