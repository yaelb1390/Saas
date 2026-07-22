<?php

declare(strict_types=1);

namespace App\Modules\Core\Http\Requests;

use App\Modules\Core\Enums\BillingCycle;
use App\Modules\Core\Support\ModuleRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StorePlanRequest extends FormRequest
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
        $planId = $this->route('plan')?->id;

        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'alpha_dash', Rule::unique('plans', 'slug')->ignore($planId)],
            'description' => ['nullable', 'string', 'max:255'],
            'price' => ['required', 'numeric', 'min:0'],
            'billing_cycle' => ['required', Rule::enum(BillingCycle::class)],
            'trial_days' => ['required', 'integer', 'min:0', 'max:365'],
            'max_users' => ['nullable', 'integer', 'min:1'],
            'max_branches' => ['nullable', 'integer', 'min:1'],
            'is_active' => ['sometimes', 'boolean'],
            'modules' => ['present', 'array'],
            'modules.*' => ['string', Rule::in(ModuleRegistry::keys())],
        ];
    }

    public function attributes(): array
    {
        return [
            'name' => 'nombre', 'slug' => 'identificador', 'price' => 'precio',
            'billing_cycle' => 'ciclo', 'trial_days' => 'días de prueba',
        ];
    }
}
