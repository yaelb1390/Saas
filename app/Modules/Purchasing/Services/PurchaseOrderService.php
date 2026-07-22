<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Services;

use App\Modules\Core\Models\Warehouse;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Purchasing\DTOs\CreatePurchaseOrderData;
use App\Modules\Purchasing\Enums\PurchaseOrderStatus;
use App\Modules\Purchasing\Events\PurchaseOrderReceived;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\Supplier;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Lógica de negocio de órdenes de compra. Al recibir una orden, delega en StockService la
 * entrada de inventario, de modo que la comunicación entre módulos ocurre servicio a servicio
 * (Purchasing depende de Inventory), nunca tocando sus tablas directamente.
 */
final class PurchaseOrderService
{
    public function __construct(private readonly StockService $stock) {}

    public function create(CreatePurchaseOrderData $data): PurchaseOrder
    {
        return DB::transaction(function () use ($data): PurchaseOrder {
            $supplier = Supplier::findOrFail($data->supplierId);
            $warehouse = Warehouse::findOrFail($data->warehouseId);
            $companyId = (int) $supplier->company_id;

            $order = new PurchaseOrder([
                'company_id' => $companyId,
                'supplier_id' => $supplier->id,
                'warehouse_id' => $warehouse->id,
                'code' => $this->nextCode($companyId),
                'status' => PurchaseOrderStatus::Ordered,
                'subtotal' => $data->subtotal(),
                'tax' => $data->tax,
                'total' => $data->total(),
                'notes' => $data->notes,
                'ordered_at' => now(),
                'user_id' => auth()->id(),
            ]);
            $order->save();

            foreach ($data->lines as $line) {
                $order->items()->create([
                    'company_id' => $companyId,
                    'product_id' => $line->productId,
                    'quantity' => $line->quantity,
                    'unit_cost' => $line->unitCost,
                    'subtotal' => $line->subtotal(),
                ]);
            }

            return $order->load('items');
        });
    }

    /**
     * Recibe la orden: incrementa el stock del almacén destino y marca la orden como recibida.
     */
    public function receive(PurchaseOrder $order): PurchaseOrder
    {
        if (! $order->status->canBeReceived()) {
            throw new DomainException("La orden {$order->code} no puede recibirse en estado {$order->status->value}.");
        }

        return DB::transaction(function () use ($order): PurchaseOrder {
            $order->load('items.product', 'warehouse');

            foreach ($order->items as $item) {
                $this->stock->increase(
                    $item->product,
                    $order->warehouse,
                    StockMovementType::Purchase,
                    (string) $item->quantity,
                    ['reference' => $order, 'notes' => "Recepción {$order->code}"],
                );

                $item->update(['received_quantity' => $item->quantity]);
            }

            $order->update([
                'status' => PurchaseOrderStatus::Received,
                'received_at' => now(),
            ]);

            PurchaseOrderReceived::dispatch($order);

            return $order;
        });
    }

    private function nextCode(int $companyId): string
    {
        $count = PurchaseOrder::withoutCompanyScope()
            ->where('company_id', $companyId)
            ->count();

        return 'PO-'.str_pad((string) ($count + 1), 6, '0', STR_PAD_LEFT);
    }
}
