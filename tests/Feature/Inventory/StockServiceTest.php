<?php

declare(strict_types=1);

use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Exceptions\InsufficientStockException;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Stock;
use App\Modules\Inventory\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(CurrentCompany::class)->forget();
    $this->company = app(CompanyService::class)->create(new CreateCompanyData(name: 'Inv Co'));
    app(CurrentCompany::class)->set($this->company->id);

    $this->warehouse = $this->company->warehouses()->where('is_default', true)->firstOrFail();
    $this->product = Product::create(['sku' => 'P1', 'name' => 'Prod 1', 'cost' => '10', 'price' => '20']);
    $this->stockService = app(StockService::class);
});

it('una entrada aumenta el stock y registra el movimiento con saldo antes/después', function (): void {
    $movement = $this->stockService->increase($this->product, $this->warehouse, StockMovementType::Purchase, '10');

    expect($movement->quantity_before)->toBe('0.000')
        ->and($movement->quantity_after)->toBe('10.000')
        ->and($movement->quantity)->toBe('10.000');

    $stock = Stock::where('product_id', $this->product->id)
        ->where('warehouse_id', $this->warehouse->id)
        ->first();

    expect($stock->quantity)->toBe('10.000');
});

it('una salida disminuye el stock', function (): void {
    $this->stockService->increase($this->product, $this->warehouse, StockMovementType::Purchase, '10');
    $movement = $this->stockService->decrease($this->product, $this->warehouse, StockMovementType::Sale, '4');

    expect($movement->quantity)->toBe('-4.000')
        ->and($movement->quantity_after)->toBe('6.000');
});

it('impide dejar el stock en negativo', function (): void {
    expect(fn () => $this->stockService->decrease($this->product, $this->warehouse, StockMovementType::Sale, '5'))
        ->toThrow(InsufficientStockException::class);

    // El saldo no debe haberse alterado.
    expect(Stock::where('product_id', $this->product->id)->count())->toBe(0);
});

it('aísla productos y stock por empresa', function (): void {
    $this->stockService->increase($this->product, $this->warehouse, StockMovementType::Purchase, '10');

    $other = app(CompanyService::class)->create(new CreateCompanyData(name: 'Otra Co'));
    app(CurrentCompany::class)->set($other->id);

    expect(Product::count())->toBe(0)
        ->and(Stock::count())->toBe(0);
});
