<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\ProductResource;
use App\Modules\Core\Models\Warehouse;
use App\Modules\Inventory\DTOs\CreateProductData;
use App\Modules\Inventory\Http\Requests\StoreProductRequest;
use App\Modules\Inventory\Http\Requests\UpdateProductRequest;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Services\ProductService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

/**
 * Productos vía API v1. Reutiliza los mismos Form Requests y servicios que el panel, de modo que
 * las reglas de negocio y validación son idénticas por web y por API. El CompanyScope aísla todo
 * por la empresa del token.
 */
final class ProductController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $products = Product::query()
            ->with('stock')
            ->when($request->query('q'), fn ($query, $q) => $query->where(
                fn ($sub) => $sub->whereLike('sku', "%{$q}%")
                    ->orWhereLike('name', "%{$q}%")
                    ->orWhereLike('barcode', "%{$q}%")
            ))
            ->latest()
            ->paginate((int) $request->integer('per_page', 25));

        return ProductResource::collection($products);
    }

    public function show(Product $product): ProductResource
    {
        return new ProductResource($product->load('stock'));
    }

    public function store(StoreProductRequest $request, ProductService $products): JsonResponse
    {
        $data = CreateProductData::fromArray($request->validated());
        $warehouse = Warehouse::query()->where('is_default', true)->orderBy('id')->first();

        $product = $products->create($data, $warehouse, (string) ($request->input('initial_stock') ?? '0'));

        return (new ProductResource($product->load('stock')))
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdateProductRequest $request, Product $product): ProductResource
    {
        $product->update($request->validated());

        return new ProductResource($product->refresh()->load('stock'));
    }

    public function destroy(Product $product): JsonResponse
    {
        $product->delete();

        return response()->json(null, 204);
    }
}
