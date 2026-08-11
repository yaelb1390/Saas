<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests;

use App\Modules\Inventory\Enums\SelectionType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Sirve para crear y para editar un grupo de opciones.
 */
final class StoreOptionGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // la ruta ya exige el permiso products.manage
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'selection_type' => ['required', Rule::enum(SelectionType::class)],
            'is_required' => ['sometimes', 'boolean'],
            'min_selections' => ['nullable', 'integer', 'min:0', 'max:50'],
            'max_selections' => ['nullable', 'integer', 'min:1', 'max:50'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->input('selection_type') !== SelectionType::Multiple->value) {
                return; // en «elegir una» los límites se ignoran, no hay nada que comprobar
            }

            $min = (int) $this->input('min_selections', 0);
            $max = $this->input('max_selections');

            // Un grupo con mínimo mayor que el máximo no se puede satisfacer nunca: al cajero le
            // quedaría un producto imposible de añadir al ticket, sin explicación en pantalla.
            if ($max !== null && $max !== '' && (int) $max < $min) {
                $validator->errors()->add(
                    'max_selections',
                    'El máximo no puede ser menor que el mínimo: nadie podría completar la selección.',
                );
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'nombre',
            'selection_type' => 'tipo de selección',
            'min_selections' => 'mínimo',
            'max_selections' => 'máximo',
        ];
    }
}
