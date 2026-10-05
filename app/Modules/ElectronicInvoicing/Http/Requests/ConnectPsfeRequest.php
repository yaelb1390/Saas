<?php

declare(strict_types=1);

namespace App\Modules\ElectronicInvoicing\Http\Requests;

use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\ElectronicInvoicing\Models\ElectronicInvoicingSettings;
use App\Modules\ElectronicInvoicing\Providers\Psfe\PsfeCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * «Conectar y probar» un proveedor certificado. El proveedor tiene que estar en el catálogo y
 * disponible en el ambiente actual; los datos que pide salen de su conector (`fields()`), así que un
 * proveedor nuevo no necesita tocar esta validación. Que no falte ninguno obligatorio lo decide
 * `PsfeConnectionService`, porque un secreto vacío puede significar «conserva el guardado».
 */
final class ConnectPsfeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $empresa = app(CurrentCompany::class)->model();
        $catalogo = app(PsfeCatalog::class);
        $disponibles = $empresa === null
            ? []
            : array_keys($catalogo->availableIn(ElectronicInvoicingSettings::paraEmpresa($empresa)->environment));

        $reglas = [
            'psfe' => ['required', 'string', Rule::in($disponibles)],
            'credentials' => ['nullable', 'array'],
        ];

        foreach ($catalogo->find((string) $this->input('psfe'))?->fields() ?? [] as $campo) {
            $reglas['credentials.'.$campo->name] = ['nullable', 'string', 'max:'.$campo->maxLength];
        }

        return $reglas;
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['psfe.in' => 'Ese proveedor no está disponible en el ambiente actual.'];
    }
}
