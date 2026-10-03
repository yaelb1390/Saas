<?php

declare(strict_types=1);

namespace App\Modules\ElectronicInvoicing\Domain;

use Carbon\CarbonInterface;

/**
 * El comprobante que una nota de débito/crédito modifica, o el NCF en papel que un e-CF reemplaza
 * tras una contingencia [FMT sección F «Información de referencia»].
 *
 * `modifiedTotal` y `alreadyCredited` permiten comprobar que las notas de crédito no superan el
 * total del comprobante afectado [FMT campo 110 d)]; si no se informan, esa regla no se comprueba.
 */
final readonly class EcfReference
{
    public function __construct(
        public string $modifiedNcf,
        public CarbonInterface $modifiedDate,
        public int $code,
        public ?string $reason = null,
        public ?string $modifiedTotal = null,
        public string $alreadyCredited = '0',
    ) {}
}
