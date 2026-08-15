<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockMovement;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * La PANTALLA de entrada de mercancía: sus puertas y las herramientas que ofrece.
 *
 * Las reglas de la remesa —que entre entera, el kardex, el costo, el proveedor— viven en
 * GoodsReceiptTest. Aquí queda lo que es de la pantalla y de quién puede llegar a ella, que es
 * distinto y se rompe por otros motivos.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(CurrentCompany::class)->forget();
    $this->company = app(CompanyService::class)->create(new CreateCompanyData(name: 'Entradas Co'));
    app(CurrentCompany::class)->set($this->company->id);

    $this->warehouse = $this->company->warehouses()->where('is_default', true)->firstOrFail();

    $this->admin = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Almacenista',
        'email' => 'admin@entradas.test', 'password' => 'secret-password',
    ]), 'admin');

    $this->product = Product::create([
        'sku' => 'ENT-1', 'name' => 'Producto Entrada',
        'barcode' => '7770001', 'cost' => '10', 'price' => '25',
    ]);
});

/** El cuerpo de una remesa de una sola línea, tal como lo manda el formulario. */
function remesaDeUnaLinea(array $linea = [], array $extra = []): array
{
    return array_merge([
        'warehouse_id' => test()->warehouse->id,
        'lines' => [array_merge([
            'product_id' => test()->product->id,
            'quantity' => '12',
        ], $linea)],
    ], $extra);
}

// ---------------------------------------------------------------- Herramientas de la pantalla

it('ofrece el campo de escaneo y la cámara', function (): void {
    // La cámara no es un adorno: es la única forma de escanear a pie de estantería cuando el lector
    // de pistola está atado a la caja. Se perdió una vez al rehacer la pantalla y nadie lo habría
    // notado hasta que un almacenista subiera al segundo piso con el móvil.
    $this->actingAs($this->admin)
        ->get(route('panel.stock.entry'))
        ->assertOk()
        ->assertSee('entry-scan', false)
        ->assertSee('Usar cámara', false);
});

it('la búsqueda del inventario no ve productos de otra empresa', function (): void {
    $otra = app(CompanyService::class)->create(new CreateCompanyData(name: 'Ajena Tres'));
    app(CurrentCompany::class)->set($otra->id);
    Product::create(['sku' => 'AJ-2', 'name' => 'Ajeno', 'barcode' => '9990001', 'price' => '10']);
    app(CurrentCompany::class)->set($this->company->id);

    $this->actingAs($this->admin)
        ->getJson(route('panel.products.lookup', ['codigo' => '9990001']))
        ->assertOk()
        ->assertJsonPath('found', false);
});

// ---------------------------------------------------------------- Validación de lo que llega

it('rechaza una cantidad de cero o negativa', function (string $qty): void {
    $this->actingAs($this->admin)
        ->post(route('panel.stock.store'), remesaDeUnaLinea(['quantity' => $qty]))
        ->assertSessionHasErrors('lines.0.quantity');

    expect(StockMovement::count())->toBe(0);
})->with(['0', '-5']);

it('rechaza un almacén de otra empresa', function (): void {
    $otra = app(CompanyService::class)->create(new CreateCompanyData(name: 'Ajena Dos'));

    // Hay que situarse en la otra empresa para poder leer su almacén: el CompanyScope lo esconde
    // desde aquí, que es justamente el aislamiento que se quiere comprobar.
    app(CurrentCompany::class)->set($otra->id);
    $almacenAjeno = $otra->warehouses()->where('is_default', true)->firstOrFail();
    app(CurrentCompany::class)->set($this->company->id);

    $this->actingAs($this->admin)
        ->post(route('panel.stock.store'), remesaDeUnaLinea([], ['warehouse_id' => $almacenAjeno->id]))
        ->assertSessionHasErrors('warehouse_id');

    expect(StockMovement::withoutCompanyScope()->count())->toBe(0);
});

// ---------------------------------------------------------------- Permisos

it('el cajero no puede dar entrada de mercancía', function (): void {
    // Decisión deliberada: «staff» tiene stock.view pero no stock.adjust. Quien puede inflar
    // existencias podría tapar un faltante, así que cobrar y recibir están separados.
    $cajero = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Cajero',
        'email' => 'cajero@entradas.test', 'password' => 'secret-password',
    ]), 'staff');

    $this->actingAs($cajero)->get(route('panel.stock.entry'))->assertForbidden();
    $this->actingAs($cajero)->post(route('panel.stock.store'), remesaDeUnaLinea())->assertForbidden();
});

it('una empresa sin el módulo de inventario no entra', function (): void {
    $this->company->update(['modules' => ['pos', 'sales']]);

    $this->actingAs($this->admin)->get(route('panel.stock.entry'))->assertForbidden();
});

// ---------------------------------------------------------------- Alta desde un código desconocido

it('un código desconocido se puede dar de alta con su código y su existencia inicial', function (): void {
    // Es el camino del botón «Crear el producto»: reutiliza el alta que ya existía.
    $this->actingAs($this->admin)
        ->post(route('panel.products.store'), [
            'sku' => 'NUEVO-1', 'name' => 'Producto Nuevo',
            'barcode' => '7770999', 'cost' => '5', 'price' => '15',
            'initial_stock' => '8',
        ])
        ->assertRedirect();

    $nuevo = Product::where('sku', 'NUEVO-1')->firstOrFail();

    expect($nuevo->barcode)->toBe('7770999')
        // totalStock() suma en la base y devuelve el valor sin escala fija: se compara numérico.
        ->and((float) $nuevo->totalStock())->toBe(8.0)
        // Nace con inventario inicial, que es exactamente lo que es: no es un ajuste ni una compra.
        ->and(StockMovement::where('product_id', $nuevo->id)->firstOrFail()->type)
        ->toBe(StockMovementType::Initial);
});
