<?php

declare(strict_types=1);

namespace App\Modules\ElectronicInvoicing\Providers\Contracts;

use App\Modules\Core\Models\Company;
use App\Modules\ElectronicInvoicing\Domain\Environment;
use App\Modules\ElectronicInvoicing\Providers\ProviderResult;

/**
 * Un proveedor que recibe el documento entero —firmado, sin firmar o los dos— y decide él por dónde
 * sale: el de los proveedores certificados con respaldo (`PsfeProvider`). Unos conectores necesitan
 * el XML firmado por BMIA y otros firman ellos a partir del original; con varios conectados en orden,
 * solo él sabe cuál le toca a cada uno.
 */
interface SubmitsDocuments
{
    /**
     * @param  string|null  $signedXml  el firmado que toca enviar (el RFCE si `$summary`), o null si
     *                                  el documento no se firmó en BMIA
     * @param  string  $unsignedXml  el XML original, sin firmar
     * @param  string|null  $lastVia  el conector del intento anterior de ESTE documento, si lo hubo:
     *                                si aquel pudo recibirlo, hay que preguntarle antes de usar otro
     */
    public function submit(
        Company $company,
        Environment $env,
        ?string $signedXml,
        string $unsignedXml,
        string $fileName,
        bool $summary,
        string $encf,
        ?string $lastVia = null,
    ): ProviderResult;
}
