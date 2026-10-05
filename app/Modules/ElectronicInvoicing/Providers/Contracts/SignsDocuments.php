<?php

declare(strict_types=1);

namespace App\Modules\ElectronicInvoicing\Providers\Contracts;

use App\Modules\Core\Models\Company;
use App\Modules\ElectronicInvoicing\Domain\Environment;
use App\Modules\ElectronicInvoicing\Signature\SignedXml;
use DOMDocument;

/**
 * Un proveedor que puede firmar POR la empresa (un PSFE que firma). Si `signsFor()` es true, BMIA no
 * necesita el certificado de la empresa para emitir: le pide la firma al proveedor y guarda el
 * resultado igual que si hubiera firmado aquí (bytes exactos, código de seguridad, hora).
 */
interface SignsDocuments
{
    public function signsFor(Company $company): bool;

    public function signDocument(Company $company, Environment $env, DOMDocument $unsigned, bool $writeSignatureDate = true): SignedXml;
}
