<?php

declare(strict_types=1);

namespace App\Modules\ElectronicInvoicing\Providers\Psfe;

/**
 * Lo que un proveedor certificado hace POR la empresa. Cada conector lo declara según la
 * documentación de ese proveedor; BMIA no lo supone.
 *
 * - `signs`: la firma la pone el proveedor. La empresa no sube su certificado .p12 a BMIA y ese paso
 *   desaparece del asistente.
 * - `submitsUnsigned`: firma Y envía en UNA llamada, a partir del documento SIN firmar (Digifact y la
 *   mayoría de proveedores con API propia). BMIA no firma: guarda el XML que el proveedor devuelve.
 *   Implica `signs`.
 * - `findsReceivers`: consulta el directorio de receptores electrónicos de la DGII.
 * - `voidsRanges`: tramita la anulación de rangos (ANECF).
 */
final readonly class PsfeCapabilities
{
    public function __construct(
        public bool $signs = false,
        public bool $findsReceivers = false,
        public bool $voidsRanges = false,
        public bool $submitsUnsigned = false,
    ) {}
}
