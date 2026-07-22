<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Sales\DTOs\CreateSaleData;
use App\Modules\Sales\DTOs\SaleLineData;
use App\Modules\Sales\Services\SaleService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeCompletedSale(): object
{
    $warehouse = test()->company->warehouses()->where('is_default', true)->firstOrFail();
    $product = Product::create(['sku' => 'R1', 'name' => 'Producto Recibo', 'cost' => '10', 'price' => '50']);
    app(StockService::class)->increase($product, $warehouse, StockMovementType::Purchase, '10');

    return app(SaleService::class)->complete(new CreateSaleData(
        warehouseId: $warehouse->id,
        lines: [new SaleLineData(productId: $product->id, quantity: '2', unitPrice: '50')],
        paid: '200',
        customerName: 'Cliente Recibo',
    ));
}

beforeEach(function (): void {
    app(CurrentCompany::class)->forget();
    $this->company = app(CompanyService::class)->create(new CreateCompanyData(name: 'Recibo Co'));
    $this->user = withRole(User::create([
        'company_id' => $this->company->id,
        'name' => 'Cajero',
        'email' => 'cajero@recibo.test',
        'password' => 'secret-password',
    ]));
    app(CurrentCompany::class)->set($this->company->id);
});

it('muestra el recibo imprimible de una venta', function (): void {
    $sale = makeCompletedSale();

    $this->actingAs($this->user)
        ->get(route('panel.sales.receipt', $sale))
        ->assertOk()
        ->assertSee('RECIBO DE VENTA')
        ->assertSee($sale->code)
        ->assertSee('Producto Recibo')
        ->assertSee('Cliente Recibo');
});

it('genera el recibo en PDF de 80mm', function (): void {
    $sale = makeCompletedSale();

    $response = $this->actingAs($this->user)
        ->get(route('panel.sales.receipt.pdf', $sale))
        ->assertOk();

    expect($response->headers->get('content-type'))->toContain('application/pdf')
        ->and($response->getContent())->toStartWith('%PDF');
});

it('descarga el recibo PDF como adjunto', function (): void {
    $sale = makeCompletedSale();

    $this->actingAs($this->user)
        ->get(route('panel.sales.receipt.pdf', ['sale' => $sale, 'mode' => 'descargar']))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf')
        ->assertDownload('recibo-'.$sale->code.'.pdf');
});

it('no genera el PDF de una venta de otra empresa', function (): void {
    $sale = makeCompletedSale();

    $other = app(CompanyService::class)->create(new CreateCompanyData(name: 'Otra PDF'));
    $intruder = withRole(User::create([
        'company_id' => $other->id, 'name' => 'Intruso', 'email' => 'x-pdf@x.test', 'password' => 'secret-password',
    ]));

    $this->actingAs($intruder)
        ->get(route('panel.sales.receipt.pdf', $sale->id))
        ->assertNotFound();
});

it('no muestra el recibo de una venta de otra empresa', function (): void {
    $sale = makeCompletedSale();

    $other = app(CompanyService::class)->create(new CreateCompanyData(name: 'Otra'));
    $intruder = withRole(User::create([
        'company_id' => $other->id, 'name' => 'Intruso', 'email' => 'x@x.test', 'password' => 'secret-password',
    ]));

    $this->actingAs($intruder)
        ->get(route('panel.sales.receipt', $sale->id))
        ->assertNotFound();
});
