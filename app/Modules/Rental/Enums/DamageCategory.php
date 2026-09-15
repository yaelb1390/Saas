<?php

declare(strict_types=1);

namespace App\Modules\Rental\Enums;

/** Qué tipo de daño se encontró en el vehículo. */
enum DamageCategory: string
{
    case Scratch = 'scratch';
    case Dent = 'dent';
    case Glass = 'glass';
    case Tire = 'tire';
    case Interior = 'interior';
    case Paint = 'paint';
    case Mechanical = 'mechanical';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Scratch => 'Rayón',
            self::Dent => 'Golpe',
            self::Glass => 'Vidrio',
            self::Tire => 'Neumático',
            self::Interior => 'Interior',
            self::Paint => 'Pintura',
            self::Mechanical => 'Mecánica',
            self::Other => 'Otro',
        };
    }
}
