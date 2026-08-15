<?php

declare(strict_types=1);

namespace App\Modules\Finance\Http\Requests;

use App\Modules\Core\Tenancy\CurrentCompany;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Alta de un gasto.
 *
 * El concepto es OBLIGATORIO y no admite texto libre: es lo único que después permite contestar «¿en
 * qué se me va el dinero?». Dejarlo opcional sería construir el informe y a la vez la razón por la
 * que saldría vacío.
 */
final class StoreExpenseRequest extends FormRequest
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
            'account_id' => [
                'required', 'integer',
                Rule::exists('accounts', 'id')->where('company_id', $companyId),
            ],
            'expense_category_id' => [
                'required', 'integer',
                Rule::exists('expense_categories', 'id')
                    ->where('company_id', $companyId)
                    ->whereNull('deleted_at'),
            ],
            'supplier_id' => [
                'nullable', 'integer',
                Rule::exists('suppliers', 'id')
                    ->where('company_id', $companyId)
                    ->whereNull('deleted_at'),
            ],
            'supplier_name' => ['nullable', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'description' => ['required', 'string', 'max:255'],
            'reference' => ['nullable', 'string', 'max:100'],
            // No se admite fechar un gasto en el futuro: sería dinero que todavía no ha salido, y
            // eso es una cuenta por pagar, no un gasto.
            'paid_at' => ['required', 'date', 'before_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'account_id' => 'cuenta',
            'expense_category_id' => 'concepto',
            'supplier_id' => 'proveedor',
            'supplier_name' => 'a quién se le pagó',
            'amount' => 'monto',
            'description' => 'descripción',
            'reference' => 'referencia',
            'paid_at' => 'fecha de pago',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'paid_at.before_or_equal' => 'Un gasto no puede tener fecha futura: si todavía no lo has pagado, no es un gasto.',
        ];
    }
}
