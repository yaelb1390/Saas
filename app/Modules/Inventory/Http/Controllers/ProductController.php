<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers;

use App\Modules\Core\Models\Warehouse;
use App\Modules\Inventory\DTOs\CreateProductData;
use App\Modules\Inventory\Http\Requests\StoreProductRequest;
use App\Modules\Inventory\Http\Requests\UpdateProductRequest;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Services\ProductService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;

final class ProductController extends Controller
{
    public function store(StoreProductRequest $request, ProductService $products): RedirectResponse
    {
        $data = CreateProductData::fromArray($request->validated());

        $warehouse = Warehouse::query()->where('is_default', true)->orderBy('id')->first();
        $initialStock = (string) ($request->input('initial_stock') ?? '0');

        $products->create($data, $warehouse, $initialStock);

        return back()->with('panel_ok', 'Producto creado correctamente.');
    }

    public function update(UpdateProductRequest $request, Product $product): RedirectResponse
    {
        // El stock no se edita aquí: se ajusta mediante movimientos de inventario.
        $product->update($request->validated());

        return back()->with('panel_ok', 'Producto actualizado.');
    }

    public function destroy(Product $product): RedirectResponse
    {
        $product->delete();

        return back()->with('panel_ok', 'Producto eliminado.');
    }
}
