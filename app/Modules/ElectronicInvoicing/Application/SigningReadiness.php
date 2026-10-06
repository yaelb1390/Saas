<?php

declare(strict_types=1);

namespace App\Modules\ElectronicInvoicing\Application;

use App\Modules\Core\Models\Company;
use App\Modules\ElectronicInvoicing\Models\ElectronicInvoicingSettings;
use App\Modules\ElectronicInvoicing\Providers\Contracts\SignsDocuments;
use App\Modules\ElectronicInvoicing\Providers\ProviderResolver;
use App\Modules\ElectronicInvoicing\Providers\PsfeProvider;
use App\Modules\ElectronicInvoicing\Signature\CertificateVault;

/**
 * ¿Quién firma los e-CF de esta empresa? Una sola respuesta para todo el módulo (emisión, asistente,
 * diagnóstico, encender la emisión):
 *
 * - el proveedor principal, si es un PSFE que firma y envía en una llamada (`submitsUnsigned`):
 *   BMIA no firma nada, guarda lo que el proveedor devuelve;
 * - el proveedor principal, si es un PSFE que firma aparte: BMIA le pide la firma;
 * - si no, el certificado digital de la empresa, como siempre.
 */
final class SigningReadiness
{
    public function __construct(
        private readonly CertificateVault $certificates,
        private readonly ProviderResolver $providers,
    ) {}

    /** El proveedor que firma aparte por la empresa, o null si firma BMIA (o firma el que envía). */
    public function remoteSigner(Company $company): ?SignsDocuments
    {
        $proveedor = $this->providers->for(ElectronicInvoicingSettings::paraEmpresa($company));

        return $proveedor instanceof SignsDocuments && $proveedor->signsFor($company) ? $proveedor : null;
    }

    /** El proveedor principal firma y envía él mismo a partir del documento sin firmar. */
    public function providerSignsOnSubmit(Company $company): bool
    {
        $proveedor = $this->providers->for(ElectronicInvoicingSettings::paraEmpresa($company));

        return $proveedor instanceof PsfeProvider && $proveedor->submitsUnsignedFor($company);
    }

    /** Si hay con qué firmar: un proveedor que firma o un certificado activo. */
    public function canSign(Company $company): bool
    {
        return $this->providerSignsOnSubmit($company)
            || $this->remoteSigner($company) !== null
            || $this->certificates->active($company) !== null;
    }
}
