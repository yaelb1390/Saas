<?php

declare(strict_types=1);

namespace App\Modules\ElectronicInvoicing\Providers\Psfe;

/**
 * Lo que un proveedor certificado hace POR la empresa. Cada conector lo declara según la
 * documentación de ese proveedor; BMIA no lo supone.
 *
 * - `signs`: firma el XML él mismo. La empresa no sube su certificado .p12 a BMIA y ese paso
 *   desaparece del asistente.
 * - `findsReceivers`: consulta el directorio de receptores electrónicos de la DGII.
 * - `voidsRanges`: tramita la anulación de rangos (ANECF).
 */
final readonly class PsfeCapabilities
{
    public function __construct(
        public bool $signs = false,
        public bool $findsReceivers = false,
        public bool $voidsRanges = false,
    ) {}
}
