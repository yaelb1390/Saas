<?php

declare(strict_types=1);

namespace App\Modules\Core\Http\Requests;

use App\Modules\Core\Support\CompanyLogoStore;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Datos de la empresa que salen en sus recibos y facturas.
 *
 * Solo el nombre es obligatorio: una empresa recién dada de alta tiene que poder empezar a vender sin
 * haber rellenado su RNC ni su dirección. Lo que esté vacío simplemente no se imprime.
 */
final class UpdateCompanyProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'legal_name' => ['nullable', 'string', 'max:255'],
            'tax_id' => ['nullable', 'string', 'max:20'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            // Funciones que la empresa enciende por su cuenta. Llegan como casillas, así que lo que
            // no venga es «apagado».
            'features' => ['nullable', 'array'],
            'features.*' => ['nullable', 'boolean'],
            'logo' => [
                'nullable', 'image', 'mimes:png,jpg,jpeg',
                // El navegador ya lo recorta antes de subirlo; este tope es la red de seguridad para
                // quien mande el archivo por otra vía. En kilobytes, que es lo que espera Laravel.
                'max:'.(int) (CompanyLogoStore::MAX_BYTES / 1024),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'nombre comercial',
            'legal_name' => 'razón social',
            'tax_id' => 'RNC o cédula',
            'phone' => 'teléfono',
            'address' => 'dirección',
            'logo' => 'logo',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'logo.max' => 'El logo pesa demasiado. Elige una imagen más pequeña: para un recibo basta con un logo de unos 600 píxeles de ancho.',
            'logo.mimes' => 'El logo tiene que ser una imagen PNG o JPG.',
        ];
    }
}
