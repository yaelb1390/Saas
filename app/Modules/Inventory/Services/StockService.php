<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Modules\Core\Models\Warehouse;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Events\StockMovementRegistered;
use App\Modules\Inventory\Exceptions\InsufficientStockException;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Stock;
use App\Modules\Inventory\Models\StockMovement;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Único punto de entrada para modificar existencias. Toda variación de stock pasa por aquí,
 * de modo que siempre queda registrada en el kardex (stock_movements) con el saldo antes y
 * después. Usa transacción + bloqueo de fila para ser seguro ante concurrencia, y aritmética
 * decimal exacta (bcmath) para evitar errores de coma flotante.
 */
final class StockService
{
    private const SCALE = 3;

    /**
     * Registra un movimiento con cantidad con signo (+ entrada, - salida) y actualiza el saldo.
     *
     * @param  array<string, mixed>  $context  claves opcionales: reference (Model), notes (string), userId (int)
     */
    public function register(
        Product $product,
        Warehouse $warehouse,
        StockMovementType $type,
        string $quantity,
        array $context = [],
    ): StockMovement {
        return DB::transaction(function () use ($product, $warehouse, $type, $quantity, $context): StockMovement {
            $stock = Stock::query()
                ->where('product_id', $product->id)
                ->where('warehouse_id', $warehouse->id)
                ->lockForUpdate()
                ->firstOrNew();

            $before = $stock->exists ? (string) $stock->quantity : '0';
            $after = bcadd($before, $quantity, self::SCALE);

            if (bccomp($after, '0', self::SCALE) < 0) {
                throw InsufficientStockException::for(
                    $product->id,
                    $warehouse->id,
                    $before,
                    $quantity,
                );
            }

            $stock->forceFill([
                'company_id' => $product->company_id,
                'product_id' => $product->id,
                'warehouse_id' => $warehouse->id,
                'quantity' => $after,
            ])->save();

            $reference = $context['reference'] ?? null;

            $movement = new StockMovement([
                'company_id' => $product->company_id,
                'product_id' => $product->id,
                'warehouse_id' => $warehouse->id,
                'type' => $type,
                'quantity' => $quantity,
                'quantity_before' => (string) $before,
                'quantity_after' => $after,
                'user_id' => $context['userId'] ?? auth()->id(),
                'notes' => $context['notes'] ?? null,
            ]);

            if ($reference instanceof Model) {
                $movement->reference()->associate($reference);
            }

            $movement->save();

            StockMovementRegistered::dispatch($movement);

            return $movement;
        });
    }

    /**
     * Entrada de stock (cantidad positiva).
     *
     * @param  array<string, mixed>  $context
     */
    public function increase(Product $product, Warehouse $warehouse, StockMovementType $type, string $quantity, array $context = []): StockMovement
    {
        return $this->register($product, $warehouse, $type, $this->positive($quantity), $context);
    }

    /**
     * Salida de stock (cantidad negativa).
     *
     * @param  array<string, mixed>  $context
     */
    public function decrease(Product $product, Warehouse $warehouse, StockMovementType $type, string $quantity, array $context = []): StockMovement
    {
        return $this->register($product, $warehouse, $type, '-'.$this->positive($quantity), $context);
    }

    private function positive(string $quantity): string
    {
        return bccomp($quantity, '0', self::SCALE) < 0
            ? bcmul($quantity, '-1', self::SCALE)
            : $quantity;
    }
}
