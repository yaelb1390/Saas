<?php

declare(strict_types=1);

namespace App\Modules\ElectronicInvoicing\Http\Requests;

use App\Modules\Billing\Support\TaxId;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\ElectronicInvoicing\Domain\Environment;
use App\Modules\ElectronicInvoicing\Models\ElectronicInvoicingSettings;
use App\Modules\ElectronicInvoicing\Xml\TerritoryCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Datos fiscales del emisor, ambiente y proveedor. Los largos salen del XSD oficial
 * (RazonSocialEmisor/NombreComercial 150, DireccionEmisor 100); provincia y municipio, de su catálogo.
 *
 * Pasar a PRODUCCIÓN exige confirmar que la DGII autorizó a la empresa: BMIA no puede comprobarlo y
 * completar la configuración nunca equivale a estar autorizado.
 */
final class UpdateElectronicInvoicingSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'tax_id' => ['required', 'string', 'max:20'],
            'legal_name' => ['required', 'string', 'max:150'],
            'trade_name' => ['nullable', 'string', 'max:150'],
            'address' => ['required', 'string', 'max:100'],
            'province' => ['nullable', 'string', 'size:6'],
            'municipality' => ['nullable', 'string', 'size:6'],
            'phone' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:80'],
            'ecf_admin_user' => ['nullable', 'string', 'max:100'],
            'environment' => ['required', Rule::enum(Environment::class)],
            'provider' => ['required', Rule::in(['fake', 'dgii', 'psfe'])],
            'confirm_authorized' => ['nullable', 'boolean'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $v): void {
            $taxId = TaxId::tryParse((string) $this->input('tax_id'));

            if ($taxId === null) {
                $v->errors()->add('tax_id', 'El RNC o cédula no es válido: el dígito verificador no coincide.');
            }

            $catalogo = app(TerritoryCatalog::class);
            $provincia = $this->input('province') ?: null;
            $municipio = $this->input('municipality') ?: null;

            if ($provincia !== null && ! $catalogo->isProvince($provincia)) {
                $v->errors()->add('province', 'Elige una provincia de la lista de la DGII.');
            }

            if ($municipio !== null && (! $catalogo->isMunicipality($municipio) || ($provincia !== null && substr($municipio, 0, 2) !== substr($provincia, 0, 2)))) {
                $v->errors()->add('municipality', 'El municipio no pertenece a la provincia elegida.');
            }

            $ambiente = Environment::tryFrom((string) $this->input('environment'));

            if ($ambiente?->isFiscal() && $this->input('provider') === 'fake') {
                $v->errors()->add('provider', 'En producción hace falta un proveedor real: el de prueba no envía nada a la DGII.');
            }

            $empresa = app(CurrentCompany::class)->model();
            $actual = $empresa !== null ? ElectronicInvoicingSettings::paraEmpresa($empresa)->environment : null;

            if ($ambiente?->isFiscal() && $actual !== $ambiente && ! $this->boolean('confirm_authorized')) {
                $v->errors()->add('confirm_authorized', 'Para pasar a producción confirma que la DGII ya autorizó a tu empresa a emitir e-CF.');
            }
        }];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'tax_id' => 'RNC o cédula',
            'legal_name' => 'razón social',
            'trade_name' => 'nombre comercial',
            'address' => 'dirección',
            'email' => 'correo',
            'environment' => 'ambiente',
            'provider' => 'proveedor',
        ];
    }
}
