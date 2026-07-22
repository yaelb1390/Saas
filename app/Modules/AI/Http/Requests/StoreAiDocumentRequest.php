<?php

declare(strict_types=1);

namespace App\Modules\AI\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StoreAiDocumentRequest extends FormRequest
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
            'title' => ['required', 'string', 'max:255'],
            'source' => ['nullable', 'string', 'max:255'],
            'content' => ['required', 'string', 'min:10'],
        ];
    }

    public function attributes(): array
    {
        return ['title' => 'título', 'source' => 'fuente', 'content' => 'contenido'];
    }
}
