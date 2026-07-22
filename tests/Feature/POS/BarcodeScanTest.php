<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Cash\Models\CashRegister;
use App\Modules\Cash\Services\CashService;
use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(CurrentCompany::class)->forget();
    $this->company = app(CompanyService::class)->create(new CreateCompanyData(name: 'Scan Co'));
    app(CurrentCompany::class)->set($this->company->id);

    $this->warehouse = $this->company->warehouses()->where('is_default', true)->firstOrFail();

    $this->cajero = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Cajero',
        'email' => 'cajero@scan.test', 'password' => 'secret-password',
    ]), 'staff');

    $this->product = Product::create([
        'sku' => 'REF-001', 'name' => 'Refresco 2L',
        'barcode' => '7501234567890', 'cost' => '30', 'price' => '95',
    ]);
    app(StockService::class)->increase($this->product, $this->warehouse, StockMovementType::Purchase, '20');
});

// ---------------------------------------------------------------- Resolución del código

it('encuentra el producto por su código de barras', function (): void {
    $this->actingAs($this->cajero)
        ->getJson(route('panel.pos.lookup', ['codigo' => '7501234567890']))
        ->assertOk()
        ->assertJsonPath('found', true)
        ->assertJsonPath('product.id', $this->product->id)
        ->assertJsonPath('product.name', 'Refresco 2L')
        ->assertJsonPath('product.sellable', true);
});

it('encuentra también por SKU exacto', function (): void {
    // Muchos negocios imprimen su propio SKU como código en la etiqueta.
    $this->actingAs($this->cajero)
        ->getJson(route('panel.pos.lookup', ['codigo' => 'REF-001']))
        ->assertOk()
        ->assertJsonPath('found', true)
        ->assertJsonPath('product.id', $this->product->id);
});

it('devuelve el precio que tiene la base, no uno inventado', function (): void {
    $this->actingAs($this->cajero)
        ->getJson(route('panel.pos.lookup', ['codigo' => '7501234567890']))
        ->assertJsonPath('product.price', '95.00');
});

it('un código desconocido responde 200 y no encontrado', function (): void {
    // 200 y no 404: el terminal debe poder distinguir «no está en el catálogo» de «falló la
    // sesión o el permiso», que se ven como errores HTTP.
    $this->actingAs($this->cajero)
        ->getJson(route('panel.pos.lookup', ['codigo' => '0000000000000']))
        ->assertOk()
        ->assertJsonPath('found', false)
        ->assertJsonPath('product', null);
});

it('un código vacío no revienta', function (): void {
    $this->actingAs($this->cajero)
        ->getJson(route('panel.pos.lookup', ['codigo' => '']))
        ->assertOk()
        ->assertJsonPath('found', false);
});

// ---------------------------------------------------------------- Vendible o no

it('avisa de que el producto está agotado en vez de decir que no existe', function (): void {
    app(StockService::class)->decrease($this->product, $this->warehouse, StockMovementType::Sale, '20');

    $this->actingAs($this->cajero)
        ->getJson(route('panel.pos.lookup', ['codigo' => '7501234567890']))
        ->assertJsonPath('found', true)
        ->assertJsonPath('product.sellable', false)
        ->assertJsonPath('product.reason', 'no_stock');
});

it('avisa de que el producto está inactivo', function (): void {
    $this->product->update(['is_active' => false]);

    $this->actingAs($this->cajero)
        ->getJson(route('panel.pos.lookup', ['codigo' => '7501234567890']))
        ->assertJsonPath('found', true)
        ->assertJsonPath('product.sellable', false)
        ->assertJsonPath('product.reason', 'inactive');
});

it('un producto que no lleva control de stock se vende aunque no tenga existencia', function (): void {
    $servicio = Product::create([
        'sku' => 'SRV-1', 'name' => 'Instalación', 'barcode' => '5550001',
        'price' => '500', 'track_stock' => false,
    ]);

    $this->actingAs($this->cajero)
        ->getJson(route('panel.pos.lookup', ['codigo' => '5550001']))
        ->assertJsonPath('product.id', $servicio->id)
        ->assertJsonPath('product.sellable', true);
});

// ---------------------------------------------------------------- Aislamiento y permisos

it('no devuelve el producto de otra empresa aunque el código coincida', function (): void {
    $otra = app(CompanyService::class)->create(new CreateCompanyData(name: 'Ajena Co'));
    app(CurrentCompany::class)->set($otra->id);
    $ajeno = Product::create([
        'sku' => 'AJ-1', 'name' => 'Producto Ajeno', 'barcode' => '4440001', 'price' => '10',
    ]);
    app(CurrentCompany::class)->set($this->company->id);

    $this->actingAs($this->cajero)
        ->getJson(route('panel.pos.lookup', ['codigo' => '4440001']))
        ->assertOk()
        ->assertJsonPath('found', false);

    expect($ajeno->company_id)->toBe($otra->id);
});

it('un usuario sin permiso de operar el POS no puede consultar códigos', function (): void {
    $sinRol = User::create([
        'company_id' => $this->company->id, 'name' => 'Sin Rol',
        'email' => 'sinrol@scan.test', 'password' => 'secret-password',
    ]);

    $this->actingAs($sinRol)
        ->getJson(route('panel.pos.lookup', ['codigo' => '7501234567890']))
        ->assertForbidden();
});

it('el terminal ofrece el campo de escaneo', function (): void {
    app(CashService::class)->open(
        CashRegister::create(['name' => 'Caja 1']), '0'
    );

    $this->actingAs($this->cajero)
        ->get(route('panel.pos'))
        ->assertOk()
        ->assertSee('pos-scan', false)
        // La cámara es la alternativa al lector de pistola.
        ->assertSee('Usar cámara', false)
        ->assertSee('visorCamara', false)
        // El selector de cliente debe seguir ahí (lo usa el portal del cliente).
        ->assertSee('name="customer_id"', false);
});
