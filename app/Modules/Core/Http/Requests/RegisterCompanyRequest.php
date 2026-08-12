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

            // El cliente elige QUÉ PLAN quiere probar; de él heredará los módulos.
            //
            // La condición `is_active` es la parte que importa: sin ella se podría arrancar una
            // prueba de un plan retirado de la venta enviando su id a mano.
            'plan_id' => ['required', 'integer', Rule::exists('plans', 'id')->where('is_active', true)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'owner_email.unique' => 'Ya existe una cuenta con ese correo. Inicia sesión.',
            'plan_id.required' => 'Elige el plan que quieres probar.',
            'plan_id.exists' => 'Ese plan no está disponible. Elige uno de la lista.',
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
            'plan_id' => 'plan',
        ];
    }
}
