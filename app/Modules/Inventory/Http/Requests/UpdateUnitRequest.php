<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Corregir una unidad ya dada de alta: precio, condición y color.
 *
 * La SERIE no está aquí a propósito: es la identidad de la unidad —lo que se busca para la garantía—,
 * y dejar cambiarla convertiría una corrección en una suplantación. Tampoco el estado ni el stock:
 * eso lo mueven la venta y la baja, no una edición de ficha.
 */
final class UpdateUnitRequest extends FormRequest
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
            'condition' => ['nullable', Rule::in(['nuevo', 'usado', 'reacondicionado'])],
            'color' => ['nullable', 'string', 'max:60'],
            'price' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    public function attributes(): array
    {
        return [
            'condition' => 'condición',
            'price' => 'precio',
        ];
    }
}
