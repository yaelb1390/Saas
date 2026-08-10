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
use App\Modules\Inventory\Support\CategoryIcons;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(CurrentCompany::class)->forget();
    $this->company = app(CompanyService::class)->create(new CreateCompanyData(name: 'Barra Co'));
    app(CurrentCompany::class)->set($this->company->id);

    $this->owner = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Dueño',
        'email' => 'owner@barra.test', 'password' => 'secret-password',
    ]), 'owner');

    // Sin caja abierta la pantalla solo pinta el formulario de apertura, no la barra.
    $register = CashRegister::create(['name' => 'Caja', 'code' => 'C1', 'is_active' => true]);
    app(CashService::class)->open($register, '1000', $this->owner->id);

    $this->helados = Category::create([
        'name' => 'Helados', 'slug' => 'helados', 'icon' => '🍦', 'is_active' => true,
    ]);
});

/**
 * Nombres de las categorías que el terminal ofrece en la barra.
 *
 * Se leen del endpoint y no del HTML: la barra se pinta en el cliente a partir de estos datos, así
 * que buscar el emoji en el marcado no probaría nada (`@js()` lo escapa a secuencias unicode) y
 * ataría el test a cómo se dibuja en vez de a qué se ofrece.
 *
 * @return array<int, string>
 */
function categoriasDelTerminal(): array
{
    $cats = test()->actingAs(test()->owner)
        ->getJson(route('panel.quick-pos.catalog'))->assertOk()->json('categories');

    return array_column($cats, 'name');
}

it('una categoría sin productos no aparece en la barra', function (): void {
    // Recién creada y vacía: mostrarla sería ofrecer un filtro que no lleva a ninguna parte.
    expect(categoriasDelTerminal())->not->toContain('Helados');
});

it('la categoría aparece en cuanto se le añade un producto', function (): void {
    Product::create([
        'sku' => 'CONO', 'name' => 'Cono', 'cost' => '1', 'price' => '80',
        'category_id' => $this->helados->id,
    ]);

    expect(categoriasDelTerminal())->toContain('Helados');
});

it('la categoría desaparece al borrar su último producto', function (): void {
    $cono = Product::create([
        'sku' => 'CONO', 'name' => 'Cono', 'cost' => '1', 'price' => '80',
        'category_id' => $this->helados->id,
    ]);

    expect(categoriasDelTerminal())->toContain('Helados');

    $cono->delete();

    expect(categoriasDelTerminal())->not->toContain('Helados');
});

it('sigue apareciendo mientras le quede al menos un producto', function (): void {
    $uno = Product::create([
        'sku' => 'CONO', 'name' => 'Cono', 'cost' => '1', 'price' => '80',
        'category_id' => $this->helados->id,
    ]);
    Product::create([
        'sku' => 'COPA', 'name' => 'Copa', 'cost' => '1', 'price' => '90',
        'category_id' => $this->helados->id,
    ]);

    $uno->delete();

    expect(categoriasDelTerminal())->toContain('Helados');
});

it('desactivar el único producto también esconde la categoría', function (): void {
    $cono = Product::create([
        'sku' => 'CONO', 'name' => 'Cono', 'cost' => '1', 'price' => '80',
        'category_id' => $this->helados->id,
    ]);

    // Descatalogar sin borrar: el producto ya no se vende, así que su categoría tampoco se ofrece.
    $cono->update(['is_active' => false]);

    expect(categoriasDelTerminal())->not->toContain('Helados');
});

it('desactivar la categoría la esconde aunque tenga productos', function (): void {
    Product::create([
        'sku' => 'CONO', 'name' => 'Cono', 'cost' => '1', 'price' => '80',
        'category_id' => $this->helados->id,
    ]);

    $this->helados->update(['is_active' => false]);

    expect(categoriasDelTerminal())->not->toContain('Helados');
});

it('el endpoint del catálogo devuelve las categorías al día, sin recargar la pantalla', function (): void {
    // Terminal abierto: pide el catálogo y la barra todavía está vacía.
    $antes = $this->actingAs($this->owner)
        ->getJson(route('panel.quick-pos.catalog'))->assertOk()->json('categories');

    expect($antes)->toBe([]);

    // Alguien da de alta un producto desde otra pantalla.
    Product::create([
        'sku' => 'CONO', 'name' => 'Cono', 'cost' => '1', 'price' => '80',
        'category_id' => $this->helados->id,
    ]);

    $despues = $this->actingAs($this->owner)
        ->getJson(route('panel.quick-pos.catalog'))->assertOk()->json('categories');

    expect($despues)->toHaveCount(1)
        ->and($despues[0]['name'])->toBe('Helados')
        ->and($despues[0]['icon'])->toBe('🍦');
});

it('el icono genérico llega ya resuelto al terminal', function (): void {
    $sinIcono = Category::create(['name' => 'Varios', 'slug' => 'varios', 'is_active' => true]);
    Product::create([
        'sku' => 'X', 'name' => 'X', 'cost' => '1', 'price' => '10', 'category_id' => $sinIcono->id,
    ]);

    // El cliente no tiene por qué conocer cuál es el genérico: se decide en el servidor.
    $cats = $this->actingAs($this->owner)
        ->getJson(route('panel.quick-pos.catalog'))->assertOk()->json('categories');

    expect($cats[0]['icon'])->toBe(CategoryIcons::DEFAULT);
});

it('un producto sin categoría no hace aparecer ninguna entrada', function (): void {
    Product::create(['sku' => 'SUELTO', 'name' => 'Suelto', 'cost' => '1', 'price' => '50']);

    // Se vende desde «Todo», pero no inventa una categoría en la barra.
    expect(categoriasDelTerminal())->not->toContain('Helados');
});
