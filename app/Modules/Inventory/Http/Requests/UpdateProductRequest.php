<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests;

use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\Inventory\Http\Requests\Concerns\ValidatesPartFields;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateProductRequest extends FormRequest
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
                Rule::unique('products', 'sku')
                    ->where('company_id', $companyId)
                    ->ignore($this->route('product'))
                    ->withoutTrashed(),
            ],
            'name' => ['required', 'string', 'max:255'],
            // Mismo criterio que en el alta (ver StoreProductRequest), ignorando el propio producto
            // para que reguardar sin tocar el código no choque consigo mismo.
            'barcode' => [
                'nullable', 'string', 'max:64',
                Rule::unique('products', 'barcode')
                    ->where('company_id', $companyId)
                    ->ignore($this->route('product'))
                    ->withoutTrashed(),
            ],
            'category_id' => [
                'nullable', 'integer',
                Rule::exists('categories', 'id')->where('company_id', $companyId),
            ],
            'unit' => ['nullable', 'string', 'max:50'],
            'cost' => ['nullable', 'numeric', 'min:0'],
            'price' => ['nullable', 'numeric', 'min:0'],
            // description y track_stock faltaban aquí: update() solo aplica lo validado, así que
            // hasta ahora se descartaban en silencio aunque el modelo los admitiera.
            'description' => ['nullable', 'string', 'max:1000'],
            'track_stock' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
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
            'cost' => 'costo',
            'price' => 'precio',
            ...$this->partFieldAttributes(),
        ];
    }
}
