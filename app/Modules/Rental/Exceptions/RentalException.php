<?php

declare(strict_types=1);

namespace App\Modules\Rental\Exceptions;

use RuntimeException;

/**
 * Un motivo de negocio por el que la operación de alquiler no se puede hacer.
 *
 * Mismo criterio que `DealerException`: mensajes en español, escritos para quien atiende, y que
 * llegan tal cual a la pantalla.
 */
final class RentalException extends RuntimeException
{
    public static function noDisponible(string $vehiculo, string $motivo): self
    {
        return new self("«{$vehiculo}» no se puede alquilar en esas fechas: {$motivo}.");
    }

    public static function alquilerCancelado(): self
    {
        return new self('Ese alquiler está cancelado.');
    }

    public static function alquilerCerrado(): self
    {
        return new self('Ese alquiler ya está cerrado.');
    }

    public static function noAdmiteCancelacion(): self
    {
        return new self('Ese alquiler ya está en curso o cerrado: no se puede cancelar, hay que devolverlo.');
    }

    public static function noAdmiteEntrega(): self
    {
        return new self('Ese alquiler no está en un punto en el que se pueda entregar el vehículo.');
    }

    public static function noAdmiteDevolucion(): self
    {
        return new self('Ese alquiler no está activo: no hay nada que devolver.');
    }

    public static function fechasInvalidas(): self
    {
        return new self('La fecha de devolución tiene que ser posterior a la de recogida.');
    }

    public static function abonoInvalido(): self
    {
        return new self('El abono tiene que ser mayor que cero.');
    }

    public static function abonoMayorQueElSaldo(string $saldo): self
    {
        return new self("El abono no puede pasar del saldo, que es {$saldo}.");
    }

    public static function clienteDeOtraEmpresa(): self
    {
        return new self('Ese cliente no es de esta empresa.');
    }
}
