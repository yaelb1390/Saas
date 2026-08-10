<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests;

use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\Inventory\Support\CategoryIcons;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreCategoryRequest extends FormRequest
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

        return [
            // Único por empresa: la tabla ya lo impone con un índice sobre [company_id, name]. Sin
            // withoutTrashed() porque ese índice sí ve las filas borradas en suave; ignorarlas aquí
            // dejaría pasar la validación y reventaría el INSERT.
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('categories', 'name')->where('company_id', $companyId),
            ],
            // Anidamiento opcional: la tabla es jerárquica desde su creación.
            'parent_id' => [
                'nullable', 'integer',
                Rule::exists('categories', 'id')->where('company_id', $companyId),
            ],
            // Lista cerrada: la barra lateral del punto de venta debe mantener un aspecto homogéneo.
            'icon' => ['nullable', 'string', Rule::in(CategoryIcons::all())],
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
        ];
    }
}
