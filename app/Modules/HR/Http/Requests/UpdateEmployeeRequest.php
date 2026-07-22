<?php

declare(strict_types=1);

namespace App\Modules\HR\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateEmployeeRequest extends FormRequest
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
            'email' => ['nullable', 'email', 'max:255'],
            'position' => ['nullable', 'string', 'max:120'],
            'salary' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    public function attributes(): array
    {
        return ['name' => 'nombre', 'email' => 'correo', 'position' => 'cargo', 'salary' => 'salario'];
    }
}
