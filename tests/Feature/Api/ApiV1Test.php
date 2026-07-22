<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(CurrentCompany::class)->forget();
    $this->company = app(CompanyService::class)->create(new CreateCompanyData(name: 'API Co'));
    app(CurrentCompany::class)->set($this->company->id);

    $this->owner = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Dueño',
        'email' => 'owner@api.test', 'password' => 'secret-password',
    ]), 'owner');

    $this->warehouse = $this->company->warehouses()->where('is_default', true)->firstOrFail();
    $this->product = Product::create(['sku' => 'API-1', 'name' => 'Producto API', 'cost' => '10', 'price' => '118']);
    app(StockService::class)->increase($this->product, $this->warehouse, StockMovementType::Purchase, '50');
});

// ---------------------------------------------------------------- Autenticación

it('emite un token con credenciales válidas', function (): void {
    $this->postJson(route('api.login'), [
        'email' => 'owner@api.test', 'password' => 'secret-password', 'device_name' => 'test',
    ])
        ->assertCreated()
        ->assertJsonStructure(['token', 'user' => ['id', 'email', 'company_id']]);
});

it('rechaza credenciales inválidas y cuentas desactivadas', function (): void {
    $this->postJson(route('api.login'), [
        'email' => 'owner@api.test', 'password' => 'mal', 'device_name' => 'test',
    ])->assertStatus(422);

    $this->owner->update(['is_active' => false]);
    $this->postJson(route('api.login'), [
        'email' => 'owner@api.test', 'password' => 'secret-password', 'device_name' => 'test',
    ])->assertStatus(422);
});

it('bloquea las rutas protegidas sin token', function (): void {
    $this->getJson(route('api.products.index'))->assertUnauthorized();
});

// ---------------------------------------------------------------- Productos

it('lista y crea productos con el token', function (): void {
    Sanctum::actingAs($this->owner);

    $this->getJson(route('api.products.index'))
        ->assertOk()
        ->assertJsonPath('data.0.sku', 'API-1');

    $this->postJson(route('api.products.store'), [
        'sku' => 'API-2', 'name' => 'Nuevo', 'price' => '236', 'initial_stock' => '5',
    ])
        ->assertCreated()
        ->assertJsonPath('data.sku', 'API-2')
        ->assertJsonPath('data.stock', '5');
});

it('valida el alta de producto (422)', function (): void {
    Sanctum::actingAs($this->owner);

    $this->postJson(route('api.products.store'), ['name' => 'Sin SKU'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('sku');
});

// ---------------------------------------------------------------- Ventas

it('crea una venta y calcula el ITBIS igual que el POS', function (): void {
    Sanctum::actingAs($this->owner);

    $this->postJson(route('api.sales.store'), [
        'lines' => [['product_id' => $this->product->id, 'quantity' => '1', 'unit_price' => '118']],
    ])
        ->assertCreated()
        // 118 con ITBIS incluido → base 100 + ITBIS 18.
        ->assertJsonPath('data.subtotal', '100.00')
        ->assertJsonPath('data.tax', '18.00')
        ->assertJsonPath('data.total', '118.00');
});

it('devuelve 422 si no hay stock suficiente', function (): void {
    Sanctum::actingAs($this->owner);

    $this->postJson(route('api.sales.store'), [
        'lines' => [['product_id' => $this->product->id, 'quantity' => '999', 'unit_price' => '118']],
    ])->assertStatus(422);
});

// ---------------------------------------------------------------- Permisos y aislamiento

it('un cajero no puede crear productos por la API (403)', function (): void {
    $cajero = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Cajero',
        'email' => 'cajero@api.test', 'password' => 'secret-password',
    ]), 'staff');

    Sanctum::actingAs($cajero);

    $this->postJson(route('api.products.store'), ['sku' => 'X', 'name' => 'Y', 'price' => '1'])
        ->assertForbidden();
});

it('el token solo ve los datos de su empresa', function (): void {
    // Producto en otra empresa.
    $other = app(CompanyService::class)->create(new CreateCompanyData(name: 'Otra API'));
    app(CurrentCompany::class)->set($other->id);
    $foreign = Product::create(['sku' => 'OTRA-1', 'name' => 'Ajeno', 'price' => '10']);
    app(CurrentCompany::class)->set($this->company->id);

    Sanctum::actingAs($this->owner);

    // El listado no lo incluye…
    $skus = collect($this->getJson(route('api.products.index'))->json('data'))->pluck('sku');
    expect($skus)->not->toContain('OTRA-1');

    // …y acceder por id da 404 (route model binding aislado por empresa).
    $this->getJson(route('api.products.show', $foreign->id))->assertNotFound();
});

it('un módulo fuera del plan bloquea su API (403)', function (): void {
    $this->company->update(['modules' => ['pos']]); // sin inventario

    Sanctum::actingAs($this->owner);

    $this->getJson(route('api.products.index'))->assertForbidden();
});
