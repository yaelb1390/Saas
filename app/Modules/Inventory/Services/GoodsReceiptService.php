<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Modules\Core\Models\Warehouse;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\Inventory\DTOs\CreateGoodsReceiptData;
use App\Modules\Inventory\DTOs\GoodsReceiptLineData;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Exceptions\StockException;
use App\Modules\Inventory\Models\GoodsReceipt;
use App\Modules\Inventory\Models\Product;
use App\Modules\Purchasing\Models\Supplier;
use Illuminate\Support\Facades\DB;

/**
 * Registra una remesa completa: el documento, sus líneas y la existencia que suma al almacén.
 *
 * TODO OCURRE EN UNA TRANSACCIÓN, y esa es la razón de que este servicio exista. Antes cada producto
 * se metía por separado: si el almacenista se distraía a mitad de una remesa de treinta artículos,
 * quedaban quince dentro y quince fuera, sin nada que dijera cuáles. Ahora entra la remesa entera o
 * no entra ninguna.
 *
 * El movimiento de existencia se marca como COMPRA y apunta al documento. Antes se marcaba como
 * «ajuste» porque no había a qué apuntar y un movimiento de compra huérfano ensucia cualquier informe;
 * con el documento delante, eso deja de ser un problema y el kardex por fin dice de dónde vino cada
 * unidad.
 */
final class GoodsReceiptService
{
    private const SCALE_CANTIDAD = 3;

    private const SCALE_DINERO = 2;

    public function __construct(private readonly StockService $stock) {}

    public function create(CreateGoodsReceiptData $data): GoodsReceipt
    {
        return DB::transaction(function () use ($data): GoodsReceipt {
            $companyId = app(CurrentCompany::class)->id() ?? 0;

            if ($data->lines === []) {
                throw StockException::remesaVacia();
            }

            $almacen = $this->resolveWarehouse($data->warehouseId, $companyId);
            $proveedor = $this->resolveSupplier($data->supplierId, $companyId);

            $remesa = new GoodsReceipt([
                'company_id' => $companyId,
                'code' => $this->nextCode($companyId),
                'warehouse_id' => $almacen->id,
                'supplier_id' => $proveedor?->id,
                // Snapshot del nombre: si mañana se borra el proveedor, la remesa sigue diciendo de
                // quién vino.
                'supplier_name' => $proveedor?->name ?? $data->supplierName,
                'reference' => $data->reference,
                'received_at' => $data->receivedAt,
                'notes' => $data->notes,
                'user_id' => auth()->id(),
            ]);
            $remesa->save();

            foreach ($data->lines as $linea) {
                $this->registrarLinea($remesa, $almacen, $linea, $companyId);
            }

            return $remesa->load('lines.product');
        });
    }

    private function registrarLinea(
        GoodsReceipt $remesa,
        Warehouse $almacen,
        GoodsReceiptLineData $linea,
        int $companyId,
    ): void {
        $cantidad = bcadd($linea->quantity === '' ? '0' : $linea->quantity, '0', self::SCALE_CANTIDAD);

        if (bccomp($cantidad, '0', self::SCALE_CANTIDAD) <= 0) {
            throw StockException::invalidQuantity();
        }

        $producto = $this->resolveProduct($linea->productId, $companyId);
        $costo = $linea->unitCost === null
            ? null
            : bcadd($linea->unitCost, '0', self::SCALE_DINERO);

        $costoAnterior = (string) $producto->cost;

        // El costo del producto solo cambia si la persona lo pidió Y escribió uno distinto. Escribir
        // el mismo número no es una decisión de cambiar nada, así que no ensucia el histórico.
        $seActualiza = $linea->updateCost
            && $costo !== null
            && bccomp($costo, $costoAnterior, self::SCALE_DINERO) !== 0;

        if ($seActualiza) {
            $producto->update(['cost' => $costo]);
        }

        $remesa->lines()->create([
            'company_id' => $companyId,
            'product_id' => $producto->id,
            'quantity' => $cantidad,
            'unit_cost' => $costo,
            'cost_updated' => $seActualiza,
            'previous_cost' => $seActualiza ? $costoAnterior : null,
        ]);

        // Los productos sin control de existencia (un servicio) no descuentan al vender, así que
        // tampoco suman al recibir: apuntarles existencia sería inventar un almacén que no llevan.
        if (! $producto->track_stock) {
            return;
        }

        $this->stock->increase(
            $producto,
            $almacen,
            StockMovementType::Purchase,
            $cantidad,
            [
                'reference' => $remesa,
                'notes' => "Entrada {$remesa->code}".($remesa->reference !== null ? " · {$remesa->reference}" : ''),
            ],
        );
    }

    private function resolveWarehouse(int $id, int $companyId): Warehouse
    {
        /** @var Warehouse|null $almacen */
        $almacen = Warehouse::withoutCompanyScope()->where('company_id', $companyId)->whereKey($id)->first();

        if ($almacen === null) {
            throw StockException::warehouseNotInCompany();
        }

        return $almacen;
    }

    private function resolveProduct(int $id, int $companyId): Product
    {
        /** @var Product|null $producto */
        $producto = Product::withoutCompanyScope()->where('company_id', $companyId)->whereKey($id)->first();

        if ($producto === null) {
            throw StockException::productNotInCompany();
        }

        return $producto;
    }

    private function resolveSupplier(?int $id, int $companyId): ?Supplier
    {
        if ($id === null) {
            return null;
        }

        return Supplier::withoutCompanyScope()->where('company_id', $companyId)->whereKey($id)->first();
    }

    private function nextCode(int $companyId): string
    {
        // `withTrashed`: las remesas se archivan, y sin contarlas la siguiente reutilizaría un código
        // ya usado y chocaría contra el índice único.
        $cuantas = GoodsReceipt::withoutCompanyScope()
            ->withTrashed()
            ->where('company_id', $companyId)
            ->count();

        return 'ENT-'.str_pad((string) ($cuantas + 1), 6, '0', STR_PAD_LEFT);
    }
}
