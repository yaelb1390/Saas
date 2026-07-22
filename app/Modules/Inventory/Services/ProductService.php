<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Modules\Core\Models\Warehouse;
use App\Modules\Inventory\DTOs\CreateProductData;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Repositories\Contracts\ProductRepositoryInterface;
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
    ) {}

    public function create(
        CreateProductData $data,
        ?Warehouse $initialWarehouse = null,
        string $initialQuantity = '0',
    ): Product {
        return DB::transaction(function () use ($data, $initialWarehouse, $initialQuantity): Product {
            $product = $this->products->create($data->toAttributes());

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
