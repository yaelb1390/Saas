<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests;

use App\Modules\Core\Tenancy\CurrentCompany;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Confirmación de una remesa entera.
 *
 * Las líneas llegan como un array del navegador, así que se valida CADA una: un id de producto
 * inventado o de otra empresa dentro de una lista de treinta pasaría desapercibido si solo se mirara
 * el conjunto.
 */
final class StoreGoodsReceiptRequest extends FormRequest
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
        $companyId = app(CurrentCompany::class)->id();

        return [
            'warehouse_id' => [
                'required', 'integer',
                Rule::exists('warehouses', 'id')->where('company_id', $companyId),
            ],
            'supplier_id' => [
                'nullable', 'integer',
                Rule::exists('suppliers', 'id')->where('company_id', $companyId)->whereNull('deleted_at'),
            ],
            'supplier_name' => ['nullable', 'string', 'max:255'],
            'reference' => ['nullable', 'string', 'max:100'],
            // No se admite fechar una entrada en el futuro: sería mercancía que aún no ha llegado.
            'received_at' => ['required', 'date', 'before_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:500'],

            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => [
                'required', 'integer',
                Rule::exists('products', 'id')->where('company_id', $companyId)->whereNull('deleted_at'),
            ],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.unit_cost' => ['nullable', 'numeric', 'min:0'],
            'lines.*.update_cost' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'warehouse_id' => 'almacén',
            'supplier_id' => 'proveedor',
            'reference' => 'referencia',
            'received_at' => 'fecha de entrada',
            'lines' => 'productos',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'lines.required' => 'No hay nada que entrar: escanea o busca al menos un producto.',
            'lines.min' => 'No hay nada que entrar: escanea o busca al menos un producto.',
            'received_at.before_or_equal' => 'La fecha no puede ser futura: la mercancía todavía no ha llegado.',
            'lines.*.quantity.gt' => 'Todas las cantidades tienen que ser mayores que cero.',
        ];
    }
}
