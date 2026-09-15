<?php

declare(strict_types=1);

namespace App\Modules\Rental\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Los datos de una inspección (entrega o devolución): mismo formulario para las dos, ambas piden lo
 * mismo. Las fotos y la firma son opcionales a propósito: no todo negocio las exige, y bloquear la
 * entrega porque la cámara no cargó sería peor que dejarla pasar sin foto.
 */
final class InspectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('vehicle_rentals.manage') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'mileage' => ['required', 'integer', 'min:0'],
            'fuel_level' => ['required', 'in:empty,quarter,half,three_quarters,full'],
            'exterior_condition' => ['nullable', 'string', 'max:1000'],
            'interior_condition' => ['nullable', 'string', 'max:1000'],
            'accessories' => ['nullable', 'string', 'max:1000'],
            'checklist' => ['nullable', 'array'],
            'observations' => ['nullable', 'string', 'max:2000'],
            'signature' => ['nullable', 'string'],
            'photos' => ['nullable', 'array', 'max:12'],
            'photos.*' => ['image', 'max:8192'],
            // Solo en la devolución, pero se valida aquí para no duplicar el resto: si llega vacío,
            // el servicio simplemente no crea ningún daño.
            'damages' => ['nullable', 'array'],
            'damages.*.category' => ['required_with:damages', 'in:scratch,dent,glass,tire,interior,paint,mechanical,other'],
            'damages.*.description' => ['required_with:damages', 'string', 'max:1000'],
            'damages.*.amount' => ['nullable', 'numeric', 'min:0'],
            'damages.*.responsible' => ['nullable', 'string', 'max:120'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'mileage.required' => 'Falta el kilometraje.',
            'fuel_level.required' => 'Di cuánto combustible tiene.',
        ];
    }
}
