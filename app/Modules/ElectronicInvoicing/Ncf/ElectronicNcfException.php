<?php

declare(strict_types=1);

namespace App\Modules\ElectronicInvoicing\Ncf;

use App\Modules\ElectronicInvoicing\Domain\EcfType;
use App\Modules\ElectronicInvoicing\Domain\Environment;
use RuntimeException;

/** Mensajes para el usuario: dicen qué pasó y qué hacer, sin jerga. */
final class ElectronicNcfException extends RuntimeException
{
    public static function noActiveSequence(EcfType $type, Environment $env): self
    {
        return new self("No hay una secuencia de e-NCF {$type->prefix()} activa para el ambiente «{$env->label()}». Regístrala en Facturación Electrónica → Secuencias.");
    }

    public static function expired(EcfType $type): self
    {
        return new self("La secuencia de e-NCF {$type->prefix()} está vencida. Solicita una nueva en la Oficina Virtual de la DGII y regístrala.");
    }

    public static function exhausted(EcfType $type): self
    {
        return new self("Se agotaron los e-NCF {$type->prefix()} autorizados. Solicita un nuevo rango en la Oficina Virtual de la DGII.");
    }

    public static function malformed(string $encf): self
    {
        return new self("«{$encf}» no es un e-NCF válido.");
    }

    public static function notFromCompany(string $encf): self
    {
        return new self("El e-NCF {$encf} no pertenece a ninguna secuencia de esta empresa en este ambiente.");
    }
}
