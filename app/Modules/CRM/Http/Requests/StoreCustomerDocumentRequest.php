<?php

declare(strict_types=1);

namespace App\Modules\CRM\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StoreCustomerDocumentRequest extends FormRequest
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
            // El contenido se guarda en la base (base64): se limita a archivos pequeños.
            'file' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
            'name' => ['nullable', 'string', 'max:120'],
        ];
    }

    public function attributes(): array
    {
        return ['file' => 'archivo', 'name' => 'nombre'];
    }
}
