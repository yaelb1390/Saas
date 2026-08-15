<?php

declare(strict_types=1);

namespace App\Modules\Finance\Http\Requests;

use App\Modules\Core\Tenancy\CurrentCompany;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Alta y edición de un concepto de gasto.
 *
 * El nombre es único por empresa: dos «Luz» harían que el informe repartiera el mismo gasto en dos
 * filas y ninguna de las dos diría la verdad.
 */
final class StoreExpenseCategoryRequest extends FormRequest
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
        $categoria = $this->route('category');

        return [
            'name' => [
                'required', 'string', 'max:100',
                Rule::unique('expense_categories', 'name')
                    ->where('company_id', $companyId)
                    ->whereNull('deleted_at')
                    ->ignore($categoria?->id),
            ],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'nombre del concepto',
        ];
    }
}
