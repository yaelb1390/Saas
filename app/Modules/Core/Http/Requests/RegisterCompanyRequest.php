<?php

declare(strict_types=1);

namespace App\Modules\Core\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Alta self-service de una empresa en prueba. Es público (guest): cualquiera puede registrarse.
 * El correo del propietario es la credencial global, por eso debe ser único en `users`.
 */
final class RegisterCompanyRequest extends FormRequest
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
            'company_name' => ['required', 'string', 'max:120'],
            'owner_name' => ['required', 'string', 'max:120'],
            'owner_email' => ['required', 'string', 'email', 'max:190', Rule::unique('users', 'email')],
            'password' => ['required', 'string', Password::default(), 'confirmed'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'owner_email.unique' => 'Ya existe una cuenta con ese correo. Inicia sesión.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'company_name' => 'nombre de la empresa',
            'owner_name' => 'tu nombre',
            'owner_email' => 'correo',
            'password' => 'contraseña',
        ];
    }
}
