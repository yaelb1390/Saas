<?php

declare(strict_types=1);

use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Stock;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Purchasing\DTOs\CreatePurchaseOrderData;
use App\Modules\Purchasing\DTOs\PurchaseOrderLineData;
use App\Modules\Purchasing\Enums\PurchaseOrderStatus;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Services\PurchaseOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(CurrentCompany::class)->forget();
    $this->company = app(CompanyService::class)->create(new CreateCompanyData(name: 'Compras Co'));
    app(CurrentCompany::class)->set($this->company->id);

    $this->warehouse = $this->company->warehouses()->where('is_default', true)->firstOrFail();
    $this->product = Product::create(['sku' => 'P1', 'name' => 'Prod 1', 'cost' => '10', 'price' => '20']);
    $this->supplier = Supplier::create(['name' => 'Proveedor 1']);
    $this->service = app(PurchaseOrderService::class);
});

function makeOrder(): PurchaseOrder
{
    return test()->service->create(new CreatePurchaseOrderData(
        supplierId: test()->supplier->id,
        warehouseId: test()->warehouse->id,
        lines: [
            new PurchaseOrderLineData(productId: test()->product->id, quantity: '8', unitCost: '12.50'),
        ],
    ));
}

it('crea la orden calculando los totales', function (): void {
    $order = makeOrder();

    expect($order->status)->toBe(PurchaseOrderStatus::Ordered)
        ->and($order->code)->toBe('PO-000001')
        ->and($order->subtotal)->toBe('100.00')
        ->and($order->total)->toBe('100.00')
        ->and($order->items)->toHaveCount(1);
});

it('al recibir la orden incrementa el stock y la marca como recibida', function (): void {
    $order = makeOrder();

    $this->service->receive($order);
    $order->refresh();

    expect($order->status)->toBe(PurchaseOrderStatus::Received)
        ->and($order->received_at)->not->toBeNull();

    $stock = Stock::where('product_id', $this->product->id)
        ->where('warehouse_id', $this->warehouse->id)
        ->first();
    expect($stock->quantity)->toBe('8.000');

    expect($order->items()->first()->received_quantity)->toBe('8.000');

    $movement = StockMovement::where('product_id', $this->product->id)
        ->where('type', StockMovementType::Purchase)
        ->first();
    expect($movement->reference_id)->toBe($order->id)
        ->and($movement->reference_type)->toBe(PurchaseOrder::class);
});

it('no permite recibir dos veces la misma orden', function (): void {
    $order = makeOrder();
    $this->service->receive($order);

    expect(fn () => $this->service->receive($order->refresh()))
        ->toThrow(DomainException::class);
});
