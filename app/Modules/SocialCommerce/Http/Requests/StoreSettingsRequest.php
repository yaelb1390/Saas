<?php

declare(strict_types=1);

namespace App\Modules\SocialCommerce\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StoreSettingsRequest extends FormRequest
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
            // Formato E.164 sin el «+», que es lo que espera wa.me.
            'whatsapp_number' => ['nullable', 'string', 'regex:/^[0-9]{8,15}$/'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'whatsapp_number.regex' => 'Escribe el número con el código de país, solo dígitos (ej. 18095551234).',
        ];
    }
}
