<?php

declare(strict_types=1);

namespace App\Modules\Rental\Enums;

/**
 * En qué punto del ciclo está un alquiler.
 *
 * «Picked up» y «Active» de una reserva se colapsan en un solo estado (`Active`): la entrega ES la
 * transición a él, no hay un paso intermedio con su propia acción. `Returned` existe aparte de
 * `Completed` porque devolver el carro y liquidar la cuenta (depósito, cargos de más) casi nunca
 * pasan en el mismo instante.
 */
enum RentalStatus: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Active = 'active';
    case Returned = 'returned';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Reservado',
            self::Confirmed => 'Confirmado',
            self::Active => 'En curso',
            self::Returned => 'Devuelto',
            self::Completed => 'Cerrado',
            self::Cancelled => 'Cancelado',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Pending => 'badge-amber',
            self::Confirmed => 'badge-blue',
            self::Active => 'badge-violet',
            self::Returned => 'badge-gray',
            self::Completed => 'badge-green',
            self::Cancelled => 'badge-red',
        };
    }

    /**
     * Si un alquiler en este estado ocupa al vehículo para el rango de fechas que pactó.
     *
     * Es lo único que consulta `VehicleAvailabilityService` para decidir si hay solapamiento: uno
     * cancelado o ya cerrado no bloquea nada, aunque sus fechas sigan en la tabla.
     */
    public function bloqueaDisponibilidad(): bool
    {
        return match ($this) {
            self::Pending, self::Confirmed, self::Active => true,
            default => false,
        };
    }

    public function admitePago(): bool
    {
        return match ($this) {
            self::Pending, self::Confirmed, self::Active, self::Returned => true,
            default => false,
        };
    }

    public function admiteCancelacion(): bool
    {
        return match ($this) {
            self::Pending, self::Confirmed => true,
            default => false,
        };
    }
}
