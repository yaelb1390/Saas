<?php

declare(strict_types=1);

namespace App\Modules\Loans\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class RegisterLoanPaymentRequest extends FormRequest
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
            'amount' => ['required', 'numeric', 'gt:0'],
            'method' => ['nullable', 'string', 'max:50'],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'amount' => 'monto',
            'method' => 'método',
            'note' => 'nota',
        ];
    }
}
