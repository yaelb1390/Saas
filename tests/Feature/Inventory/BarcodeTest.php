<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\Inventory\Models\Category;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Repositories\Contracts\ProductRepositoryInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(CurrentCompany::class)->forget();
    $this->company = app(CompanyService::class)->create(new CreateCompanyData(name: 'Codigos Co'));
    app(CurrentCompany::class)->set($this->company->id);

    $this->owner = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Dueño',
        'email' => 'owner@codigos.test', 'password' => 'secret-password',
    ]), 'owner');
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function productPayload(array $overrides = []): array
{
    return array_merge([
        'sku' => 'SKU-1', 'name' => 'Producto', 'cost' => '10', 'price' => '20',
        'unit' => 'unidad', 'initial_stock' => '0',
    ], $overrides);
}

// ---------------------------------------------------------------- Alta y edición

it('el alta con una categoría seleccionada no revienta', function (): void {
    // Regresión: category_id llega como texto del formulario y, con tipado estricto, pasarlo al DTO
    // como ?int lanzaba TypeError (error 500) en cuanto el usuario elegía una categoría.
    $categoria = Category::create(['name' => 'General']);

    $this->actingAs($this->owner)
        ->post(route('panel.products.store'), productPayload(['category_id' => (string) $categoria->id]))
        ->assertRedirect()
        ->assertSessionHas('panel_ok');

    expect(Product::firstOrFail()->category_id)->toBe($categoria->id);
});

it('el alta guarda el código de barras', function (): void {
    // Antes de esta fase la columna existía pero no estaba validada, así que se descartaba siempre.
    $this->actingAs($this->owner)
        ->post(route('panel.products.store'), productPayload(['barcode' => '7501234567890']))
        ->assertRedirect();

    expect(Product::firstOrFail()->barcode)->toBe('7501234567890');
});

it('la edición cambia el código de barras', function (): void {
    $product = Product::create(['sku' => 'SKU-9', 'name' => 'Prod', 'barcode' => '111']);

    $this->actingAs($this->owner)
        ->put(route('panel.products.update', $product), productPayload([
            'sku' => 'SKU-9', 'name' => 'Prod', 'barcode' => '222',
        ]))
        ->assertRedirect();

    expect($product->refresh()->barcode)->toBe('222');
});

it('la edición ya no descarta la descripción en silencio', function (): void {
    // Regresión: description no estaba en las reglas, y update() solo aplica lo validado.
    $product = Product::create(['sku' => 'SKU-8', 'name' => 'Prod']);

    $this->actingAs($this->owner)
        ->put(route('panel.products.update', $product), productPayload([
            'sku' => 'SKU-8', 'name' => 'Prod', 'description' => 'Una descripción',
        ]))
        ->assertRedirect();

    expect($product->refresh()->description)->toBe('Una descripción');
});

// ---------------------------------------------------------------- Unicidad

it('dos productos sin código conviven (los nulos no chocan en el índice único)', function (): void {
    // El campo vacío del formulario llega como null (ConvertEmptyStringsToNull), no como ''.
    // Si llegara como cadena vacía, el segundo producto reventaría contra el índice único.
    $this->actingAs($this->owner)
        ->post(route('panel.products.store'), productPayload(['sku' => 'A1', 'barcode' => '']))
        ->assertRedirect();

    $this->actingAs($this->owner)
        ->post(route('panel.products.store'), productPayload(['sku' => 'A2', 'barcode' => '']))
        ->assertRedirect();

    expect(Product::count())->toBe(2)
        ->and(Product::whereNull('barcode')->count())->toBe(2);
});

it('tras borrar un producto se puede reutilizar su SKU y su código', function (): void {
    // El producto usa borrado suave; el SKU/código de un producto borrado debe quedar libre.
    $primero = Product::create(['sku' => 'REUSA', 'name' => 'Uno', 'barcode' => '7501112223334']);
    $primero->delete();

    $this->actingAs($this->owner)
        ->post(route('panel.products.store'), productPayload([
            'sku' => 'REUSA', 'barcode' => '7501112223334',
        ]))
        ->assertRedirect()
        ->assertSessionHasNoErrors()
        ->assertSessionHas('panel_ok');

    // Hay dos filas con ese SKU (una borrada, una activa), pero solo una activa.
    expect(Product::where('sku', 'REUSA')->count())->toBe(1)
        ->and(Product::withTrashed()->where('sku', 'REUSA')->count())->toBe(2);
});

it('rechaza un código repetido dentro de la misma empresa', function (): void {
    Product::create(['sku' => 'B1', 'name' => 'Uno', 'barcode' => '7501111111111']);

    $this->actingAs($this->owner)
        ->post(route('panel.products.store'), productPayload(['sku' => 'B2', 'barcode' => '7501111111111']))
        ->assertSessionHasErrors('barcode');

    expect(Product::count())->toBe(1);
});

it('reguardar un producto con su propio código no choca consigo mismo', function (): void {
    $product = Product::create(['sku' => 'C1', 'name' => 'Uno', 'barcode' => '750222']);

    $this->actingAs($this->owner)
        ->put(route('panel.products.update', $product), productPayload([
            'sku' => 'C1', 'name' => 'Uno editado', 'barcode' => '750222',
        ]))
        ->assertSessionHasNoErrors();

    expect($product->refresh()->name)->toBe('Uno editado');
});

// ---------------------------------------------------------------- Aislamiento multiempresa

it('dos empresas pueden usar el mismo código de barras', function (): void {
    // Es el mismo artículo de fábrica: el EAN es global, pero cada empresa lleva su catálogo.
    Product::create(['sku' => 'D1', 'name' => 'Coca-Cola', 'barcode' => '7501234567890']);

    $otra = app(CompanyService::class)->create(new CreateCompanyData(name: 'Otra Co'));
    app(CurrentCompany::class)->set($otra->id);

    $ajeno = Product::create(['sku' => 'D1', 'name' => 'Coca-Cola', 'barcode' => '7501234567890']);

    expect($ajeno->company_id)->toBe($otra->id)
        ->and(Product::withoutCompanyScope()->where('barcode', '7501234567890')->count())->toBe(2);
});

it('findByBarcode no ve el producto de otra empresa', function (): void {
    $otra = app(CompanyService::class)->create(new CreateCompanyData(name: 'Ajena Co'));
    app(CurrentCompany::class)->set($otra->id);
    Product::create(['sku' => 'E1', 'name' => 'Ajeno', 'barcode' => '9999999999999']);

    // De vuelta a la empresa propia: ese código no existe para nosotros.
    app(CurrentCompany::class)->set($this->company->id);

    expect(app(ProductRepositoryInterface::class)->findByBarcode('9999999999999'))->toBeNull();
});

it('findByBarcode encuentra el producto de la empresa activa', function (): void {
    $product = Product::create(['sku' => 'F1', 'name' => 'Propio', 'barcode' => '8888888888888']);

    expect(app(ProductRepositoryInterface::class)->findByBarcode('8888888888888')?->id)->toBe($product->id);
});

// ---------------------------------------------------------------- Búsqueda

it('el buscador del panel encuentra por código de barras', function (): void {
    Product::create(['sku' => 'G1', 'name' => 'Refresco', 'barcode' => '7509999000111']);
    Product::create(['sku' => 'G2', 'name' => 'Galleta', 'barcode' => '7500000111222']);

    $this->actingAs($this->owner)
        ->get(route('panel.products', ['q' => '7509999000111']))
        ->assertOk()
        ->assertSee('Refresco')
        ->assertDontSee('Galleta');
});
