<?php

declare(strict_types=1);

namespace App\Modules\Rental\Services;

use App\Modules\Dealer\Enums\VehicleStatus;
use App\Modules\Dealer\Models\Vehicle;
use App\Modules\Rental\Enums\RentalStatus;
use App\Modules\Rental\Exceptions\RentalException;
use App\Modules\Rental\Models\VehicleRental;
use Illuminate\Support\Carbon;

/**
 * Si un vehículo se puede alquilar en un rango de fechas concreto.
 *
 * ÚNICO punto de verdad, y se llama SIEMPRE en el servidor antes de reservar o confirmar —nunca se
 * confía en lo que ya comprobó el frontend, porque entre que se pintó el calendario y se pulsó el
 * botón, otra persona pudo haber reservado el mismo vehículo—.
 *
 * La disponibilidad NO es una columna de estado: un vehículo puede estar «Disponible» ahora mismo y
 * tener una reserva para la semana que viene. Por eso esto es una consulta de solapamiento de fechas
 * contra `vehicle_rentals`, y solo el estado ACTUAL del vehículo (vendido/retirado/en taller) se mira
 * como bloqueo absoluto, sin importar las fechas.
 */
final class VehicleAvailabilityService
{
    public function isAvailable(
        Vehicle $vehicle,
        Carbon $start,
        Carbon $end,
        ?int $excludeRentalId = null,
    ): bool {
        return $this->motivoNoDisponible($vehicle, $start, $end, $excludeRentalId) === null;
    }

    public function assertAvailable(
        Vehicle $vehicle,
        Carbon $start,
        Carbon $end,
        ?int $excludeRentalId = null,
    ): void {
        $motivo = $this->motivoNoDisponible($vehicle, $start, $end, $excludeRentalId);

        if ($motivo !== null) {
            throw RentalException::noDisponible($vehicle->nombre(), $motivo);
        }
    }

    /** El motivo por el que NO se puede alquilar, o null si sí se puede. */
    private function motivoNoDisponible(
        Vehicle $vehicle,
        Carbon $start,
        Carbon $end,
        ?int $excludeRentalId,
    ): ?string {
        if ($end->lessThanOrEqualTo($start)) {
            throw RentalException::fechasInvalidas();
        }

        if (! $vehicle->seAlquila()) {
            return 'no está marcado para alquiler';
        }

        if ($vehicle->status === VehicleStatus::Sold) {
            return 'está vendido';
        }

        if ($vehicle->status === VehicleStatus::Withdrawn) {
            return 'está retirado del patio';
        }

        if ($vehicle->status === VehicleStatus::Maintenance) {
            return 'está en mantenimiento';
        }

        $solapa = VehicleRental::query()
            ->where('vehicle_id', $vehicle->id)
            ->when($excludeRentalId !== null, fn ($q) => $q->whereKeyNot($excludeRentalId))
            ->whereIn('status', array_map(
                fn (RentalStatus $s) => $s->value,
                array_values(array_filter(RentalStatus::cases(), fn (RentalStatus $s) => $s->bloqueaDisponibilidad())),
            ))
            // Solapamiento clásico de rangos: empieza antes de que el otro termine Y termina
            // después de que el otro empiece.
            ->where('start_at', '<', $end)
            ->where('end_at', '>', $start)
            ->exists();

        return $solapa ? 'ya tiene otra reserva o alquiler en esas fechas' : null;
    }
}
