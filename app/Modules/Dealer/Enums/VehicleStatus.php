<?php

declare(strict_types=1);

namespace App\Modules\Dealer\Enums;

/**
 * En qué situación está una unidad del patio.
 *
 * «Apartado» existe aparte de «vendido» porque en un dealer es lo normal: el cliente deja un inicial
 * y se lleva el carro semanas después. Sin ese estado intermedio habría que darlo por vendido —y
 * mentir en el inventario— o dejarlo disponible —y venderlo dos veces—.
 */
enum VehicleStatus: string
{
    case Available = 'available';
    case Reserved = 'reserved';
    case Sold = 'sold';
    case Withdrawn = 'withdrawn';

    /*
     * Los dos casos siguientes los añadió el módulo de Alquiler, que vive aparte (App\Modules\Rental)
     * y depende de este enum sin poder tocarlo. `Maintenance` bloquea CUALQUIER trato o alquiler
     * mientras la unidad está en el taller; `Rented` es el estado mientras el alquiler está
     * físicamente en curso —desde la entrega hasta la devolución—. Las reservas FUTURAS de alquiler no
     * cambian este estado: un vehículo puede estar «Disponible» hoy y tener una reserva para la semana
     * que viene, y eso se resuelve con un rango de fechas, no con una columna de estado (ver
     * VehicleAvailabilityService en el módulo de Alquiler).
     */
    case Maintenance = 'maintenance';
    case Rented = 'rented';

    public function label(): string
    {
        return match ($this) {
            self::Available => 'Disponible',
            self::Reserved => 'Apartado',
            self::Sold => 'Vendido',
            self::Withdrawn => 'Retirado',
            self::Maintenance => 'En mantenimiento',
            self::Rented => 'Alquilado',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Available => 'badge-green',
            self::Reserved => 'badge-amber',
            self::Sold => 'badge-blue',
            self::Withdrawn => 'badge-gray',
            self::Maintenance => 'badge-red',
            self::Rented => 'badge-violet',
        };
    }

    /**
     * Si se puede abrir un trato sobre esta unidad.
     *
     * Es la regla que impide venderla dos veces, y vive en el enum —no en el servicio— para que
     * cualquiera que pregunte obtenga la misma respuesta.
     */
    public function admiteTrato(): bool
    {
        return $this === self::Available;
    }

    /**
     * Si se puede RESERVAR para alquiler. No mira fechas —eso lo hace
     * `VehicleAvailabilityService` con el solapamiento contra otros alquileres— solo si el estado
     * ACTUAL de la unidad lo permite en absoluto: vendida, retirada o en el taller no se alquila
     * nunca, esté libre la fecha que esté.
     */
    public function admiteAlquiler(): bool
    {
        return match ($this) {
            self::Sold, self::Withdrawn, self::Maintenance => false,
            default => true,
        };
    }
}
