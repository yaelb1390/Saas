<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Api\StoreSaleRequest;
use App\Http\Resources\SaleResource;
use App\Modules\Core\Models\Warehouse;
use App\Modules\Inventory\Exceptions\InsufficientStockException;
use App\Modules\Sales\DTOs\CreateSaleData;
use App\Modules\Sales\DTOs\SaleLineData;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Services\SaleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

/**
 * Ventas vía API v1. La creación reutiliza SaleService: el ITBIS se calcula igual que en el POS
 * y el stock se descuenta en la misma transacción. Aislado por la empresa del token.
 */
final class SaleController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $sales = Sale::query()
            ->latest()
            ->paginate((int) $request->integer('per_page', 25));

        return SaleResource::collection($sales);
    }

    public function show(Sale $sale): SaleResource
    {
        return new SaleResource($sale->load('items'));
    }

    public function store(StoreSaleRequest $request, SaleService $sales): JsonResponse
    {
        $data = $request->validated();

        $warehouse = Warehouse::query()->where('is_default', true)->orderBy('id')->firstOrFail();

        $lines = array_map(
            fn (array $line): SaleLineData => new SaleLineData(
                productId: (int) $line['product_id'],
                quantity: (string) $line['quantity'],
                unitPrice: (string) $line['unit_price'],
            ),
            $data['lines'],
        );

        try {
            $sale = $sales->complete(new CreateSaleData(
                warehouseId: (int) $warehouse->id,
                lines: $lines,
                paymentMethod: $data['payment_method'] ?? 'cash',
                customerName: $data['customer_name'] ?? null,
            ));
        } catch (InsufficientStockException $e) {
            // Regla de negocio (no hay existencias): 422, no un error de servidor.
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return (new SaleResource($sale->load('items')))->response()->setStatusCode(201);
    }
}
