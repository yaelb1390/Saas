<?php

declare(strict_types=1);

use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\Finance\Enums\MovementType;
use App\Modules\Finance\Models\Account;
use App\Modules\Finance\Models\FinancialMovement;
use App\Modules\Finance\Services\FinanceService;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Sales\DTOs\CreateSaleData;
use App\Modules\Sales\DTOs\SaleLineData;
use App\Modules\Sales\Services\SaleService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(CurrentCompany::class)->forget();
    $this->company = app(CompanyService::class)->create(new CreateCompanyData(name: 'Fin Co'));
    app(CurrentCompany::class)->set($this->company->id);

    $this->account = Account::where('is_default', true)->firstOrFail();
    $this->finance = app(FinanceService::class);
});

it('provisiona la cuenta por defecto al crear la empresa', function (): void {
    expect($this->account->name)->toBe('Caja General')
        ->and($this->account->balance)->toBe('0.00');
});

it('registra ingresos y egresos actualizando el balance', function (): void {
    $this->finance->record($this->account, MovementType::Income, '1000', 'Ingreso');
    $this->finance->record($this->account, MovementType::Expense, '300', 'Gasto');

    expect($this->account->refresh()->balance)->toBe('700.00');
});

it('registra el ingreso automáticamente al completar una venta', function (): void {
    $warehouse = $this->company->warehouses()->where('is_default', true)->firstOrFail();
    $product = Product::create(['sku' => 'P1', 'name' => 'Prod', 'cost' => '10', 'price' => '500']);
    app(StockService::class)->increase($product, $warehouse, StockMovementType::Purchase, '10');

    $sale = app(SaleService::class)->complete(new CreateSaleData(
        warehouseId: $warehouse->id,
        lines: [new SaleLineData(productId: $product->id, quantity: '1', unitPrice: '500')],
        paid: '500',
    ));

    expect($this->account->refresh()->balance)->toBe('500.00');

    $movement = FinancialMovement::where('type', MovementType::Income)->firstOrFail();
    expect($movement->reference_id)->toBe($sale->id);
});
