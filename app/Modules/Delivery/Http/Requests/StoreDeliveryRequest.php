<?php

declare(strict_types=1);

namespace App\Modules\Delivery\Http\Requests;

use App\Modules\Core\Tenancy\CurrentCompany;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Alta de una entrega.
 *
 * Solo la dirección es obligatoria, y con razón: sin ella no hay reparto posible. El resto —cliente,
 * teléfono, monto— se rellena si se sabe, porque el pedido que entra por WhatsApp a veces llega con
 * media información y hay que poder apuntarlo igual.
 */
final class StoreDeliveryRequest extends FormRequest
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
            'address' => ['required', 'string', 'max:255'],
            'customer_name' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'customer_id' => [
                'nullable', 'integer',
                Rule::exists('customers', 'id')->where('company_id', $companyId)->whereNull('deleted_at'),
            ],
            'sale_id' => [
                'nullable', 'integer',
                Rule::exists('sales', 'id')->where('company_id', $companyId)->whereNull('deleted_at'),
            ],
            'employee_id' => [
                'nullable', 'integer',
                Rule::exists('employees', 'id')->where('company_id', $companyId)->whereNull('deleted_at'),
            ],
            'amount_to_collect' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'address' => 'dirección',
            'customer_name' => 'nombre del cliente',
            'phone' => 'teléfono',
            'employee_id' => 'repartidor',
            'amount_to_collect' => 'monto a cobrar',
        ];
    }
}
