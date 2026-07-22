<?php

declare(strict_types=1);

namespace App\Modules\Billing\Exceptions;

use App\Modules\Billing\Enums\NcfType;
use DomainException;

final class FiscalSequenceException extends DomainException
{
    public static function noActiveSequence(NcfType $type): self
    {
        return new self("No hay una secuencia fiscal activa para el tipo {$type->value}.");
    }

    public static function exhausted(NcfType $type): self
    {
        return new self("La secuencia fiscal del tipo {$type->value} está agotada.");
    }

    public static function expired(NcfType $type): self
    {
        return new self("La secuencia fiscal del tipo {$type->value} está vencida.");
    }
}
