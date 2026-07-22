<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Stock;
use App\Modules\Inventory\Models\StockMovement;
use Illuminate\Foundation\Testing\RefreshDatabase;

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

// ---------------------------------------------------------------- Registro de la entrada

it('la entrada suma existencia y deja el movimiento en el kardex', function (): void {
    $this->actingAs($this->admin)
        ->post(route('panel.stock.store'), [
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouse->id,
            'quantity' => '12',
        ])
        ->assertRedirect();

    $stock = Stock::where('product_id', $this->product->id)->firstOrFail();
    $movement = StockMovement::latest('id')->firstOrFail();

    expect($stock->quantity)->toBe('12.000')
        // Adjustment, no Purchase: esta entrada no viene respaldada por una orden de compra.
        ->and($movement->type)->toBe(StockMovementType::Adjustment)
        ->and($movement->quantity)->toBe('12.000')
        ->and($movement->quantity_before)->toBe('0.000')
        ->and($movement->quantity_after)->toBe('12.000')
        ->and($movement->user_id)->toBe($this->admin->id);
});

it('dos entradas seguidas acumulan', function (): void {
    foreach (['5', '7'] as $qty) {
        $this->actingAs($this->admin)->post(route('panel.stock.store'), [
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouse->id,
            'quantity' => $qty,
        ])->assertRedirect();
    }

    expect(Stock::where('product_id', $this->product->id)->firstOrFail()->quantity)->toBe('12.000');
});

it('guarda la nota cuando se indica', function (): void {
    $this->actingAs($this->admin)->post(route('panel.stock.store'), [
        'product_id' => $this->product->id,
        'warehouse_id' => $this->warehouse->id,
        'quantity' => '3',
        'notes' => 'Factura 00123 del proveedor',
    ])->assertRedirect();

    expect(StockMovement::latest('id')->firstOrFail()->notes)->toBe('Factura 00123 del proveedor');
});

// ---------------------------------------------------------------- Validación

it('rechaza una cantidad de cero o negativa', function (string $qty): void {
    $this->actingAs($this->admin)
        ->post(route('panel.stock.store'), [
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouse->id,
            'quantity' => $qty,
        ])
        ->assertSessionHasErrors('quantity');

    expect(StockMovement::count())->toBe(0);
})->with(['0', '-5']);

// ---------------------------------------------------------------- Aislamiento multiempresa

it('rechaza un producto de otra empresa', function (): void {
    $otra = app(CompanyService::class)->create(new CreateCompanyData(name: 'Ajena Co'));
    app(CurrentCompany::class)->set($otra->id);
    $ajeno = Product::create(['sku' => 'AJ-1', 'name' => 'Ajeno', 'price' => '10']);
    app(CurrentCompany::class)->set($this->company->id);

    $this->actingAs($this->admin)
        ->post(route('panel.stock.store'), [
            'product_id' => $ajeno->id,
            'warehouse_id' => $this->warehouse->id,
            'quantity' => '10',
        ])
        ->assertSessionHasErrors('product_id');

    expect(StockMovement::withoutCompanyScope()->count())->toBe(0);
});

it('rechaza un almacén de otra empresa', function (): void {
    $otra = app(CompanyService::class)->create(new CreateCompanyData(name: 'Ajena Dos'));

    // Hay que situarse en la otra empresa para poder leer su almacén: el CompanyScope lo esconde
    // desde aquí, que es justamente el aislamiento que se quiere comprobar.
    app(CurrentCompany::class)->set($otra->id);
    $almacenAjeno = $otra->warehouses()->where('is_default', true)->firstOrFail();
    app(CurrentCompany::class)->set($this->company->id);

    $this->actingAs($this->admin)
        ->post(route('panel.stock.store'), [
            'product_id' => $this->product->id,
            'warehouse_id' => $almacenAjeno->id,
            'quantity' => '10',
        ])
        ->assertSessionHasErrors('warehouse_id');

    expect(StockMovement::withoutCompanyScope()->count())->toBe(0);
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

// ---------------------------------------------------------------- Permisos

it('el cajero no puede dar entrada de mercancía', function (): void {
    // Decisión deliberada: «staff» tiene stock.view pero no stock.adjust. Quien puede inflar
    // existencias podría tapar un faltante, así que cobrar y recibir están separados.
    $cajero = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Cajero',
        'email' => 'cajero@entradas.test', 'password' => 'secret-password',
    ]), 'staff');

    $this->actingAs($cajero)->get(route('panel.stock.entry'))->assertForbidden();

    $this->actingAs($cajero)->post(route('panel.stock.store'), [
        'product_id' => $this->product->id,
        'warehouse_id' => $this->warehouse->id,
        'quantity' => '10',
    ])->assertForbidden();
});

it('una empresa sin el módulo de inventario no entra', function (): void {
    $this->company->update(['modules' => ['pos', 'sales']]);

    $this->actingAs($this->admin)->get(route('panel.stock.entry'))->assertForbidden();
});

// ---------------------------------------------------------------- Pantalla y alta desde código

it('la pantalla ofrece el campo de escaneo y la cámara', function (): void {
    $this->actingAs($this->admin)
        ->get(route('panel.stock.entry'))
        ->assertOk()
        ->assertSee('entry-scan', false)
        ->assertSee('Usar cámara', false);
});

it('un código desconocido se puede dar de alta con su código y su existencia inicial', function (): void {
    // Es el camino del botón «Crear producto con este código»: reutiliza el alta que ya existía.
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
