<?php

declare(strict_types=1);

namespace App\Modules\Billing\Support;

/**
 * Tipo de identificación según la DGII. El valor es el código que espera el formato 607.
 */
enum TaxIdKind: string
{
    case Rnc = '1';
    case Cedula = '2';
    case Pasaporte = '3';

    public function label(): string
    {
        return match ($this) {
            self::Rnc => 'RNC',
            self::Cedula => 'Cédula',
            self::Pasaporte => 'Pasaporte',
        };
    }
}
