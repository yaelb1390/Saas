<?php

declare(strict_types=1);

namespace App\Modules\ElectronicInvoicing\Signature;

use App\Modules\ElectronicInvoicing\Models\ElectronicCertificate;
use SensitiveParameter;

/**
 * Certificado abierto EN MEMORIA para firmar. No se serializa ni se guarda: vive lo que dura la
 * petición. `__debugInfo` evita que un dump o un log enseñe la clave privada.
 */
final class LoadedCertificate
{
    public function __construct(
        #[SensitiveParameter] public readonly string $privateKeyPem,
        public readonly string $certificatePem,
        public readonly ElectronicCertificate $record,
    ) {}

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['certificate' => $this->record->subject, 'privateKey' => '[oculta]'];
    }

    public function __serialize(): array
    {
        throw new \LogicException('Un certificado abierto no se serializa.');
    }
}
