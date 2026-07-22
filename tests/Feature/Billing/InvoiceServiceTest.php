<?php

declare(strict_types=1);

use App\Modules\Billing\Enums\NcfType;
use App\Modules\Billing\Exceptions\FiscalSequenceException;
use App\Modules\Billing\Exceptions\InvoiceException;
use App\Modules\Billing\Models\FiscalSequence;
use App\Modules\Billing\Services\InvoiceService;
use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Sales\DTOs\CreateSaleData;
use App\Modules\Sales\DTOs\SaleLineData;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Services\SaleService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(CurrentCompany::class)->forget();
    $this->company = app(CompanyService::class)->create(new CreateCompanyData(name: 'Billing Co'));
    app(CurrentCompany::class)->set($this->company->id);

    $this->warehouse = $this->company->warehouses()->where('is_default', true)->firstOrFail();
    $this->product = Product::create(['sku' => 'P1', 'name' => 'Prod', 'cost' => '10', 'price' => '100']);
    app(StockService::class)->increase($this->product, $this->warehouse, StockMovementType::Purchase, '100');
});

function makeSale(): Sale
{
    return app(SaleService::class)->complete(new CreateSaleData(
        warehouseId: test()->warehouse->id,
        lines: [new SaleLineData(productId: test()->product->id, quantity: '1', unitPrice: '100')],
        paid: '100000',
    ));
}

function activeSequence(array $overrides = []): FiscalSequence
{
    return FiscalSequence::create(array_merge([
        'type' => NcfType::Consumo,
        'next_number' => 1,
        'range_from' => 1,
        'range_to' => 1000,
        'number_length' => 8,
        'is_active' => true,
    ], $overrides));
}

it('emite una factura con NCF secuencial y avanza la secuencia', function (): void {
    $sale = makeSale();
    activeSequence();

    $invoice = app(InvoiceService::class)->issueForSale($sale, NcfType::Consumo);

    expect($invoice->ncf)->toBe('B0200000001')
        ->and($invoice->total)->toBe($sale->total)
        ->and($invoice->items)->toHaveCount(1);

    expect(FiscalSequence::first()->next_number)->toBe(2);
});

it('asigna NCF consecutivos a facturas distintas', function (): void {
    $s1 = makeSale();
    $s2 = makeSale();
    activeSequence();

    $i1 = app(InvoiceService::class)->issueForSale($s1);
    $i2 = app(InvoiceService::class)->issueForSale($s2);

    expect($i1->ncf)->toBe('B0200000001')
        ->and($i2->ncf)->toBe('B0200000002');
});

it('no permite facturar dos veces la misma venta', function (): void {
    $sale = makeSale();
    activeSequence();

    app(InvoiceService::class)->issueForSale($sale);

    expect(fn () => app(InvoiceService::class)->issueForSale($sale->refresh()))
        ->toThrow(InvoiceException::class);
});

it('falla si no hay secuencia activa para el tipo', function (): void {
    $sale = makeSale();

    expect(fn () => app(InvoiceService::class)->issueForSale($sale))
        ->toThrow(FiscalSequenceException::class);
});

it('falla si la secuencia está agotada', function (): void {
    $sale = makeSale();
    activeSequence(['next_number' => 6, 'range_from' => 1, 'range_to' => 5]);

    expect(fn () => app(InvoiceService::class)->issueForSale($sale))
        ->toThrow(FiscalSequenceException::class);
});

it('falla si la secuencia está vencida', function (): void {
    $sale = makeSale();
    activeSequence(['expires_at' => now()->subDay()]);

    expect(fn () => app(InvoiceService::class)->issueForSale($sale))
        ->toThrow(FiscalSequenceException::class);
});
