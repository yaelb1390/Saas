<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Exceptions;

use DomainException;

/**
 * Lo que impide operar sobre una unidad ya dada de alta (borrarla, sobre todo). Mensajes para el
 * cajero, no trazas.
 */
final class ProductUnitException extends DomainException
{
    /**
     * Solo se borra lo que está disponible. Una unidad vendida —o reservada, o devuelta— NO se borra:
     * su historia es exactamente lo que hará falta el día que el cliente vuelva con la garantía. Y
     * borrarla bajaría un stock que ya bajó al venderla, descuadrando el contador.
     */
    public static function soloSeBorranDisponibles(string $serial): self
    {
        return new self("La unidad con serie «{$serial}» no está disponible: ya se vendió o está apartada, y su historia no se borra.");
    }
}
