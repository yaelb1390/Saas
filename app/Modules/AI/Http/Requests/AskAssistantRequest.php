<?php

declare(strict_types=1);

namespace App\Modules\AI\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class AskAssistantRequest extends FormRequest
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
            'query' => ['required', 'string', 'min:3', 'max:1000'],
        ];
    }

    public function attributes(): array
    {
        return ['query' => 'pregunta'];
    }
}
