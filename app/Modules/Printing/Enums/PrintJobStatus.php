<?php

declare(strict_types=1);

namespace App\Modules\Printing\Enums;

/**
 * Cómo terminó un trabajo de impresión, para el historial.
 */
enum PrintJobStatus: string
{
    case Printed = 'printed';
    case Error = 'error';
    case Canceled = 'canceled';

    public function label(): string
    {
        return match ($this) {
            self::Printed => 'Impreso',
            self::Error => 'Error',
            self::Canceled => 'Cancelado',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Printed => 'badge-green',
            self::Error => 'badge-red',
            self::Canceled => 'badge-gray',
        };
    }
}
