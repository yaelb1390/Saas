<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\Inventory\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * Borrado múltiple de productos.
 *
 * Dos modos: los marcados en pantalla, o todos los que coinciden con la búsqueda —lo que hace falta
 * para vaciar un catálogo de cientos sin recorrer veinte páginas—.
 *
 * Lo que más se cuida aquí es lo que NO debe pasar: borrar más de lo seleccionado, tocar productos
 * de otra empresa, o destruir el histórico de ventas.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(CurrentCompany::class)->forget();
    $this->company = app(CompanyService::class)->create(new CreateCompanyData(name: 'Heladería'));
    app(CurrentCompany::class)->set($this->company->id);

    $this->owner = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Dueña',
        'email' => 'duena@heladeria.test', 'password' => 'secret-password',
    ]), 'owner');

    $this->helado = Product::create(['name' => 'Helado', 'price' => '100', 'sku' => 'H-1']);
    $this->agua = Product::create(['name' => 'Agua', 'price' => '50', 'sku' => 'A-1']);
    $this->cono = Product::create(['name' => 'Cono', 'price' => '30', 'sku' => 'C-1']);
});

it('elimina solo los productos marcados', function (): void {
    $this->actingAs($this->owner)
        ->delete(route('panel.products.bulk-destroy'), ['ids' => [$this->helado->id, $this->agua->id]])
        ->assertRedirect();

    expect(Product::count())->toBe(1)
        ->and(Product::first()->name)->toBe('Cono');
});

it('elimina todos los que coinciden con la búsqueda, no solo la página', function (): void {
    // El modo que permite vaciar un catálogo grande de una vez.
    $this->actingAs($this->owner)
        ->delete(route('panel.products.bulk-destroy'), ['todos' => 1, 'q' => 'o'])
        ->assertRedirect();

    // «Helado» y «Cono» contienen la «o»; «Agua» no.
    expect(Product::pluck('name')->all())->toBe(['Agua']);
});

it('con «todos» y sin búsqueda vacía el catálogo entero', function (): void {
    $this->actingAs($this->owner)
        ->delete(route('panel.products.bulk-destroy'), ['todos' => 1])
        ->assertRedirect();

    expect(Product::count())->toBe(0);
});

it('archiva en vez de destruir, para no romper las ventas ya hechas', function (): void {
    // `sale_items` apunta a `products` con RESTRICT: un borrado real fallaría en cuanto un producto
    // tuviera una venta, y con él se perdería el histórico. El borrado lógico lo saca del
    // inventario y deja intactos los recibos.
    $this->actingAs($this->owner)
        ->delete(route('panel.products.bulk-destroy'), ['ids' => [$this->helado->id]]);

    expect(Product::find($this->helado->id))->toBeNull()
        ->and(Product::withTrashed()->find($this->helado->id))->not->toBeNull();
});

it('no toca los productos de otra empresa aunque se envíen sus ids', function (): void {
    $otra = app(CompanyService::class)->create(new CreateCompanyData(name: 'Otra'));
    app(CurrentCompany::class)->set($otra->id);
    $ajeno = Product::create(['name' => 'Ajeno', 'price' => '10', 'sku' => 'X-1']);
    app(CurrentCompany::class)->set($this->company->id);

    $this->actingAs($this->owner)
        ->delete(route('panel.products.bulk-destroy'), ['ids' => [$this->helado->id, $ajeno->id]]);

    app(CurrentCompany::class)->set($otra->id);
    expect(Product::find($ajeno->id))->not->toBeNull();
});

it('avisa si no se seleccionó nada, en vez de fingir que borró', function (): void {
    $this->actingAs($this->owner)
        ->delete(route('panel.products.bulk-destroy'), ['ids' => []])
        ->assertRedirect();

    expect(session('panel_error'))->toContain('No se seleccionó ningún producto')
        ->and(Product::count())->toBe(3);
});

it('un cajero no puede borrar productos en lote', function (): void {
    $cajero = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Cajero',
        'email' => 'cajero@heladeria.test', 'password' => 'secret-password',
    ]), 'staff');

    $this->actingAs($cajero)
        ->delete(route('panel.products.bulk-destroy'), ['ids' => [$this->helado->id]])
        ->assertForbidden();

    expect(Product::count())->toBe(3);
});

it('el listado y el borrado masivo filtran igual', function (): void {
    // Es la garantía de que «seleccionar los que coinciden» se lleva exactamente lo que hay en
    // pantalla. Si cada uno filtrara por su cuenta, se borraría un conjunto distinto del visible.
    $vistos = $this->actingAs($this->owner)->get(route('panel.products', ['q' => 'o']))
        ->assertOk()->viewData('products')->pluck('id')->sort()->values()->all();

    $aBorrar = Product::query()->filtered('o', false)->pluck('id')->sort()->values()->all();

    expect($aBorrar)->toBe($vistos);
});

it('la pantalla ofrece la casilla de selección', function (): void {
    $this->actingAs($this->owner)->get(route('panel.products'))
        ->assertOk()
        ->assertSee('alternarPagina', false)
        ->assertSee('Seleccionar todos', false);
});
