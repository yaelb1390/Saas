<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Exceptions;

use DomainException;

/**
 * Lo que impide dar de alta unidades por escaneo. Mensajes para el cajero, no trazas.
 */
final class SerialScanException extends DomainException
{
    public static function productoNoSerializado(string $nombre): self
    {
        return new self("«{$nombre}» no se lleva por número de serie. Enciéndele «con número de serie» en el producto, o dale entrada por cantidad.");
    }

    public static function nadaQueDarDeAlta(): self
    {
        return new self('No escaneaste ninguna serie.');
    }

    public static function serieRequerida(string $nombre): self
    {
        return new self("«{$nombre}» se vende por número de serie: elige qué unidad sale antes de cobrar.");
    }

    public static function serieNoDisponible(string $serial): self
    {
        return new self("La unidad con serie «{$serial}» no está disponible: puede que ya se vendiera o esté en otro almacén.");
    }
}
