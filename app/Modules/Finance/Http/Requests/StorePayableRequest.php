<?php

declare(strict_types=1);

namespace App\Modules\Finance\Http\Requests;

use App\Modules\Core\Tenancy\CurrentCompany;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Alta manual de una cuenta por pagar (deuda suelta que no viene de una orden de compra recibida).
 */
final class StorePayableRequest extends FormRequest
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
                'nullable', 'integer',
                Rule::exists('suppliers', 'id')->where('company_id', $companyId)->whereNull('deleted_at'),
            ],
            'supplier_name' => ['required_without:supplier_id', 'nullable', 'string', 'max:255'],
            'total' => ['required', 'numeric', 'gt:0'],
            'due_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'supplier_id' => 'proveedor',
            'supplier_name' => 'nombre del proveedor',
            'total' => 'monto',
            'due_date' => 'fecha de vencimiento',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'supplier_name.required_without' => 'Dinos a quién se le debe: elige un proveedor o escribe su nombre.',
        ];
    }
}
