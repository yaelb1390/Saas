<?php

declare(strict_types=1);

namespace App\Modules\Finance\Enums;

enum ReceivableStatus: string
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

    /** El tono de la etiqueta en pantalla, con los mismos nombres que el resto del panel. */
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
