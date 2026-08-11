<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Una opción dentro de un grupo: «2 bolas» (+60), «Chocolate» (+0), «Sin cebolla» (0).
 */
final class StoreOptionRequest extends FormRequest
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

            // Puede ser negativo a propósito: un «tamaño pequeño» que descuenta del precio base es
            // un caso real. El tope por abajo evita que un descuento desbocado deje la línea en
            // negativo y acabe restando de la caja.
            'price_delta' => ['required', 'numeric', 'min:-99999', 'max:999999'],

            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'nombre',
            'price_delta' => 'recargo',
        ];
    }
}
