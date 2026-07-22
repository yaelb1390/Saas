<?php

declare(strict_types=1);

namespace App\Modules\Billing\Http\Requests;

use App\Modules\Billing\Enums\NcfType;
use App\Modules\Core\Tenancy\CurrentCompany;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Facturación desde el mostrador de repuestos: carrito (JSON), tipo de NCF, cliente y pago. El RNC
 * se valida en profundidad en InvoiceService según el tipo (el mensaje fiscal es su responsabilidad).
 */
final class IssuePartsInvoiceRequest extends FormRequest
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
            'cart' => ['required', 'string'],
            'type' => ['required', Rule::enum(NcfType::class)],
            'customer_tax_id' => ['nullable', 'string', 'max:20'],
            'customer_name' => ['nullable', 'string', 'max:255'],
            'paid' => ['required', 'numeric', 'min:0'],
            // La regla «exists» no pasa por el CompanyScope: se acota a la empresa activa a mano.
            'customer_id' => [
                'nullable', 'integer',
                Rule::exists('customers', 'id')
                    ->where('company_id', app(CurrentCompany::class)->id())
                    ->whereNull('deleted_at'),
            ],
        ];
    }

    public function attributes(): array
    {
        return [
            'cart' => 'ticket',
            'type' => 'tipo de comprobante',
            'customer_tax_id' => 'RNC/cédula',
            'customer_name' => 'cliente',
            'paid' => 'pagado',
        ];
    }
}
