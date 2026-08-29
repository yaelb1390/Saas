<?php

declare(strict_types=1);

namespace App\Modules\Dealer\Http\Requests;

use App\Modules\Core\Tenancy\CurrentCompany;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Un trabajo de preparación sobre una unidad. */
final class StoreJobRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('vehicle_jobs.manage') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $companyId = app(CurrentCompany::class)->id() ?? 0;

        return [
            'vehicle_id' => [
                'required', 'integer',
                Rule::exists('vehicles', 'id')->where(fn ($q) => $q->where('company_id', $companyId)),
            ],
            'description' => ['required', 'string', 'max:200'],
            'cost' => ['nullable', 'numeric', 'min:0'],
            'performed_by' => ['nullable', 'string', 'max:120'],
            'performed_at' => ['nullable', 'date'],
            'done' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'vehicle_id.required' => 'Elige el vehículo.',
            'vehicle_id.exists' => 'Ese vehículo no es de tu empresa.',
            'description.required' => 'Escribe qué se le hizo.',
        ];
    }
}
