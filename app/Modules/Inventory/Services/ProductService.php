<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Modules\Core\Models\Warehouse;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\Inventory\DTOs\CreateProductData;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Repositories\Contracts\ProductRepositoryInterface;
use App\Modules\Inventory\Support\SkuGenerator;
use Illuminate\Support\Facades\DB;

/**
 * Lógica de negocio de productos. Crea el producto y, opcionalmente, su inventario inicial en
 * un almacén (delegando en StockService para que quede registrado en el kardex).
 */
final class ProductService
{
    public function __construct(
        private readonly ProductRepositoryInterface $products,
        private readonly StockService $stock,
        private readonly SkuGenerator $skus,
    ) {}

    public function create(
        CreateProductData $data,
        ?Warehouse $initialWarehouse = null,
        string $initialQuantity = '0',
    ): Product {
        return DB::transaction(function () use ($data, $initialWarehouse, $initialQuantity): Product {
            $attributes = $data->toAttributes();

            // Punto único donde se decide el SKU: por aquí pasan tanto el alta desde el panel como
            // la de la API, así que ninguna de las dos puede olvidarse de generarlo.
            if (! filled($attributes['sku'])) {
                $attributes['sku'] = $this->skus->next((int) app(CurrentCompany::class)->id());
            }

            $product = $this->products->create($attributes);

            if ($initialWarehouse !== null && bccomp($initialQuantity, '0', 3) > 0) {
                $this->stock->increase(
                    $product,
                    $initialWarehouse,
                    StockMovementType::Initial,
                    $initialQuantity,
                    ['notes' => 'Inventario inicial'],
                );
            }

            return $product;
        });
    }
}
