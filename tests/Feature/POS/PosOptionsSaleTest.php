<?php

declare(strict_types=1);

use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\HR\Models\Employee;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Stock;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Sales\DTOs\CreateSaleData;
use App\Modules\Sales\DTOs\SaleLineData;
use App\Modules\Sales\Services\SaleService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(CurrentCompany::class)->forget();
    $this->company = app(CompanyService::class)->create(new CreateCompanyData(name: 'Opciones Co'));
    app(CurrentCompany::class)->set($this->company->id);

    $this->warehouse = $this->company->warehouses()->where('is_default', true)->firstOrFail();
    $this->product = Product::create(['sku' => 'P1', 'name' => 'Prod', 'cost' => '10', 'price' => '100']);
    app(StockService::class)->increase($this->product, $this->warehouse, StockMovementType::Purchase, '100');
});

it('el descuento de línea reduce el importe', function (): void {
    $sale = app(SaleService::class)->complete(new CreateSaleData(
        warehouseId: $this->warehouse->id,
        lines: [new SaleLineData($this->product->id, '1', '100', discount: '20')],
        paid: '1000',
    ));

    $item = $sale->items->first();
    expect($item->discount)->toBe('20.00')
        ->and($item->subtotal)->toBe('80.00')
        ->and($sale->total)->toBe('80.00');
});

it('el descuento global del ticket reduce la base', function (): void {
    $sale = app(SaleService::class)->complete(new CreateSaleData(
        warehouseId: $this->warehouse->id,
        lines: [new SaleLineData($this->product->id, '2', '100')],
        paid: '1000',
        discountTotal: '50',
    ));

    expect($sale->discount_total)->toBe('50.00')
        ->and($sale->total)->toBe('150.00');
});

it('la propina se suma al total pero NO se grava con ITBIS', function (): void {
    $sale = app(SaleService::class)->complete(new CreateSaleData(
        warehouseId: $this->warehouse->id,
        lines: [new SaleLineData($this->product->id, '1', '100')],
        paid: '1000',
        tip: '30',
    ));

    // ITBIS se calcula solo sobre los 100 (base 84.75 + 15.25); la propina va aparte.
    expect($sale->tax)->toBe('15.25')
        ->and($sale->tip)->toBe('30.00')
        ->and($sale->total)->toBe('130.00'); // 100 + 30
});

it('guarda empleado, serie y nota por línea y el empleado de la venta', function (): void {
    $emp = Employee::create(['name' => 'María', 'is_active' => true]);

    $sale = app(SaleService::class)->complete(new CreateSaleData(
        warehouseId: $this->warehouse->id,
        lines: [new SaleLineData($this->product->id, '1', '100',
            note: 'sellado', serial: 'IMEI-123', employeeId: $emp->id)],
        paid: '1000',
        employeeId: $emp->id,
    ));

    $item = $sale->items->first();
    expect($item->note)->toBe('sellado')
        ->and($item->serial)->toBe('IMEI-123')
        ->and($item->employee_id)->toBe($emp->id)
        ->and($sale->employee_id)->toBe($emp->id);
});

it('acepta cantidades decimales', function (): void {
    $sale = app(SaleService::class)->complete(new CreateSaleData(
        warehouseId: $this->warehouse->id,
        lines: [new SaleLineData($this->product->id, '2.5', '100')],
        paid: '1000',
    ));

    $item = $sale->items->first();
    expect($item->quantity)->toBe('2.500')
        ->and($item->subtotal)->toBe('250.00');
});

it('un servicio (track_stock false) no descuenta stock', function (): void {
    $service = Product::create(['sku' => 'SV1', 'name' => 'Manicure', 'price' => '500', 'track_stock' => false]);

    $sale = app(SaleService::class)->complete(new CreateSaleData(
        warehouseId: $this->warehouse->id,
        lines: [new SaleLineData($service->id, '1', '500')],
        paid: '1000',
    ));

    expect($sale->total)->toBe('500.00')
        // No hay fila de stock para el servicio: no se movió inventario.
        ->and(Stock::where('product_id', $service->id)->exists())->toBeFalse();
});
