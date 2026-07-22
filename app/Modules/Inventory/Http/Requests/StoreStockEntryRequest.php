<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests;

use App\Modules\Core\Tenancy\CurrentCompany;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreStockEntryRequest extends FormRequest
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
            // «exists» consulta la base directamente, sin pasar por el CompanyScope: hay que acotar
            // a la empresa activa a mano o se aceptaría el id de un producto ajeno y la entrada
            // acabaría sumando stock en el almacén de otra empresa.
            'product_id' => [
                'required', 'integer',
                Rule::exists('products', 'id')
                    ->where('company_id', $companyId)
                    ->whereNull('deleted_at'),
            ],
            'warehouse_id' => [
                'required', 'integer',
                Rule::exists('warehouses', 'id')->where('company_id', $companyId),
            ],
            // gt:0 y no min:0: una entrada de cero no es una entrada. Para descontar existe el
            // camino de las ventas y, en su día, el ajuste negativo.
            'quantity' => ['required', 'numeric', 'gt:0'],
            'notes' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'product_id' => 'producto',
            'warehouse_id' => 'almacén',
            'quantity' => 'cantidad',
            'notes' => 'nota',
        ];
    }
}
