<?php

declare(strict_types=1);

namespace App\Modules\Rental\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Anotar un daño suelto sobre un alquiler (fuera del checklist de devolución). */
final class StoreDamageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('vehicle_rentals.manage') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'category' => ['required', 'in:scratch,dent,glass,tire,interior,paint,mechanical,other'],
            'description' => ['required', 'string', 'max:1000'],
            'amount' => ['nullable', 'numeric', 'min:0'],
            'responsible' => ['nullable', 'string', 'max:120'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'category.required' => 'Elige el tipo de daño.',
            'description.required' => 'Describe el daño.',
        ];
    }
}
