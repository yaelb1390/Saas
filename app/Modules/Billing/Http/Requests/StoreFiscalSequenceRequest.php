<?php

declare(strict_types=1);

namespace App\Modules\Billing\Http\Requests;

use App\Modules\Billing\Enums\NcfType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreFiscalSequenceRequest extends FormRequest
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
            'type' => ['required', Rule::enum(NcfType::class)],
            'range_from' => ['required', 'integer', 'min:1'],
            'range_to' => ['required', 'integer', 'gte:range_from'],
            'number_length' => ['required', 'integer', 'min:8', 'max:10'],
            // Fecha límite de emisión que autoriza la DGII: no puede estar ya vencida.
            'expires_at' => ['required', 'date', 'after:today'],
        ];
    }

    public function attributes(): array
    {
        return [
            'type' => 'tipo de comprobante',
            'range_from' => 'desde',
            'range_to' => 'hasta',
            'number_length' => 'longitud',
            'expires_at' => 'fecha límite',
        ];
    }
}
