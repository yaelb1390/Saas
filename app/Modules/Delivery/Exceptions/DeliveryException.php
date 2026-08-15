<?php

declare(strict_types=1);

namespace App\Modules\Delivery\Exceptions;

use DomainException;

/**
 * Errores de negocio del reparto. Los controladores los capturan y los convierten en un mensaje
 * `panel_error` para el usuario, sin abortar con un 500.
 */
final class DeliveryException extends DomainException
{
    public static function transicionInvalida(string $desde, string $hasta): self
    {
        return new self("Una entrega «{$desde}» no puede pasar a «{$hasta}».");
    }

    public static function yaCerrada(string $estado): self
    {
        return new self("Esta entrega ya está «{$estado}»: no se puede reasignar.");
    }

    public static function nadaQueCobrar(): self
    {
        return new self('Esta entrega no lleva nada que cobrar: ya venía pagada.');
    }

    public static function yaCobrada(): self
    {
        return new self('Este cobro ya estaba anotado.');
    }

    public static function nadaQueLiquidar(string $repartidor): self
    {
        return new self("{$repartidor} no tiene nada cobrado pendiente de entregar en caja.");
    }

    public static function sinRepartidor(): self
    {
        return new self('Asigna primero un repartidor a la entrega.');
    }
}
