<?php

declare(strict_types=1);

namespace App\Modules\CRM\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StoreCustomerRequest extends FormRequest
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
            'phone' => ['nullable', 'string', 'max:50'],
            'tax_id' => ['nullable', 'string', 'max:50'],
            'cedula' => ['nullable', 'string', 'max:20'],
            'address' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function attributes(): array
    {
        return ['name' => 'nombre', 'email' => 'correo', 'phone' => 'teléfono', 'tax_id' => 'RNC', 'cedula' => 'cédula'];
    }
}
