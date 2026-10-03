<?php

declare(strict_types=1);

namespace App\Modules\ElectronicInvoicing\Storage;

use App\Modules\ElectronicInvoicing\Models\ElectronicInvoice;
use App\Modules\ElectronicInvoicing\Models\ElectronicInvoiceFile;
use App\Modules\ElectronicInvoicing\Signature\CertificateVault;
use RuntimeException;

/**
 * Guarda los archivos de cada e-CF en el disco privado `fiscal_documents`, separados por empresa,
 * ambiente y mes:
 *
 *     ecf/{empresa}/{ambiente}/{aaaa}/{mm}/{e-NCF}/{tipo}-{n}.xml
 *
 * Se guardan los BYTES tal cual (el XML firmado no puede re-serializarse: cambiaría la firma) y su
 * sha256. Nunca se sobrescribe: un segundo archivo del mismo tipo (p. ej. otra respuesta) lleva otro
 * número.
 */
final class FiscalDocumentStore
{
    public function put(ElectronicInvoice $ecf, string $kind, string $bytes, string $extension = 'xml'): ElectronicInvoiceFile
    {
        $n = $ecf->files()->where('kind', $kind)->count() + 1;

        $ruta = sprintf(
            'ecf/%d/%s/%s/%s/%s/%s-%d.%s',
            $ecf->company_id,
            $ecf->environment->value,
            $ecf->issue_date->format('Y'),
            $ecf->issue_date->format('m'),
            $ecf->e_ncf,
            $kind,
            $n,
            $extension,
        );

        // throw => true: un archivo fiscal que no se pudo guardar no puede pasar por guardado.
        CertificateVault::disk()->put($ruta, $bytes, ['throw' => true]);

        return $ecf->files()->create([
            'company_id' => $ecf->company_id,
            'kind' => $kind,
            'path' => $ruta,
            'sha256' => hash('sha256', $bytes),
            'bytes' => strlen($bytes),
        ]);
    }

    /** Lee un archivo y comprueba que sigue siendo el que se guardó. */
    public function get(ElectronicInvoiceFile $file): string
    {
        $bytes = (string) CertificateVault::disk()->get($file->path);

        if (! hash_equals($file->sha256, hash('sha256', $bytes))) {
            throw new RuntimeException("El archivo {$file->kind} del e-CF no coincide con su huella: fue modificado.");
        }

        return $bytes;
    }
}
