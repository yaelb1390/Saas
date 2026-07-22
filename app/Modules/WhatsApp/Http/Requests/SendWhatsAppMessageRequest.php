<?php

declare(strict_types=1);

namespace App\Modules\WhatsApp\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class SendWhatsAppMessageRequest extends FormRequest
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
            // Solo dígitos: código de país + número, sin signos (ej. 18095551234).
            'phone' => ['required', 'string', 'regex:/^\d{8,15}$/'],
            'body' => ['required', 'string', 'max:4096'],
        ];
    }

    public function attributes(): array
    {
        return ['phone' => 'teléfono', 'body' => 'mensaje'];
    }

    public function messages(): array
    {
        return ['phone.regex' => 'El teléfono debe ser solo dígitos, con código de país (ej. 18095551234).'];
    }
}
