<?php

declare(strict_types=1);

namespace App\Modules\Dealer\Exceptions;

use RuntimeException;

/**
 * Un motivo de negocio por el que la operación no se puede hacer.
 *
 * Los mensajes están en español y escritos para quien atiende, no para quien programa: llegan tal
 * cual a la pantalla. «Ese vehículo ya está apartado» le dice al vendedor qué pasó; un código de
 * error, no.
 */
final class DealerException extends RuntimeException
{
    public static function noDisponible(string $vehiculo, string $estado): self
    {
        return new self("«{$vehiculo}» no se puede vender ahora mismo: está {$estado}.");
    }

    public static function tratoCerrado(): self
    {
        return new self('Ese trato ya está cerrado.');
    }

    public static function tratoCaido(): self
    {
        return new self('Ese trato está caído: no admite cobros.');
    }

    public static function abonoMayorQueElSaldo(string $saldo): self
    {
        return new self("El abono no puede pasar del saldo, que es {$saldo}.");
    }

    public static function abonoInvalido(): self
    {
        return new self('El abono tiene que ser mayor que cero.');
    }

    public static function clienteDeOtraEmpresa(): self
    {
        return new self('Ese cliente no es de esta empresa.');
    }

    public static function sinFinanciamiento(): self
    {
        return new self('Ese trato es de contado: no tiene cuotas que cobrar.');
    }
}
