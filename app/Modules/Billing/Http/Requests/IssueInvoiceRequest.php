<?php

declare(strict_types=1);

namespace App\Modules\Billing\Http\Requests;

use App\Modules\Billing\Enums\NcfType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class IssueInvoiceRequest extends FormRequest
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
            // La existencia se comprueba con la venta ya aislada por empresa en el controlador.
            'sale_id' => ['required', 'integer'],
            'type' => ['required', Rule::enum(NcfType::class)],
            'customer_tax_id' => ['nullable', 'string', 'max:20'],
        ];
    }

    public function attributes(): array
    {
        return ['sale_id' => 'venta', 'type' => 'tipo de comprobante', 'customer_tax_id' => 'RNC/cédula'];
    }
}
