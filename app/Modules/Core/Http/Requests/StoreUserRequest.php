<?php

declare(strict_types=1);

namespace App\Modules\Core\Http\Requests;

use App\Modules\Core\Http\Requests\Concerns\LinksAnEmployee;
use App\Modules\Core\Support\RoleCatalog;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

final class StoreUserRequest extends FormRequest
{
    use LinksAnEmployee;

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
            // El correo es la credencial de acceso: único en toda la plataforma.
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'confirmed', Password::defaults()],
            'role' => ['required', 'string', Rule::in(RoleCatalog::assignable())],
            // De quién es esta cuenta. Sin esto, un repartidor que inicia sesión no es nadie: no hay
            // forma de saber qué entregas son suyas.
            'employee_id' => $this->employeeLinkRules(),
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $this->employeeLinkMessage($validator);
    }

    public function attributes(): array
    {
        return [
            'name' => 'nombre', 'email' => 'correo', 'password' => 'contraseña',
            'role' => 'rol', 'employee_id' => 'empleado',
        ];
    }
}
