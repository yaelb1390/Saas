<?php

declare(strict_types=1);

namespace App\Modules\Core\Http\Requests;

use App\Modules\Core\Support\ModuleRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

final class StoreCompanyRequest extends FormRequest
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
            'tax_id' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:255'],

            // Usuario propietario que administrará la empresa.
            'owner_name' => ['required', 'string', 'max:255'],
            'owner_email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'owner_password' => ['required', 'confirmed', Password::defaults()],

            // Plan: si no se envía ninguno, se asume plan completo.
            'modules' => ['sometimes', 'array'],
            'modules.*' => ['string', Rule::in(ModuleRegistry::keys())],
        ];
    }

    public function attributes(): array
    {
        return [
            'name' => 'nombre de la empresa',
            'owner_name' => 'nombre del propietario',
            'owner_email' => 'correo del propietario',
            'owner_password' => 'contraseña',
        ];
    }
}
