<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\Inventory\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(CurrentCompany::class)->forget();
    $this->company = app(CompanyService::class)->create(new CreateCompanyData(name: 'SKU Co'));
    app(CurrentCompany::class)->set($this->company->id);

    $this->owner = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Dueño',
        'email' => 'owner@sku.test', 'password' => 'secret-password',
    ]), 'owner');
});

it('genera el SKU cuando el formulario lo deja vacío', function (): void {
    $this->actingAs($this->owner)
        ->post(route('panel.products.store'), ['name' => 'Cono de vainilla', 'price' => '100'])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(Product::firstWhere('name', 'Cono de vainilla')->sku)->toBe('PROD-000001');
});

it('numera de forma correlativa', function (): void {
    foreach (['Uno', 'Dos', 'Tres'] as $nombre) {
        $this->actingAs($this->owner)->post(route('panel.products.store'), ['name' => $nombre, 'price' => '10']);
    }

    expect(Product::orderBy('id')->pluck('sku')->all())
        ->toBe(['PROD-000001', 'PROD-000002', 'PROD-000003']);
});

it('respeta el SKU que se escriba a mano', function (): void {
    $this->actingAs($this->owner)
        ->post(route('panel.products.store'), ['name' => 'Con código propio', 'sku' => 'MI-CODIGO', 'price' => '10'])
        ->assertSessionHasNoErrors();

    expect(Product::firstWhere('name', 'Con código propio')->sku)->toBe('MI-CODIGO');
});

it('no reutiliza el número de un producto borrado', function (): void {
    // El índice único de la base SÍ ve las filas borradas en suave: reutilizar su número reventaría
    // el INSERT con un error de servidor.
    $this->actingAs($this->owner)->post(route('panel.products.store'), ['name' => 'Uno', 'price' => '10']);
    Product::firstWhere('sku', 'PROD-000001')->delete();

    $this->actingAs($this->owner)
        ->post(route('panel.products.store'), ['name' => 'Dos', 'price' => '10'])
        ->assertSessionHasNoErrors();

    expect(Product::firstWhere('name', 'Dos')->sku)->toBe('PROD-000002');
});

it('un código escrito a mano que no sigue el formato no descoloca la numeración', function (): void {
    Product::create(['sku' => 'PROD-CONO', 'name' => 'Manual', 'cost' => '1', 'price' => '10']);

    $this->actingAs($this->owner)->post(route('panel.products.store'), ['name' => 'Auto', 'price' => '10']);

    expect(Product::firstWhere('name', 'Auto')->sku)->toBe('PROD-000001');
});

it('continúa desde el mayor existente, no desde el número de productos', function (): void {
    Product::create(['sku' => 'PROD-000050', 'name' => 'Antiguo', 'cost' => '1', 'price' => '10']);

    $this->actingAs($this->owner)->post(route('panel.products.store'), ['name' => 'Nuevo', 'price' => '10']);

    expect(Product::firstWhere('name', 'Nuevo')->sku)->toBe('PROD-000051');
});

it('cada empresa lleva su propia numeración', function (): void {
    $this->actingAs($this->owner)->post(route('panel.products.store'), ['name' => 'Mío', 'price' => '10']);

    $otra = app(CompanyService::class)->create(new CreateCompanyData(name: 'Otra SKU'));
    app(CurrentCompany::class)->set($otra->id);
    $ajeno = withRole(User::create([
        'company_id' => $otra->id, 'name' => 'Ajeno',
        'email' => 'owner@otrasku.test', 'password' => 'secret-password',
    ]), 'owner');

    $this->actingAs($ajeno)->post(route('panel.products.store'), ['name' => 'Suyo', 'price' => '10']);

    // Las dos empresas arrancan en PROD-000001 sin pisarse.
    expect(Product::withoutCompanyScope()->where('company_id', $otra->id)->first()->sku)
        ->toBe('PROD-000001');
});

it('la API también genera el SKU si no se envía', function (): void {
    $token = $this->owner->createToken('test')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/products', ['name' => 'Desde la API', 'price' => '10'])
        ->assertCreated();

    expect(Product::firstWhere('name', 'Desde la API')->sku)->toBe('PROD-000001');
});
