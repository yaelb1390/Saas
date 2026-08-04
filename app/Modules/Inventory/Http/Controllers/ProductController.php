<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers;

use App\Modules\Core\Models\Warehouse;
use App\Modules\Inventory\DTOs\CreateProductData;
use App\Modules\Inventory\Http\Requests\StoreProductRequest;
use App\Modules\Inventory\Http\Requests\UpdateProductRequest;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Services\ProductService;
use App\Modules\Inventory\Support\ProductImageStore;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

final class ProductController extends Controller
{
    public function store(StoreProductRequest $request, ProductService $products, ProductImageStore $images): RedirectResponse
    {
        $data = CreateProductData::fromArray($request->validated());

        $warehouse = Warehouse::query()->where('is_default', true)->orderBy('id')->first();
        $initialStock = (string) ($request->input('initial_stock') ?? '0');

        $product = $products->create($data, $warehouse, $initialStock);

        if ($request->hasFile('image')) {
            $images->store($product, $request->file('image'));
        }

        return back()->with('panel_ok', 'Producto creado correctamente.');
    }

    public function update(UpdateProductRequest $request, Product $product, ProductImageStore $images): RedirectResponse
    {
        // El stock no se edita aquí: se ajusta mediante movimientos de inventario. El archivo de la
        // foto no es una columna, así que se excluye del update y se procesa aparte.
        $product->update($request->safe()->except('image'));

        if ($request->hasFile('image')) {
            $images->store($product, $request->file('image'));
        }

        return back()->with('panel_ok', 'Producto actualizado.');
    }

    public function destroy(Product $product, ProductImageStore $images): RedirectResponse
    {
        $images->delete($product);
        $product->delete();

        return back()->with('panel_ok', 'Producto eliminado.');
    }

    /** Sirve la foto del producto (cacheable). */
    public function image(Product $product): Response
    {
        abort_unless($product->hasImage(), 404);

        $disk = Storage::disk('local');
        $path = (string) $product->image_path;

        abort_unless($disk->exists($path), 404);

        return response($disk->get($path), 200, [
            'Content-Type' => $disk->mimeType($path) ?: 'image/jpeg',
            'Cache-Control' => 'public, max-age=604800',
        ]);
    }
}
