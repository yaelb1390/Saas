<?php

declare(strict_types=1);

namespace App\Modules\Loans\Http\Requests;

use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\Loans\Enums\LoanFrequency;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreLoanRequest extends FormRequest
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
            // Cliente existente (de la empresa activa) O uno nuevo escrito a mano: uno de los dos.
            'customer_id' => [
                'required_without:new_customer_name', 'nullable', 'integer',
                Rule::exists('customers', 'id')
                    ->where('company_id', $companyId)
                    ->whereNull('deleted_at'),
            ],
            'new_customer_name' => ['required_without:customer_id', 'nullable', 'string', 'max:255'],
            'new_customer_phone' => ['nullable', 'string', 'max:50'],
            'principal' => ['required', 'numeric', 'gt:0'],
            // El interés lo coloca el administrador: la tasa (%) o el monto directo (que manda).
            'interest_rate' => ['nullable', 'numeric', 'min:0'],
            'interest_amount' => ['nullable', 'numeric', 'min:0'],
            'installments_count' => ['required', 'integer', 'min:1', 'max:1000'],
            'frequency' => ['required', Rule::enum(LoanFrequency::class)],
            'start_date' => ['required', 'date'],
            'late_fee_rate' => ['nullable', 'numeric', 'min:0'],
            'collateral' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'customer_id' => 'cliente',
            'new_customer_name' => 'nombre del cliente',
            'new_customer_phone' => 'teléfono del cliente',
            'principal' => 'capital',
            'interest_rate' => 'tasa de interés',
            'interest_amount' => 'monto de interés',
            'installments_count' => 'número de cuotas',
            'frequency' => 'frecuencia',
            'start_date' => 'fecha de inicio',
            'late_fee_rate' => 'tasa de mora',
            'collateral' => 'garantía',
        ];
    }
}
