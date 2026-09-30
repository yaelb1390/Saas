<?php

declare(strict_types=1);

namespace App\Modules\Finance\Http\Requests;

use App\Modules\Core\Tenancy\CurrentCompany;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Edición de una cuenta por cobrar. */
final class UpdateReceivableRequest extends FormRequest
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
            'customer_id' => [
                'nullable', 'integer',
                Rule::exists('customers', 'id')->where('company_id', $companyId)->whereNull('deleted_at'),
            ],
            'customer_name' => ['nullable', 'string', 'max:255'],
            'total' => ['nullable', 'numeric', 'gt:0'],
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
            'customer_id' => 'cliente',
            'customer_name' => 'nombre del cliente',
            'total' => 'monto',
            'due_date' => 'fecha de vencimiento',
        ];
    }
}
