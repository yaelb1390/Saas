<?php

declare(strict_types=1);

namespace App\Modules\Core\Http\Requests;

use App\Modules\Core\Http\Requests\Concerns\LinksAnEmployee;
use App\Modules\Core\Support\RoleCatalog;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

final class UpdateUserRequest extends FormRequest
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
        // Cast explícito: con `strict_types` la regla del vínculo no acepta el `mixed` de la ruta.
        $rutaId = $this->route('user')?->id;
        $userId = $rutaId === null ? null : (int) $rutaId;

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($userId)],
            'role' => ['required', 'string', Rule::in(RoleCatalog::assignable())],
            // La contraseña es opcional al editar: solo se cambia si se rellena.
            'password' => ['nullable', 'confirmed', Password::defaults()],
            'is_active' => ['sometimes', 'boolean'],
            'employee_id' => $this->employeeLinkRules($userId),
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
