<?php

declare(strict_types=1);

namespace App\Modules\Rental\Enums;

/** Qué se cobró en un abono del alquiler: hace falta para el desglose del recibo y del reporte. */
enum PaymentKind: string
{
    case Deposit = 'deposit';
    case Rental = 'rental';
    case ExtraKm = 'extra_km';
    case Damage = 'damage';
    case Fuel = 'fuel';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Deposit => 'Depósito',
            self::Rental => 'Alquiler',
            self::ExtraKm => 'Kilómetros de más',
            self::Damage => 'Daño',
            self::Fuel => 'Combustible',
            self::Other => 'Otro',
        };
    }
}
