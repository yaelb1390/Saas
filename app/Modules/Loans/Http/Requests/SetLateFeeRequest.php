<?php

declare(strict_types=1);

namespace App\Modules\Loans\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class SetLateFeeRequest extends FormRequest
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
            'amount' => ['required', 'numeric', 'min:0'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'amount' => 'mora',
        ];
    }
}
