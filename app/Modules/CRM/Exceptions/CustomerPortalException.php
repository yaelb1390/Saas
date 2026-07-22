<?php

declare(strict_types=1);

namespace App\Modules\CRM\Exceptions;

use DomainException;

final class CustomerPortalException extends DomainException
{
    public static function withoutPhone(int $customerId): self
    {
        return new self("El cliente {$customerId} no tiene un teléfono al que enviar el enlace.");
    }
}
