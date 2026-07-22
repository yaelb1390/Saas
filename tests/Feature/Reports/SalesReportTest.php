<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Reports\Services\ReportService;
use App\Modules\Sales\DTOs\CreateSaleData;
use App\Modules\Sales\DTOs\SaleLineData;
use App\Modules\Sales\Services\SaleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(CurrentCompany::class)->forget();
    $this->company = app(CompanyService::class)->create(new CreateCompanyData(name: 'Report Co'));
    $this->user = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Ger', 'email' => 'ger@rep.test', 'password' => 'secret-password',
    ]));
    app(CurrentCompany::class)->set($this->company->id);

    $warehouse = $this->company->warehouses()->where('is_default', true)->firstOrFail();
    $product = Product::create(['sku' => 'RP', 'name' => 'Producto Rep', 'cost' => '10', 'price' => '50']);
    app(StockService::class)->increase($product, $warehouse, StockMovementType::Purchase, '100');

    foreach ([2, 1] as $qty) {
        app(SaleService::class)->complete(new CreateSaleData(
            warehouseId: $warehouse->id,
            lines: [new SaleLineData(productId: $product->id, quantity: (string) $qty, unitPrice: '50')],
            paid: '1000',
        ));
    }
});

it('calcula el reporte de ventas del período', function (): void {
    $report = app(ReportService::class)->salesReport(Carbon::now()->subDay(), Carbon::now());

    expect($report['count'])->toBe(2)
        ->and($report['total'])->toBe('150.00')
        ->and($report['avg_ticket'])->toBe('75.00')
        ->and($report['top_products'][0]['name'])->toBe('Producto Rep')
        ->and($report['top_products'][0]['total'])->toBe('150.00');
});

it('la página de reportes muestra el reporte del período', function (): void {
    $this->actingAs($this->user)
        ->get(route('panel.reports'))
        ->assertOk()
        ->assertSee('Ventas por período')
        ->assertSee('Producto Rep');
});
