<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests;

use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\Inventory\Models\Category;
use App\Modules\Inventory\Support\CategoryIcons;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateCategoryRequest extends FormRequest
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
        $companyId = app(CurrentCompany::class)->id();
        $category = $this->route('category');
        $categoryId = $category instanceof Category ? $category->id : null;

        return [
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('categories', 'name')->where('company_id', $companyId)->ignore($categoryId),
            ],
            'parent_id' => [
                'nullable', 'integer',
                // No puede ser su propio padre: crearía un ciclo y dejaría la categoría fuera de
                // cualquier recorrido del árbol.
                Rule::notIn([$categoryId]),
                Rule::exists('categories', 'id')->where('company_id', $companyId),
            ],
            'icon' => ['nullable', 'string', Rule::in(CategoryIcons::all())],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'nombre',
            'parent_id' => 'categoría padre',
            'is_active' => 'activa',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'parent_id.not_in' => 'Una categoría no puede ser su propia categoría padre.',
        ];
    }
}
