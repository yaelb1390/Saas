<?php

declare(strict_types=1);

namespace App\Modules\Dealer\Enums;

/**
 * El trato: apartado, cerrado o caído.
 *
 * Apartado y cerrado son la misma fila en dos momentos, no dos cosas distintas. Separarlos en dos
 * tablas obligaría a copiar cliente, precio pactado e inicial de una a otra, que es donde se pierden
 * los datos.
 */
enum DealStatus: string
{
    case Reserved = 'reserved';
    case Closed = 'closed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Reserved => 'Apartado',
            self::Closed => 'Cerrado',
            self::Cancelled => 'Caído',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Reserved => 'badge-amber',
            self::Closed => 'badge-green',
            self::Cancelled => 'badge-gray',
        };
    }

    /** Un trato caído o ya cerrado no se vuelve a tocar. */
    public function admiteCobro(): bool
    {
        return $this !== self::Cancelled;
    }
}
