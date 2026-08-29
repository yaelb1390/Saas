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

    public function label(): string
    {
        return match ($this) {
            self::Available => 'Disponible',
            self::Reserved => 'Apartado',
            self::Sold => 'Vendido',
            self::Withdrawn => 'Retirado',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Available => 'badge-green',
            self::Reserved => 'badge-amber',
            self::Sold => 'badge-blue',
            self::Withdrawn => 'badge-gray',
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
}
