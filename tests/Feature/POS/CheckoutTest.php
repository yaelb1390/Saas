<?php

declare(strict_types=1);

use App\Modules\Cash\Models\CashMovement;
use App\Modules\Cash\Models\CashRegister;
use App\Modules\Cash\Services\CashService;
use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Exceptions\InsufficientStockException;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Stock;
use App\Modules\Inventory\Services\StockService;
use App\Modules\POS\Services\CheckoutService;
use App\Modules\Sales\DTOs\CreateSaleData;
use App\Modules\Sales\DTOs\SaleLineData;
use App\Modules\Sales\Enums\SaleStatus;
use App\Modules\Sales\Models\Sale;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(CurrentCompany::class)->forget();
    $this->company = app(CompanyService::class)->create(new CreateCompanyData(name: 'POS Co'));
    app(CurrentCompany::class)->set($this->company->id);

    $this->warehouse = $this->company->warehouses()->where('is_default', true)->firstOrFail();
    $this->product = Product::create(['sku' => 'P1', 'name' => 'Prod', 'cost' => '10', 'price' => '50']);
    app(StockService::class)->increase($this->product, $this->warehouse, StockMovementType::Purchase, '100');

    $this->register = CashRegister::create(['name' => 'Caja 1']);
    $this->session = app(CashService::class)->open($this->register, '0');
    $this->checkout = app(CheckoutService::class);
});

it('cobra una venta: descuenta stock y registra el efectivo en caja', function (): void {
    $sale = $this->checkout->checkout($this->session, new CreateSaleData(
        warehouseId: $this->warehouse->id,
        lines: [new SaleLineData(productId: $this->product->id, quantity: '3', unitPrice: '50')],
        paid: '200',
    ));

    expect($sale->status)->toBe(SaleStatus::Completed)
        ->and($sale->total)->toBe('150.00')
        ->and($sale->change)->toBe('50.00')
        ->and($sale->cash_session_id)->toBe($this->session->id);

    $stock = Stock::where('product_id', $this->product->id)
        ->where('warehouse_id', $this->warehouse->id)
        ->first();
    expect($stock->quantity)->toBe('97.000');

    $movement = CashMovement::where('cash_session_id', $this->session->id)->firstOrFail();
    expect($movement->amount)->toBe('150.00')
        ->and($movement->reference_id)->toBe($sale->id)
        ->and($movement->reference_type)->toBe(Sale::class);
});

it('rechaza la venta si no hay stock suficiente y revierte todo', function (): void {
    expect(fn () => $this->checkout->checkout($this->session, new CreateSaleData(
        warehouseId: $this->warehouse->id,
        lines: [new SaleLineData(productId: $this->product->id, quantity: '500', unitPrice: '50')],
        paid: '99999',
    )))->toThrow(InsufficientStockException::class);

    expect(Sale::count())->toBe(0)
        ->and(CashMovement::count())->toBe(0);

    $stock = Stock::where('product_id', $this->product->id)->firstOrFail();
    expect($stock->quantity)->toBe('100.000');
});

it('el cierre de caja incluye el cobro de la venta', function (): void {
    $this->checkout->checkout($this->session, new CreateSaleData(
        warehouseId: $this->warehouse->id,
        lines: [new SaleLineData(productId: $this->product->id, quantity: '2', unitPrice: '50')],
        paid: '100',
    ));

    app(CashService::class)->close($this->session->refresh(), '100');

    expect($this->session->refresh()->expected_amount)->toBe('100.00')
        ->and($this->session->difference)->toBe('0.00');
});
