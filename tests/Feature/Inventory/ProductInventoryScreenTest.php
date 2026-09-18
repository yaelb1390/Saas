<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Models\Warehouse;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Models\Category;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Stock;
use App\Modules\Inventory\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

/*
 * El rediseño de Inventario: las cuatro cifras de resumen, los filtros de categoría y almacén, y la
 * acción «Duplicar».
 *
 * Las cifras se calculan sobre TODO el catálogo, no sobre la página a la vista —de ahí que cada test
 * arme productos con y sin control de existencias, en uno y en dos almacenes, y alguno sin ninguna
 * fila de stock, que son justo los casos donde una suma ingenua se equivoca.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(CurrentCompany::class)->forget();
    $this->company = app(CompanyService::class)->create(new CreateCompanyData(name: 'Colmado Central'));
    app(CurrentCompany::class)->set($this->company->id);

    $this->duena = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Dueña',
        'email' => 'duena@colmado.test', 'password' => 'secret-password',
    ]), 'owner');

    $this->principal = Warehouse::query()->where('is_default', true)->firstOrFail();
    $this->sucursal = Warehouse::create(['company_id' => $this->company->id, 'name' => 'Sucursal']);

    $this->bebidas = Category::create(['company_id' => $this->company->id, 'name' => 'Bebidas']);

    // Con existencia sana (20, un solo almacén): no debe contar como bajo stock.
    $this->agua = Product::create([
        'sku' => 'AGUA', 'name' => 'Agua', 'category_id' => $this->bebidas->id,
        'cost' => '10', 'price' => '20', 'track_stock' => true,
    ]);
    app(StockService::class)->increase($this->agua, $this->principal, StockMovementType::Purchase, '20');

    // Con existencia baja (3, en la sucursal): debe contar como bajo stock y aparecer al filtrar
    // por la sucursal.
    $this->refresco = Product::create([
        'sku' => 'REFR', 'name' => 'Refresco', 'category_id' => $this->bebidas->id,
        'cost' => '5', 'price' => '12', 'track_stock' => true,
    ]);
    app(StockService::class)->increase($this->refresco, $this->sucursal, StockMovementType::Purchase, '3');

    // Sin control de existencias: no debe sumar ni al stock total ni al valor, y no es «bajo stock».
    $this->servicio = Product::create([
        'sku' => 'SERV', 'name' => 'Servicio', 'cost' => '100', 'price' => '200', 'track_stock' => false,
    ]);

    // Con control de existencias pero SIN ninguna fila de stock: no es «bajo stock» —si contara, todo
    // producto recién creado nacería como alerta—, y sin categoría, para el filtro de categoría.
    $this->sinExistencia = Product::create([
        'sku' => 'SINEX', 'name' => 'Sin existencia', 'cost' => '2', 'price' => '5', 'track_stock' => true,
    ]);
});

it('calcula las cuatro cifras de resumen sobre todo el catálogo', function (): void {
    $resumen = $this->actingAs($this->duena)->get(route('panel.products'))
        ->assertOk()->viewData('resumen');

    expect($resumen['total'])->toBe(4)
        // Stock total: 20 (agua) + 3 (refresco) + 0 (sin existencia); el servicio no cuenta.
        ->and((float) $resumen['stockTotal'])->toBe(23.0)
        // Valor: 20*10 + 3*5 = 200 + 15 = 215; el servicio (100*nada) tampoco entra.
        ->and((float) $resumen['valorInventario'])->toBe(215.0)
        // Solo el refresco tiene una fila de existencia por debajo de 5.
        ->and($resumen['bajoStock'])->toBe(1);
});

it('el filtro de categoría acota el listado y el paginador', function (): void {
    $ids = $this->actingAs($this->duena)
        ->get(route('panel.products', ['category_id' => $this->bebidas->id]))
        ->assertOk()->viewData('products');

    expect($ids->pluck('id')->sort()->values()->all())
        ->toBe(collect([$this->agua->id, $this->refresco->id])->sort()->values()->all())
        ->and($ids->total())->toBe(2);
});

it('el filtro de almacén enseña solo lo que tiene existencia ahí', function (): void {
    $productos = $this->actingAs($this->duena)
        ->get(route('panel.products', ['warehouse_id' => $this->sucursal->id]))
        ->assertOk()->viewData('products');

    expect($productos->pluck('id')->all())->toBe([$this->refresco->id]);
});

it('duplicar copia el catálogo pero arranca sin existencia, sin foto y con SKU propio', function (): void {
    $original = Product::create([
        'sku' => 'ORIG-1', 'name' => 'Original', 'barcode' => '7501234567890',
        'category_id' => $this->bebidas->id, 'cost' => '15', 'price' => '30',
        'unit' => 'caja', 'track_stock' => true, 'tracks_serials' => true,
    ]);
    app(StockService::class)->increase($original, $this->principal, StockMovementType::Purchase, '10');

    $this->actingAs($this->duena)
        ->post(route('panel.products.duplicate', $original))
        ->assertRedirect();

    $duplicado = Product::where('name', 'Original (copia)')->firstOrFail();

    expect($duplicado->id)->not->toBe($original->id)
        ->and($duplicado->sku)->not->toBe($original->sku)
        ->and($duplicado->sku)->not->toBeEmpty()
        ->and($duplicado->barcode)->toBeNull()
        ->and($duplicado->category_id)->toBe($original->category_id)
        ->and((string) $duplicado->cost)->toBe('15.00')
        ->and((string) $duplicado->price)->toBe('30.00')
        ->and($duplicado->unit)->toBe('caja')
        ->and($duplicado->tracks_serials)->toBeTrue()
        ->and($duplicado->image_path)->toBeNull()
        ->and(Stock::query()->where('product_id', $duplicado->id)->count())->toBe(0)
        ->and($duplicado->totalStock())->toBe('0.000');
});

it('el interruptor de Activo/Inactivo retira o devuelve el producto al catálogo', function (): void {
    // El default de la columna ('is_active' boolean, default true) lo pone la base de datos al
    // insertar: el modelo recién creado en memoria no lo trae hasta que se relee.
    expect($this->agua->refresh()->is_active)->toBeTrue();

    $this->actingAs($this->duena)
        ->post(route('panel.products.status', $this->agua), ['is_active' => '0'])
        ->assertRedirect();

    expect($this->agua->refresh()->is_active)->toBeFalse()
        ->and($this->agua->sePuedeVender())->toBeFalse();

    $this->actingAs($this->duena)
        ->post(route('panel.products.status', $this->agua), ['is_active' => '1'])
        ->assertRedirect();

    expect($this->agua->refresh()->is_active)->toBeTrue();
});

it('con products.view el interruptor no es clicable y el POST directo se rechaza', function (): void {
    $soloLectura = User::create([
        'company_id' => $this->company->id, 'name' => 'Solo lectura',
        'email' => 'lectura2@colmado.test', 'password' => 'secret-password',
    ]);

    $registrar = app(PermissionRegistrar::class);
    $registrar->setPermissionsTeamId($this->company->id);
    $soloLectura->givePermissionTo('products.view');
    $registrar->forgetCachedPermissions();

    $html = $this->actingAs($soloLectura)->get(route('panel.products'))->assertOk()->getContent();

    expect($html)->not->toContain(route('panel.products.status', $this->agua));

    $this->actingAs($soloLectura)
        ->post(route('panel.products.status', $this->agua), ['is_active' => '0'])
        ->assertForbidden();

    expect($this->agua->refresh()->is_active)->toBeTrue();
});

it('con products.view se ve «Ver», pero «Duplicar» exige products.manage', function (): void {
    $soloLectura = User::create([
        'company_id' => $this->company->id, 'name' => 'Solo lectura',
        'email' => 'lectura@colmado.test', 'password' => 'secret-password',
    ]);

    $registrar = app(PermissionRegistrar::class);
    $registrar->setPermissionsTeamId($this->company->id);
    $soloLectura->givePermissionTo('products.view');
    $registrar->forgetCachedPermissions();

    $html = $this->actingAs($soloLectura)->get(route('panel.products'))->assertOk()->getContent();

    expect($html)->toContain('title="Ver"')
        ->not->toContain(route('panel.products.duplicate', $this->agua));

    $this->actingAs($soloLectura)
        ->post(route('panel.products.duplicate', $this->agua))
        ->assertForbidden();

    expect(Product::count())->toBe(4);
});
