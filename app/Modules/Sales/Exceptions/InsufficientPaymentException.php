<?php

declare(strict_types=1);

namespace App\Modules\Sales\Exceptions;

use DomainException;

final class InsufficientPaymentException extends DomainException
{
    public static function for(string $total, string $paid): self
    {
        return new self("El pago ({$paid}) es menor que el total de la venta ({$total}).");
    }
}
