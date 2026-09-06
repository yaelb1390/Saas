<?php

declare(strict_types=1);

namespace App\Modules\Sales\Exceptions;

use App\Modules\Sales\Enums\PaymentMethod;
use DomainException;

/**
 * El reparto de un cobro entre varias formas de pago no cuadra.
 *
 * Todas estas situaciones se rechazan en vez de arreglarse por nuestra cuenta: ajustar un cobro que
 * no cuadra significa cobrar algo distinto de lo que el cajero acordó con el cliente, y eso no se
 * hace en silencio.
 */
final class PaymentSplitException extends DomainException
{
    public static function noCuadra(string $total, string $suma): self
    {
        return new self("Las formas de pago suman {$suma} y la venta es de {$total}.");
    }

    /**
     * Un datáfono no da vuelto.
     *
     * Si la tarjeta cobró más que lo pendiente, la venta no puede taparlo repartiendo el sobrante:
     * ese dinero de más está en la cuenta del negocio y hay que devolverlo por donde entró.
     */
    public static function sinVueltoEn(PaymentMethod $metodo): self
    {
        return new self("Con {$metodo->label()} no se puede cobrar de más: no hay vuelto por esa vía.");
    }

    public static function importeVacio(PaymentMethod $metodo): self
    {
        return new self("El importe de {$metodo->label()} tiene que ser mayor que cero.");
    }

    public static function demasiadasFormas(int $tope): self
    {
        return new self("Un cobro no puede repartirse en más de {$tope} formas de pago.");
    }
}
