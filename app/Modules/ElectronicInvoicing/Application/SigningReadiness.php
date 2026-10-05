<?php

declare(strict_types=1);

namespace App\Modules\ElectronicInvoicing\Application;

use App\Modules\Core\Models\Company;
use App\Modules\ElectronicInvoicing\Models\ElectronicInvoicingSettings;
use App\Modules\ElectronicInvoicing\Providers\Contracts\SignsDocuments;
use App\Modules\ElectronicInvoicing\Providers\ProviderResolver;
use App\Modules\ElectronicInvoicing\Signature\CertificateVault;

/**
 * ¿Quién firma los e-CF de esta empresa? Una sola respuesta para todo el módulo (emisión, asistente,
 * diagnóstico, encender la emisión):
 *
 * - el proveedor conectado, si es un PSFE que firma: la empresa no necesita subir su certificado;
 * - si no, el certificado digital de la empresa, como siempre.
 */
final class SigningReadiness
{
    public function __construct(
        private readonly CertificateVault $certificates,
        private readonly ProviderResolver $providers,
    ) {}

    /** El proveedor que firma por la empresa, o null si firma BMIA con su certificado. */
    public function remoteSigner(Company $company): ?SignsDocuments
    {
        $proveedor = $this->providers->for(ElectronicInvoicingSettings::paraEmpresa($company));

        return $proveedor instanceof SignsDocuments && $proveedor->signsFor($company) ? $proveedor : null;
    }

    /** Si hay con qué firmar: el proveedor o un certificado activo. */
    public function canSign(Company $company): bool
    {
        return $this->remoteSigner($company) !== null || $this->certificates->active($company) !== null;
    }
}
