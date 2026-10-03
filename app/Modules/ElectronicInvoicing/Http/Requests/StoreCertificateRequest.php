<?php

declare(strict_types=1);

namespace App\Modules\ElectronicInvoicing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Subida del certificado digital (.p12 / .pfx) con su contraseña.
 *
 * Solo se comprueba la forma aquí; que el archivo se abra con esa contraseña, que la clave
 * corresponda y que esté vigente lo comprueba `CertificateVault` antes de guardar nada.
 */
final class StoreCertificateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // Un .p12 de firma pesa unos pocos KB; 200 KB deja margen sin aceptar cualquier cosa.
            'certificate' => ['required', 'file', 'max:200', 'extensions:p12,pfx'],
            'password' => ['required', 'string', 'max:200'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['certificate' => 'certificado', 'password' => 'contraseña'];
    }
}
