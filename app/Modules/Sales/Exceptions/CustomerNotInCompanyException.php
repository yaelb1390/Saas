<?php

declare(strict_types=1);

namespace App\Modules\Sales\Exceptions;

use DomainException;

final class CustomerNotInCompanyException extends DomainException
{
    public static function for(int $customerId, int $companyId): self
    {
        return new self("El cliente {$customerId} no pertenece a la empresa {$companyId}.");
    }
}
