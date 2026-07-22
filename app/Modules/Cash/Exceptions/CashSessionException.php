<?php

declare(strict_types=1);

namespace App\Modules\Cash\Exceptions;

use DomainException;

final class CashSessionException extends DomainException
{
    public static function alreadyOpen(int $cashRegisterId): self
    {
        return new self("La caja {$cashRegisterId} ya tiene una sesión abierta.");
    }

    public static function notOpen(): self
    {
        return new self('La sesión de caja no está abierta.');
    }
}
