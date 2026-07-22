<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers;

use App\Modules\Core\Models\Warehouse;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Http\Requests\StoreStockEntryRequest;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Inventory\Support\ProductLookupPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Entrada de mercancía al almacén.
 *
 * Delgado a propósito: el caso de uso ya es StockService::increase() (transacción, bloqueo de fila,
 * kardex con saldo antes/después y evento). Envolverlo en un servicio propio solo añadiría una capa
 * vacía y, peor, una segunda puerta al stock: StockService debe seguir siendo la única.
 */
final class StockController extends Controller
{
    /**
     * Resuelve el código escaneado (o tecleado). Endpoint propio del inventario, no el del POS:
     * reutilizar aquel ataría el almacén al módulo «pos» y al permiso «pos.operate», y se puede
     * inventariar sin vender. Ambos comparten el presenter, así que la forma es la misma.
     */
    public function lookup(Request $request, ProductLookupPresenter $lookup): JsonResponse
    {
        return response()->json($lookup->payload((string) $request->query('codigo', '')));
    }

    public function store(StoreStockEntryRequest $request, StockService $stock): RedirectResponse
    {
        $data = $request->validated();

        // El Form Request ya garantizó que ambos son de la empresa activa.
        $product = Product::findOrFail($data['product_id']);
        $warehouse = Warehouse::findOrFail($data['warehouse_id']);

        // Adjustment y no Purchase: «Purchase» significa entrada respaldada por una orden de compra
        // y la emite PurchaseOrderService::receive() con su referencia. Una entrada rápida no tiene
        // orden, proveedor ni costo; tiparla como compra ensuciaría el kardex y cualquier informe
        // de compras con movimientos huérfanos imposibles de conciliar.
        $movement = $stock->increase(
            $product,
            $warehouse,
            StockMovementType::Adjustment,
            (string) $data['quantity'],
            ['notes' => $data['notes'] ?? 'Entrada de mercancía'],
        );

        return back()->with(
            'panel_ok',
            "Entrada registrada: {$product->name} · {$movement->quantity_before} → {$movement->quantity_after}",
        );
    }
}
