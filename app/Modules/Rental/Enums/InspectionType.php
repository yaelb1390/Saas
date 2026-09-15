<?php

declare(strict_types=1);

namespace App\Modules\Rental\Enums;

/** Si el checklist es de la entrega o de la devolución del vehículo. */
enum InspectionType: string
{
    case Pickup = 'pickup';
    case Return = 'return';

    public function label(): string
    {
        return match ($this) {
            self::Pickup => 'Entrega',
            self::Return => 'Devolución',
        };
    }
}
