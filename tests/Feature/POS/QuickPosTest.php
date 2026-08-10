<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Cash\Models\CashRegister;
use App\Modules\Cash\Services\CashService;
use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Models\Warehouse;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Models\Category;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Sales\Models\Sale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(CurrentCompany::class)->forget();
    $this->company = app(CompanyService::class)->create(new CreateCompanyData(name: 'Heladería Rápida'));
    app(CurrentCompany::class)->set($this->company->id);

    $this->owner = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Dueño',
        'email' => 'owner@rapida.test', 'password' => 'secret-password',
    ]), 'owner');

    $this->warehouse = Warehouse::query()->where('is_default', true)->orderBy('id')->first();

    $register = CashRegister::create(['name' => 'Caja Principal', 'code' => 'CAJA-01', 'is_active' => true]);
    $this->session = app(CashService::class)->open($register, '1000', $this->owner->id);
});

/** Crea un producto vendible con existencia. */
function heladoConStock(string $sku, string $name, string $price, ?int $categoryId = null): Product
{
    $product = Product::create([
        'sku' => $sku, 'name' => $name, 'cost' => '10', 'price' => $price, 'category_id' => $categoryId,
    ]);

    app(StockService::class)->increase(
        $product,
        Warehouse::query()->where('is_default', true)->orderBy('id')->first(),
        StockMovementType::Initial,
        '50',
    );

    return $product;
}

it('la pantalla pide abrir caja si no hay ninguna abierta', function (): void {
    app(CashService::class)->close($this->session, '1000');

    $this->actingAs($this->owner)->get(route('panel.quick-pos.index'))
        ->assertOk()
        ->assertSee('Caja cerrada');
});

it('muestra la rejilla y los chips de las categorías con productos', function (): void {
    $helados = Category::create(['name' => 'Helados', 'slug' => 'helados', 'is_active' => true]);
    Category::create(['name' => 'Vacía', 'slug' => 'vacia', 'is_active' => true]);
    heladoConStock('CONO', 'Cono doble', '120', $helados->id);

    $this->actingAs($this->owner)->get(route('panel.quick-pos.index'))
        ->assertOk()
        ->assertSee('Helados')
        // Un chip sin productos no se pinta: sería un callejón sin salida para el cajero.
        ->assertDontSee('Vacía');
});

it('el catálogo devuelve los productos con su foto, precio y categoría', function (): void {
    $helados = Category::create(['name' => 'Helados', 'slug' => 'helados', 'is_active' => true]);
    heladoConStock('CONO', 'Cono doble', '120', $helados->id);

    $payload = $this->actingAs($this->owner)
        ->getJson(route('panel.quick-pos.catalog'))
        ->assertOk()
        ->json();

    expect($payload['results'])->toHaveCount(1)
        ->and($payload['results'][0]['name'])->toBe('Cono doble')
        ->and($payload['results'][0]['price'])->toBe('120.00')
        ->and($payload['results'][0]['category_id'])->toBe($helados->id)
        ->and($payload['results'][0]['sellable'])->toBeTrue()
        ->and($payload['has_more'])->toBeFalse();
});

it('el catálogo filtra por la categoría pedida', function (): void {
    $helados = Category::create(['name' => 'Helados', 'slug' => 'helados', 'is_active' => true]);
    $bebidas = Category::create(['name' => 'Bebidas', 'slug' => 'bebidas', 'is_active' => true]);
    heladoConStock('CONO', 'Cono doble', '120', $helados->id);
    heladoConStock('REF', 'Refresco', '60', $bebidas->id);

    $results = $this->actingAs($this->owner)
        ->getJson(route('panel.quick-pos.catalog', ['category' => $bebidas->id]))
        ->assertOk()
        ->json('results');

    expect($results)->toHaveCount(1)
        ->and($results[0]['sku'])->toBe('REF');
});

it('marca como no vendible el producto agotado en vez de ocultarlo', function (): void {
    Product::create(['sku' => 'AGOT', 'name' => 'Agotado', 'cost' => '10', 'price' => '80']);

    $results = $this->actingAs($this->owner)
        ->getJson(route('panel.quick-pos.catalog'))->assertOk()->json('results');

    expect($results[0]['sellable'])->toBeFalse()
        ->and($results[0]['reason'])->toBe('no_stock');
});

it('cobra el pedido, descuenta stock y deja el cambio calculado', function (): void {
    $producto = heladoConStock('CONO', 'Cono doble', '100');

    $this->actingAs($this->owner)
        ->from(route('panel.quick-pos.index'))
        ->post(route('panel.pos.checkout'), [
            'cart' => json_encode([['id' => $producto->id, 'qty' => 2]]),
            'paid' => '500',
        ])
        ->assertRedirect(route('panel.quick-pos.index'))
        ->assertSessionHas('pos_ok');

    $sale = Sale::query()->latest('id')->first();

    expect($sale->total)->toBe('200.00')
        ->and($sale->change)->toBe('300.00');

    // 50 iniciales − 2 vendidos.
    expect((string) $producto->fresh()->totalStock())->toBe('48.000');
});

it('ignora el precio que envíe el navegador y cobra el del catálogo', function (): void {
    $producto = heladoConStock('CONO', 'Cono doble', '100');

    $this->actingAs($this->owner)
        ->from(route('panel.quick-pos.index'))
        ->post(route('panel.pos.checkout'), [
            // Un cliente manipulado se fija un precio de saldo: el servidor debe descartarlo.
            'cart' => json_encode([['id' => $producto->id, 'qty' => 1, 'price' => '1']]),
            'paid' => '100',
        ])
        ->assertSessionHas('pos_ok');

    expect(Sale::query()->latest('id')->first()->total)->toBe('100.00');
});

it('no expone en el catálogo los productos de otra empresa', function (): void {
    heladoConStock('MIO', 'Producto propio', '100');

    $otra = app(CompanyService::class)->create(new CreateCompanyData(name: 'Ajena Co'));
    app(CurrentCompany::class)->set($otra->id);
    Product::create(['sku' => 'AJENO', 'name' => 'Producto ajeno', 'cost' => '1', 'price' => '99']);
    app(CurrentCompany::class)->set($this->company->id);

    $results = $this->actingAs($this->owner)
        ->getJson(route('panel.quick-pos.catalog'))->assertOk()->json('results');

    expect(collect($results)->pluck('sku')->all())->toBe(['MIO']);
});

it('un usuario sin permiso de punto de venta no entra', function (): void {
    // Sin rol asignado: los tres roles que se aprovisionan (owner, admin, staff) llevan
    // «pos.operate», así que la única forma de no tenerlo es no tener rol.
    $sinRol = User::create([
        'company_id' => $this->company->id, 'name' => 'Sin permiso',
        'email' => 'nada@rapida.test', 'password' => 'secret-password',
    ]);

    $this->actingAs($sinRol)->get(route('panel.quick-pos.index'))->assertForbidden();
});

it('la rejilla no dispara una consulta por producto', function (): void {
    $helados = Category::create(['name' => 'Helados', 'slug' => 'helados', 'is_active' => true]);
    foreach (range(1, 12) as $i) {
        heladoConStock("H{$i}", "Helado {$i}", '100', $helados->id);
    }

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $this->actingAs($this->owner)->getJson(route('panel.quick-pos.catalog'))->assertOk();

    // Con 12 productos, un N+1 dispararía más de 12 consultas solo por el stock de cada ficha.
    expect($queries)->toBeLessThan(12, "El catálogo ejecutó {$queries} consultas para 12 productos.");
});
