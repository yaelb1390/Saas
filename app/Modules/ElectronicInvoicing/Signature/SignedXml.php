<?php

declare(strict_types=1);

namespace App\Modules\ElectronicInvoicing\Signature;

use Carbon\CarbonInterface;

/**
 * Un e-CF firmado. `xml` son los BYTES exactos a guardar y enviar: cualquier cambio posterior (otro
 * formato, otra codificación, un salto de línea) invalida la firma.
 */
final readonly class SignedXml
{
    public function __construct(
        public string $xml,
        public string $signatureValue,
        public string $securityCode,
        public CarbonInterface $signedAt,
        public string $certificateFingerprint,
    ) {}
}
