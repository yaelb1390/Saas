<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers;

use App\Modules\Core\Models\Warehouse;
use App\Modules\Core\Support\EntregaDeArchivo;
use App\Modules\Inventory\DTOs\CreateProductData;
use App\Modules\Inventory\Http\Requests\StoreProductRequest;
use App\Modules\Inventory\Http\Requests\UpdateProductRequest;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Services\ProductService;
use App\Modules\Inventory\Support\ProductImageStore;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\Response;

final class ProductController extends Controller
{
    public function store(StoreProductRequest $request, ProductService $products, ProductImageStore $images): RedirectResponse
    {
        $data = CreateProductData::fromArray($request->validated());

        /*
         * EL ALMACÉN QUE SE ELIGIÓ, y el de por omisión solo como red.
         *
         * Estaba escrito a fuego: dabas de alta cien piezas para la sucursal y aparecían en el
         * principal, sin decir nada. Es el mismo fallo que tenía el cobro, y con la misma
         * consecuencia: el inventario deja de decir dónde está la mercancía.
         */
        $elegido = $request->integer('warehouse_id') ?: null;

        $warehouse = ($elegido !== null ? Warehouse::find($elegido) : null)
            ?? Warehouse::query()->where('is_default', true)->orderBy('id')->first();

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

    /**
     * Copia los datos de catálogo de un producto a uno nuevo.
     *
     * Arranca con SKU propio (regenerado, nunca el del original: dos productos con el mismo SKU
     * romperían la búsqueda por código) y con existencia en cero: duplicar la ficha no duplica la
     * mercancía física que representa. Tampoco copia la foto ni el código de barras —compartir un
     * barcode entre dos productos rompería el escaneo, que asume que identifica uno solo.
     */
    public function duplicate(Product $product, ProductService $products): RedirectResponse
    {
        $data = new CreateProductData(
            sku: null,
            name: $product->name.' (copia)',
            categoryId: $product->category_id,
            description: $product->description,
            barcode: null,
            unit: $product->unit,
            cost: (string) $product->cost,
            price: (string) $product->price,
            trackStock: $product->track_stock,
            partNumber: $product->part_number,
            brand: $product->brand,
            vehicleMake: $product->vehicle_make,
            vehicleModel: $product->vehicle_model,
            yearFrom: $product->year_from,
            yearTo: $product->year_to,
            location: $product->location,
        );

        // Sin almacén ni cantidad inicial: ProductService::create() no crea ninguna fila de
        // existencia, así que el duplicado nace en cero en todos los almacenes.
        $duplicate = $products->create($data);

        // tracks_serials no viaja en el DTO (ver CreateProductData): es el único campo de catálogo
        // que hoy solo se escribe desde update(), nunca desde store(). Se completa aparte para no
        // ampliar el DTO por un caso que usa un solo sitio.
        if ($product->tracks_serials) {
            $duplicate->update(['tracks_serials' => true]);
        }

        return back()->with('panel_ok', 'Producto duplicado. Revisa el nuevo antes de venderlo: no tiene existencia todavía.');
    }

    public function destroy(Product $product, ProductImageStore $images): RedirectResponse
    {
        $images->delete($product);
        $product->delete();

        return back()->with('panel_ok', 'Producto eliminado.');
    }

    /**
     * Borrado de varios productos a la vez.
     *
     * Dos modos: los marcados en pantalla, o TODOS los que coinciden con la búsqueda activa —que es
     * lo que hace falta para vaciar un catálogo de cientos sin recorrer veinte páginas—.
     *
     * El borrado es lógico, como el de uno solo: `sale_items` apunta a `products` con RESTRICT, así
     * que un borrado real fallaría en cuanto un producto tuviera una venta. Archivarlo lo saca del
     * inventario y del punto de venta sin tocar el histórico ni los recibos ya emitidos.
     */
    public function bulkDestroy(Request $request, ProductImageStore $images): RedirectResponse
    {
        $datos = $request->validate([
            'ids' => ['array'],
            'ids.*' => ['integer'],
            'todos' => ['sometimes', 'boolean'],
            'q' => ['nullable', 'string'],
            'filter' => ['nullable', 'string'],
            'category_id' => ['nullable', 'integer'],
            'warehouse_id' => ['nullable', 'integer'],
        ]);

        // El ámbito de empresa va en el modelo, así que un id de otra empresa no aparece por aquí
        // aunque se envíe a mano: la consulta simplemente no lo encuentra.
        $productos = $request->boolean('todos')
            ? Product::query()->filtered(
                $datos['q'] ?? null,
                ($datos['filter'] ?? null) === 'low_stock',
                $datos['category_id'] ?? null,
                $datos['warehouse_id'] ?? null,
            )->get()
            : Product::query()->whereIn('id', $datos['ids'] ?? [])->get();

        if ($productos->isEmpty()) {
            return back()->with('panel_error', 'No se seleccionó ningún producto.');
        }

        foreach ($productos as $producto) {
            $images->delete($producto);
            $producto->delete();
        }

        $total = $productos->count();

        return back()->with('panel_ok', $total === 1
            ? 'Producto eliminado.'
            : "{$total} productos eliminados. Las ventas ya registradas no cambian.");
    }

    /** Sirve la foto del producto (cacheable). */
    public function image(Product $product): Response
    {
        abort_unless($product->hasImage(), 404);

        return EntregaDeArchivo::imagen(ProductImageStore::disk(), (string) $product->image_path);
    }
}
