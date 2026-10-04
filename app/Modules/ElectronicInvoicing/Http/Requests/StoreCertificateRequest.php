<?php

declare(strict_types=1);

namespace App\Modules\ElectronicInvoicing\Http\Requests;

use App\Modules\Core\Models\SystemEvent;
use Illuminate\Foundation\Http\FormRequest;
use Symfony\Component\HttpFoundation\File\UploadedFile as SymfonyUploadedFile;

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

    /**
     * «El campo certificado es obligatorio» sale también cuando el archivo SÍ se envió pero PHP no lo
     * aceptó (llegó a medias, pasó un límite, no pudo escribirse en el temporal): Laravel lo trata
     * como ausente. Se dice cuál de los dos pasó y se deja constancia, que desde la pantalla no se ve.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        $archivo = $this->files->get('certificate');

        if (! $archivo instanceof SymfonyUploadedFile) {
            return ['certificate.required' => 'No llegó ningún archivo. Elige el .p12 o .pfx con «Seleccionar archivo» y vuelve a guardar.'];
        }

        if ($archivo->isValid()) {
            return [];
        }

        SystemEvent::registrar(
            type: 'ecf.certificate_upload_failed',
            message: 'El certificado e-CF no llegó completo al servidor',
            contexto: [
                'codigo' => $archivo->getError(),
                'motivo' => $archivo->getErrorMessage(),
                'content_length' => $this->header('Content-Length'),
                'content_type' => mb_substr((string) $this->header('Content-Type'), 0, 60),
            ],
            level: SystemEvent::AVISO,
        );

        // Según cómo lo dejó PHP lo para `required` (sin ruta temporal) o `uploaded` (con ella).
        $mensaje = 'El archivo se envió pero no llegó completo al servidor (código '.$archivo->getError().'). Vuelve a intentarlo; si se repite, avisa a soporte.';

        return ['certificate.required' => $mensaje, 'certificate.uploaded' => $mensaje];
    }
}
