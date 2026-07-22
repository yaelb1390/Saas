<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Http\Requests;

use App\Modules\Core\Tenancy\CurrentCompany;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StorePurchaseOrderRequest extends FormRequest
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
            'supplier_id' => [
                'required', 'integer',
                Rule::exists('suppliers', 'id')
                    ->where('company_id', $companyId)
                    ->whereNull('deleted_at'),
            ],
            'warehouse_id' => [
                'required', 'integer',
                Rule::exists('warehouses', 'id')->where('company_id', $companyId),
            ],
            'lines' => ['required', 'array', 'min:1'],

            // Ésta es la defensa crítica: PurchaseOrderService protege el proveedor y el almacén con
            // findOrFail (bajo el CompanyScope), pero inserta las líneas SIN comprobar el producto.
            // Sin este «exists» acotado a la empresa, un id ajeno entraría en la orden y, al
            // recibirla, se sumaría existencia al producto de otra empresa.
            'lines.*.product_id' => [
                'required', 'integer',
                Rule::exists('products', 'id')
                    ->where('company_id', $companyId)
                    ->whereNull('deleted_at'),
            ],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.unit_cost' => ['required', 'numeric', 'min:0'],
            'tax' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'supplier_id' => 'proveedor',
            'warehouse_id' => 'almacén',
            'lines' => 'líneas',
            'lines.*.product_id' => 'producto',
            'lines.*.quantity' => 'cantidad',
            'lines.*.unit_cost' => 'costo unitario',
            'tax' => 'impuesto',
        ];
    }
}
