<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Cash\Models\CashRegister;
use App\Modules\Cash\Services\CashService;
use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\Inventory\Models\Category;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Repositories\Contracts\ProductRepositoryInterface;
use App\Modules\Inventory\Support\CategoryIcons;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(CurrentCompany::class)->forget();
    $this->company = app(CompanyService::class)->create(new CreateCompanyData(name: 'Heladería Co'));
    app(CurrentCompany::class)->set($this->company->id);

    $this->owner = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Dueño',
        'email' => 'owner@heladeria.test', 'password' => 'secret-password',
    ]), 'owner');
});

it('crea una categoría y le deriva el slug del nombre', function (): void {
    $this->actingAs($this->owner)
        ->post(route('panel.categories.store'), ['name' => 'Helados de Agua'])
        ->assertRedirect();

    $category = Category::firstWhere('name', 'Helados de Agua');

    expect($category)->not->toBeNull()
        ->and($category->slug)->toBe('helados-de-agua')
        ->and($category->is_active)->toBeTrue()
        ->and($category->company_id)->toBe($this->company->id);
});

it('rechaza dos categorías con el mismo nombre en la misma empresa', function (): void {
    $this->actingAs($this->owner)->post(route('panel.categories.store'), ['name' => 'Bebidas']);

    $this->actingAs($this->owner)
        ->post(route('panel.categories.store'), ['name' => 'Bebidas'])
        ->assertSessionHasErrors('name');

    expect(Category::where('name', 'Bebidas')->count())->toBe(1);
});

it('permite el mismo nombre de categoría en dos empresas distintas', function (): void {
    $this->actingAs($this->owner)->post(route('panel.categories.store'), ['name' => 'Combos']);

    $otra = app(CompanyService::class)->create(new CreateCompanyData(name: 'Otra Co'));
    app(CurrentCompany::class)->set($otra->id);

    $ajeno = withRole(User::create([
        'company_id' => $otra->id, 'name' => 'Ajeno',
        'email' => 'owner@otra.test', 'password' => 'secret-password',
    ]), 'owner');

    $this->actingAs($ajeno)
        ->post(route('panel.categories.store'), ['name' => 'Combos'])
        ->assertSessionHasNoErrors();
});

it('no deja que una categoría sea su propia categoría padre', function (): void {
    $category = Category::create(['name' => 'Postres', 'slug' => 'postres', 'is_active' => true]);

    $this->actingAs($this->owner)
        ->put(route('panel.categories.update', $category), [
            'name' => 'Postres', 'parent_id' => $category->id,
        ])
        ->assertSessionHasErrors('parent_id');
});

it('al borrar una categoría sus productos quedan sin categoría, no se borran', function (): void {
    $category = Category::create(['name' => 'Paletas', 'slug' => 'paletas', 'is_active' => true]);
    $product = Product::create([
        'sku' => 'PAL-1', 'name' => 'Paleta de coco', 'cost' => '10', 'price' => '50',
        'category_id' => $category->id,
    ]);

    $this->actingAs($this->owner)
        ->delete(route('panel.categories.destroy', $category))
        ->assertRedirect();

    expect(Product::find($product->id))->not->toBeNull()
        ->and(Product::find($product->id)->category_id)->toBeNull();
});

it('no permite tocar una categoría de otra empresa', function (): void {
    $otra = app(CompanyService::class)->create(new CreateCompanyData(name: 'Ajena Co'));
    app(CurrentCompany::class)->set($otra->id);
    $ajena = Category::create(['name' => 'Ajena', 'slug' => 'ajena', 'is_active' => true]);

    app(CurrentCompany::class)->set($this->company->id);

    $this->actingAs($this->owner)
        ->put(route('panel.categories.update', $ajena), ['name' => 'Secuestrada'])
        ->assertNotFound();

    $this->actingAs($this->owner)
        ->delete(route('panel.categories.destroy', $ajena))
        ->assertNotFound();
});

it('el catálogo devuelve solo los productos activos de la categoría pedida', function (): void {
    $helados = Category::create(['name' => 'Helados', 'slug' => 'helados', 'is_active' => true]);
    $bebidas = Category::create(['name' => 'Bebidas', 'slug' => 'bebidas', 'is_active' => true]);

    Product::create(['sku' => 'H1', 'name' => 'Cono', 'cost' => '10', 'price' => '80', 'category_id' => $helados->id]);
    Product::create(['sku' => 'H2', 'name' => 'Copa', 'cost' => '10', 'price' => '90', 'category_id' => $helados->id]);
    Product::create(['sku' => 'B1', 'name' => 'Refresco', 'cost' => '10', 'price' => '60', 'category_id' => $bebidas->id]);
    Product::create([
        'sku' => 'H3', 'name' => 'Descatalogado', 'cost' => '10', 'price' => '80',
        'category_id' => $helados->id, 'is_active' => false,
    ]);

    $catalogo = app(ProductRepositoryInterface::class)->catalog($helados->id);

    expect($catalogo->getCollection()->pluck('sku')->all())->toBe(['H1', 'H2']);
});

it('el catálogo sin categoría devuelve todo el surtido activo, paginado', function (): void {
    foreach (range(1, 5) as $i) {
        Product::create(['sku' => "P{$i}", 'name' => "Producto {$i}", 'cost' => '1', 'price' => '10']);
    }

    $catalogo = app(ProductRepositoryInterface::class)->catalog(null, 2);

    expect($catalogo->total())->toBe(5)
        ->and($catalogo->getCollection()->count())->toBe(2);
});

it('la categoría viaja en el payload que consume el punto de venta', function (): void {
    $helados = Category::create(['name' => 'Helados', 'slug' => 'helados', 'is_active' => true]);
    Product::create([
        'sku' => 'CONO', 'name' => 'Cono doble', 'cost' => '10', 'price' => '120',
        'category_id' => $helados->id,
    ]);

    $payload = $this->actingAs($this->owner)
        ->getJson(route('panel.pos.search', ['q' => 'Cono']))
        ->assertOk()
        ->json('results.0');

    expect($payload['category_id'])->toBe($helados->id);
});

it('guarda el icono elegido para la barra lateral del punto de venta', function (): void {
    $this->actingAs($this->owner)
        ->post(route('panel.categories.store'), ['name' => 'Helados', 'icon' => '🍦'])
        ->assertSessionHasNoErrors();

    expect(Category::firstWhere('name', 'Helados')->icon)->toBe('🍦');
});

it('rechaza un icono que no esté en la lista ofrecida', function (): void {
    // Lista cerrada: si no, cualquiera pega ahí un texto y descuadra la columna de categorías.
    $this->actingAs($this->owner)
        ->post(route('panel.categories.store'), ['name' => 'Rara', 'icon' => 'texto libre'])
        ->assertSessionHasErrors('icon');
});

it('una categoría sin icono cae al genérico', function (): void {
    $sinIcono = Category::create(['name' => 'Varios', 'slug' => 'varios', 'is_active' => true]);

    expect(CategoryIcons::resolve($sinIcono->icon))->toBe(CategoryIcons::DEFAULT);
});

it('el punto de venta recibe las categorías con su icono', function (): void {
    // Sin caja abierta la pantalla solo pinta el formulario de apertura, no el terminal.
    $register = CashRegister::create(['name' => 'Caja', 'code' => 'C1', 'is_active' => true]);
    app(CashService::class)->open($register, '1000', $this->owner->id);

    $helados = Category::create([
        'name' => 'Helados', 'slug' => 'helados', 'icon' => '🍦', 'is_active' => true,
    ]);
    Product::create([
        'sku' => 'CONO', 'name' => 'Cono', 'cost' => '1', 'price' => '80', 'category_id' => $helados->id,
    ]);

    // Se mira el dato que el terminal usa para pintar la barra, no el marcado: la barra se dibuja
    // en el cliente y `@js()` escapa el emoji a secuencias unicode dentro del HTML.
    $cats = $this->actingAs($this->owner)
        ->getJson(route('panel.quick-pos.catalog'))->assertOk()->json('categories');

    expect($cats[0]['icon'])->toBe('🍦');
});

it('un usuario sin el permiso de categorías no entra a la pantalla', function (): void {
    $cajero = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Cajero',
        'email' => 'cajero@heladeria.test', 'password' => 'secret-password',
    ]), 'staff');

    $this->actingAs($cajero)->get(route('panel.categories'))->assertForbidden();
});
