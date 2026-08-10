<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers;

use App\Modules\Inventory\Http\Requests\StoreCategoryRequest;
use App\Modules\Inventory\Http\Requests\UpdateCategoryRequest;
use App\Modules\Inventory\Models\Category;
use App\Modules\Inventory\Services\CategoryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;

final class CategoryController extends Controller
{
    public function store(StoreCategoryRequest $request, CategoryService $categories): RedirectResponse
    {
        $categories->create(
            $request->string('name')->toString(),
            $request->integer('parent_id') ?: null,
            $request->filled('icon') ? $request->string('icon')->toString() : null,
        );

        return back()->with('panel_ok', 'Categoría creada correctamente.');
    }

    public function update(UpdateCategoryRequest $request, Category $category, CategoryService $categories): RedirectResponse
    {
        $categories->update($category, [
            'name' => $request->string('name')->toString(),
            'parent_id' => $request->integer('parent_id') ?: null,
            'icon' => $request->filled('icon') ? $request->string('icon')->toString() : null,
            'is_active' => $request->boolean('is_active'),
        ]);

        return back()->with('panel_ok', 'Categoría actualizada.');
    }

    public function destroy(Category $category, CategoryService $categories): RedirectResponse
    {
        $categories->delete($category);

        return back()->with('panel_ok', 'Categoría eliminada. Sus productos quedaron sin categoría.');
    }
}
