<?php

declare(strict_types=1);

namespace App\Modules\Core\Http\Requests;

use App\Modules\Core\Support\ModuleRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateCompanyModulesRequest extends FormRequest
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
            'follow_plan' => ['sometimes', 'boolean'],
            'modules' => ['sometimes', 'array'],
            'modules.*' => ['string', Rule::in(ModuleRegistry::keys())],
        ];
    }
}
