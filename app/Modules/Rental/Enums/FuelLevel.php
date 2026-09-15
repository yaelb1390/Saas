<?php

declare(strict_types=1);

namespace App\Modules\Rental\Enums;

/** Cuánto combustible tenía el vehículo al entregarlo o al devolverlo. */
enum FuelLevel: string
{
    case Empty = 'empty';
    case Quarter = 'quarter';
    case Half = 'half';
    case ThreeQuarters = 'three_quarters';
    case Full = 'full';

    public function label(): string
    {
        return match ($this) {
            self::Empty => 'Vacío',
            self::Quarter => '1/4',
            self::Half => '1/2',
            self::ThreeQuarters => '3/4',
            self::Full => 'Lleno',
        };
    }

    /** Fracción del tanque, para comparar entrega vs. devolución. */
    public function fraction(): float
    {
        return match ($this) {
            self::Empty => 0.0,
            self::Quarter => 0.25,
            self::Half => 0.5,
            self::ThreeQuarters => 0.75,
            self::Full => 1.0,
        };
    }
}
