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
            // Opcional: si se deja vacío, `ProductService` genera el siguiente («PROD-000001»).
            // Se sigue admitiendo escribirlo porque muchos negocios ya tienen su propia codificación.
            'sku' => [
                'nullable', 'string', 'max:100',
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

            /*
             * La descripción faltaba aquí.
             *
             * El controlador guarda solo lo VALIDADO, así que un campo sin regla se descarta en
             * silencio: se escribiría en el formulario y no llegaría a la base. La misma trampa que
             * ya se había corregido en el formulario de editar.
             */
            'description' => ['nullable', 'string', 'max:1000'],
            'cost' => ['nullable', 'numeric', 'min:0'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'initial_stock' => ['nullable', 'numeric', 'min:0'],

            /*
             * A qué almacén entra ese stock inicial.
             *
             * La regla se acota a la empresa A MANO: `exists` consulta la tabla directamente, sin
             * pasar por el aislamiento por empresa, así que sin esto aceptaría el id de un almacén
             * ajeno y una empresa acabaría metiendo existencia en la de al lado.
             */
            'warehouse_id' => [
                'nullable', 'integer',
                Rule::exists('warehouses', 'id')->where('company_id', $companyId)->where('is_active', true),
            ],
            // Desmarcar «controla stock» convierte el producto en un servicio (no descuenta stock).
            'track_stock' => ['nullable', 'boolean'],
            // Foto del producto (opcional).
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:15360'],
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
