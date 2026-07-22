<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests;

use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\Inventory\Http\Requests\Concerns\ValidatesPartFields;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreProductRequest extends FormRequest
{
    use ValidatesPartFields;

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
            'sku' => [
                'required', 'string', 'max:100',
                // withoutTrashed(): un SKU de un producto borrado se puede reutilizar. Va de la mano
                // del índice único parcial de la base (solo cuenta entre productos activos).
                Rule::unique('products', 'sku')->where('company_id', $companyId)->withoutTrashed(),
            ],
            'name' => ['required', 'string', 'max:255'],
            // Único por empresa: escanear debe resolver a un solo producto. Dejarlo vacío es lo
            // normal (no todo artículo trae código), y los NULL no colisionan entre sí.
            //
            // Sin withoutTrashed(), igual que el SKU: el índice único de la base SÍ ve las filas
            // borradas en suave, así que ignorarlas aquí haría pasar la validación y reventar el
            // INSERT con un error 500. Contrapartida asumida: borrar un producto quema su código.
            'barcode' => [
                'nullable', 'string', 'max:64',
                Rule::unique('products', 'barcode')->where('company_id', $companyId)->withoutTrashed(),
            ],
            'category_id' => [
                'nullable', 'integer',
                Rule::exists('categories', 'id')->where('company_id', $companyId),
            ],
            'unit' => ['nullable', 'string', 'max:50'],
            'cost' => ['nullable', 'numeric', 'min:0'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'initial_stock' => ['nullable', 'numeric', 'min:0'],
            // Desmarcar «controla stock» convierte el producto en un servicio (no descuenta stock).
            'track_stock' => ['nullable', 'boolean'],
            ...$this->partFieldRules(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'sku' => 'SKU',
            'name' => 'nombre',
            'barcode' => 'código de barras',
            'category_id' => 'categoría',
            'cost' => 'costo',
            'price' => 'precio',
            'initial_stock' => 'stock inicial',
            ...$this->partFieldAttributes(),
        ];
    }
}
