<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Reports\Services\AlertService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(CurrentCompany::class)->forget();
    $this->company = app(CompanyService::class)->create(new CreateCompanyData(name: 'Alert Co'));
    $this->user = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Admin', 'email' => 'admin@alert.test', 'password' => 'secret-password',
    ]));
    app(CurrentCompany::class)->set($this->company->id);
    $this->warehouse = $this->company->warehouses()->where('is_default', true)->firstOrFail();
});

it('genera una alerta de stock bajo', function (): void {
    $product = Product::create(['sku' => 'LS', 'name' => 'Casi agotado', 'price' => '10']);
    app(StockService::class)->increase($product, $this->warehouse, StockMovementType::Purchase, '2');

    $alerts = app(AlertService::class)->forCurrentCompany();

    expect(collect($alerts)->firstWhere('key', 'low_stock'))->not->toBeNull()
        ->and(collect($alerts)->firstWhere('key', 'low_stock')['count'])->toBe(1);
});

it('no genera alertas cuando todo está en orden', function (): void {
    $product = Product::create(['sku' => 'OK', 'name' => 'Con stock', 'price' => '10']);
    app(StockService::class)->increase($product, $this->warehouse, StockMovementType::Purchase, '100');

    expect(app(AlertService::class)->forCurrentCompany())->toBe([]);
});

it('la campana muestra las alertas en la barra superior', function (): void {
    $this->withoutVite();
    $product = Product::create(['sku' => 'LS2', 'name' => 'Bajo', 'price' => '10']);
    app(StockService::class)->increase($product, $this->warehouse, StockMovementType::Purchase, '1');

    $this->actingAs($this->user)
        ->get('/dashboard')
        ->assertOk()
        ->assertSee('stock bajo');
});
