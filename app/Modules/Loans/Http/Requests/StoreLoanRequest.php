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
            // El cliente debe ser de la empresa activa: el servicio lo revalida, pero se corta aquí.
            'customer_id' => [
                'required', 'integer',
                Rule::exists('customers', 'id')
                    ->where('company_id', $companyId)
                    ->whereNull('deleted_at'),
            ],
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
