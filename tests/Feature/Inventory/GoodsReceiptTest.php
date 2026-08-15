<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Models\Warehouse;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\Inventory\DTOs\CreateGoodsReceiptData;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Exceptions\StockException;
use App\Modules\Inventory\Models\GoodsReceipt;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Services\GoodsReceiptService;
use App\Modules\Purchasing\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/*
 * Entrada de mercancía por remesa.
 *
 * Antes era «un producto, un envío, una recarga de página»: treinta artículos eran treinta viajes al
 * servidor, y si el almacenista se distraía a la mitad quedaban quince dentro y quince fuera sin nada
 * que dijera cuáles. Tampoco quedaba constancia de quién trajo la mercancía ni a qué costo.
 *
 * Lo que se fija aquí es que la remesa entre ENTERA o no entre, que el kardex diga de dónde vino cada
 * unidad, y que el costo del producto solo cambie cuando alguien lo decide.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(CurrentCompany::class)->forget();
    $this->company = app(CompanyService::class)->create(new CreateCompanyData(name: 'Almacén Co'));
    app(CurrentCompany::class)->set($this->company->id);

    $this->owner = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Dueña',
        'email' => 'duena@almacen.test', 'password' => 'secret-password',
    ]), 'owner');

    $this->almacen = Warehouse::firstOrFail();
});

function productoDeRemesa(string $nombre, string $costo = '100', bool $llevaStock = true): Product
{
    return Product::create([
        'name' => $nombre, 'sku' => str($nombre)->slug()->upper()->toString(),
        'price' => '200', 'cost' => $costo, 'track_stock' => $llevaStock,
    ]);
}

function existenciaDe(Product $p): string
{
    return (string) (DB::table('stock')->where('product_id', $p->id)->sum('quantity') ?: '0');
}

/** @param list<array<string, mixed>> $lineas */
function remesaDe(array $lineas, array $extra = []): GoodsReceipt
{
    return app(GoodsReceiptService::class)->create(CreateGoodsReceiptData::fromArray(array_merge([
        'warehouse_id' => test()->almacen->id,
        'received_at' => now()->toDateString(),
        'lines' => $lineas,
    ], $extra)));
}

// -------------------------------------------------------------------- Entra toda o no entra

it('una remesa suma la existencia de todos sus productos de una vez', function (): void {
    $a = productoDeRemesa('Refresco');
    $b = productoDeRemesa('Galleta');

    $r = remesaDe([
        ['product_id' => $a->id, 'quantity' => '24'],
        ['product_id' => $b->id, 'quantity' => '10.5'],
    ]);

    expect($r->code)->toBe('ENT-000001')
        ->and($r->lines)->toHaveCount(2)
        ->and((float) existenciaDe($a))->toBe(24.0)
        ->and((float) existenciaDe($b))->toBe(10.5);
});

it('si una línea es inválida NO entra ninguna', function (): void {
    /*
     * Es la razón de ser del cambio. Antes, con envíos sueltos, media remesa podía quedarse dentro y
     * la otra media fuera sin nada que dijera cuáles: para arreglarlo había que contar el almacén.
     */
    $a = productoDeRemesa('Refresco');

    expect(fn () => remesaDe([
        ['product_id' => $a->id, 'quantity' => '24'],
        ['product_id' => $a->id, 'quantity' => '0'],   // inválida: cero
    ]))->toThrow(StockException::class);

    expect((float) existenciaDe($a))->toBe(0.0)
        ->and(GoodsReceipt::query()->count())->toBe(0);
});

it('una remesa sin líneas no se registra', function (): void {
    expect(fn () => remesaDe([]))->toThrow(StockException::class);
});

it('rechaza un producto de otra empresa', function (): void {
    app(CurrentCompany::class)->forget();
    $otra = app(CompanyService::class)->create(new CreateCompanyData(name: 'Ajena'));
    app(CurrentCompany::class)->set($otra->id);
    $ajeno = productoDeRemesa('De la otra');

    app(CurrentCompany::class)->set($this->company->id);

    expect(fn () => remesaDe([['product_id' => $ajeno->id, 'quantity' => '5']]))
        ->toThrow(StockException::class);
});

// -------------------------------------------------------------- El kardex dice de dónde vino

it('el movimiento se marca como compra y apunta a la remesa', function (): void {
    // Antes era un «ajuste» suelto con la nota «Entrada de mercancía»: el kardex no sabía de dónde
    // había salido esa existencia. Con el documento delante, sí.
    $a = productoDeRemesa('Refresco');
    $r = remesaDe([['product_id' => $a->id, 'quantity' => '12']], ['reference' => 'B0100000123']);

    $mov = StockMovement::query()->where('product_id', $a->id)->latest('id')->firstOrFail();

    expect($mov->type)->toBe(StockMovementType::Purchase)
        ->and($mov->reference_type)->toBe(GoodsReceipt::class)
        ->and($mov->reference_id)->toBe($r->id)
        ->and($mov->notes)->toContain($r->code)
        ->and($mov->notes)->toContain('B0100000123');
});

it('guarda de quién vino y con qué referencia', function (): void {
    $proveedor = Supplier::create(['name' => 'Distribuidora Peña']);
    $a = productoDeRemesa('Refresco');

    $r = remesaDe([['product_id' => $a->id, 'quantity' => '5']], [
        'supplier_id' => $proveedor->id,
        'reference' => 'B0100000999',
    ]);

    expect($r->supplier_id)->toBe($proveedor->id)
        // Snapshot del nombre: si mañana se borra el proveedor, la remesa sigue diciendo de quién vino.
        ->and($r->supplier_name)->toBe('Distribuidora Peña')
        ->and($r->reference)->toBe('B0100000999')
        ->and($r->deQuien())->toBe('Distribuidora Peña');
});

it('un proveedor escrito a mano también se guarda', function (): void {
    // Muchas remesas llegan de alguien que no está dado de alta, y obligar a crearle ficha para poder
    // cargar dos cajas convertiría la pantalla en un trámite.
    $a = productoDeRemesa('Refresco');
    $r = remesaDe([['product_id' => $a->id, 'quantity' => '5']], ['supplier_name' => 'El camión de los viernes']);

    expect($r->supplier_id)->toBeNull()
        ->and($r->deQuien())->toBe('El camión de los viernes');
});

it('los productos sin control de existencia no suman stock, pero sí quedan en la remesa', function (): void {
    // Un servicio no descuenta al vender; apuntarle existencia al recibir sería inventar un almacén
    // que no lleva. Pero si vino en la factura, tiene que constar.
    $servicio = productoDeRemesa('Instalación', '500', llevaStock: false);

    $r = remesaDe([['product_id' => $servicio->id, 'quantity' => '1', 'unit_cost' => '500']]);

    expect($r->lines)->toHaveCount(1)
        ->and(StockMovement::query()->where('product_id', $servicio->id)->count())->toBe(0);
});

// ----------------------------------------------------------------------------- El costo

it('el costo del producto NO cambia si nadie lo pide', function (): void {
    // Es el comportamiento de antes y sigue siendo el que manda: una compra más cara puntual no debe
    // desplomar el margen de todo el catálogo sin avisar.
    $a = productoDeRemesa('Refresco', '100');

    remesaDe([['product_id' => $a->id, 'quantity' => '10', 'unit_cost' => '120']]);

    expect((string) $a->fresh()->cost)->toBe('100.00');
});

it('el costo cambia cuando se pide, y queda el rastro de cuál era', function (): void {
    $a = productoDeRemesa('Refresco', '100');

    $r = remesaDe([['product_id' => $a->id, 'quantity' => '10', 'unit_cost' => '120', 'update_cost' => true]]);
    $linea = $r->lines->first();

    expect((string) $a->fresh()->cost)->toBe('120.00')
        ->and($linea->cost_updated)->toBeTrue()
        ->and((string) $linea->previous_cost)->toBe('100.00');
});

it('pedir actualizar con el MISMO costo no cuenta como cambio', function (): void {
    // Escribir el número que ya estaba no es una decisión de cambiar nada; anotarlo como cambio
    // llenaría el histórico de ruido y haría creer que el costo se movió.
    $a = productoDeRemesa('Refresco', '100');

    $r = remesaDe([['product_id' => $a->id, 'quantity' => '10', 'unit_cost' => '100', 'update_cost' => true]]);

    expect($r->lines->first()->cost_updated)->toBeFalse()
        ->and($r->lines->first()->previous_cost)->toBeNull();
});

it('sin costo escrito no se toca nada', function (): void {
    $a = productoDeRemesa('Refresco', '100');

    $r = remesaDe([['product_id' => $a->id, 'quantity' => '10', 'update_cost' => true]]);

    expect((string) $a->fresh()->cost)->toBe('100.00')
        ->and($r->lines->first()->unit_cost)->toBeNull()
        ->and($r->costoTotal())->toBe('0.00');
});

it('el costo total suma solo las líneas que lo tienen', function (): void {
    $a = productoDeRemesa('Refresco');
    $b = productoDeRemesa('Galleta');

    $r = remesaDe([
        ['product_id' => $a->id, 'quantity' => '10', 'unit_cost' => '25.50'],
        ['product_id' => $b->id, 'quantity' => '4'],   // sin costo
    ]);

    // 10 × 25.50 = 255.00; la línea sin costo no inventa nada a partir del costo viejo.
    expect($r->costoTotal())->toBe('255.00')
        ->and((float) $r->unidades())->toBe(14.0);
});

// ------------------------------------------------------------------------------- Por HTTP

it('el almacenista confirma una remesa desde la pantalla', function (): void {
    $this->withoutVite();
    $a = productoDeRemesa('Refresco');
    $b = productoDeRemesa('Galleta');

    $this->actingAs($this->owner)->post(route('panel.stock.store'), [
        'warehouse_id' => $this->almacen->id,
        'received_at' => now()->toDateString(),
        'reference' => 'B0100000123',
        'lines' => [
            ['product_id' => $a->id, 'quantity' => '24'],
            ['product_id' => $b->id, 'quantity' => '6', 'unit_cost' => '15', 'update_cost' => '1'],
        ],
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect((float) existenciaDe($a))->toBe(24.0)
        ->and((float) existenciaDe($b))->toBe(6.0)
        ->and((string) $b->fresh()->cost)->toBe('15.00');
});

it('rechaza una remesa sin productos', function (): void {
    $this->withoutVite();

    $this->actingAs($this->owner)->post(route('panel.stock.store'), [
        'warehouse_id' => $this->almacen->id,
        'received_at' => now()->toDateString(),
    ])->assertSessionHasErrors('lines');
});

it('rechaza una fecha futura', function (): void {
    // Sería mercancía que todavía no ha llegado.
    $this->withoutVite();
    $a = productoDeRemesa('Refresco');

    $this->actingAs($this->owner)->post(route('panel.stock.store'), [
        'warehouse_id' => $this->almacen->id,
        'received_at' => now()->addWeek()->toDateString(),
        'lines' => [['product_id' => $a->id, 'quantity' => '5']],
    ])->assertSessionHasErrors('received_at');

    expect((float) existenciaDe($a))->toBe(0.0);
});

it('la pantalla enseña las últimas entradas, no las ventas', function (): void {
    /*
     * El panel lateral mostraba los últimos movimientos de existencia, así que en una pantalla de
     * ENTRADAS aparecían las ventas del punto de venta («−1 Venta») y, si el producto se había
     * borrado, una fila con un guion y nada más.
     */
    $this->withoutVite();
    $a = productoDeRemesa('Refresco');
    remesaDe([['product_id' => $a->id, 'quantity' => '12']], ['reference' => 'CONDUCE-77']);

    $html = $this->actingAs($this->owner)->get(route('panel.stock.entry'))->assertOk()->getContent();

    expect($html)->toContain('ENT-000001')
        ->toContain('CONDUCE-77')
        ->toContain('Últimas entradas')
        ->and($html)->not->toContain('Movimientos recientes');
});

// ---------------------------------------------------------------------- Permisos y costo

it('el cajero NO recibe el costo en la búsqueda de productos', function (): void {
    /*
     * El buscador de productos lo comparten el punto de venta y el almacén. Devolver el costo sin
     * condición se lo enseñaría al cajero: sabría lo que le cuesta cada producto al negocio con solo
     * abrir las herramientas del navegador.
     */
    $this->withoutVite();
    productoDeRemesa('Refresco', '77.77');

    $cajero = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Cajero',
        'email' => 'cajero@almacen.test', 'password' => 'secret-password',
    ]), 'staff');

    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $comoCajero = $this->actingAs($cajero)->getJson(route('panel.pos.lookup', ['codigo' => 'REFRESCO']));
    $comoCajero->assertOk();
    expect($comoCajero->json('product.cost'))->toBeNull();

    // El dueño, que sí puede dar entrada, lo necesita para que le avisen del cambio de costo.
    $comoDuena = $this->actingAs($this->owner)->getJson(route('panel.products.lookup', ['codigo' => 'REFRESCO']));
    $comoDuena->assertOk();
    expect($comoDuena->json('product.cost'))->toBe('77.77');
});

it('un cajero no puede dar entrada de mercancía', function (): void {
    $this->withoutVite();
    $a = productoDeRemesa('Refresco');

    $cajero = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Cajero Dos',
        'email' => 'cajero2@almacen.test', 'password' => 'secret-password',
    ]), 'staff');

    $this->actingAs($cajero)->get(route('panel.stock.entry'))->assertForbidden();
    $this->actingAs($cajero)->post(route('panel.stock.store'), [
        'warehouse_id' => $this->almacen->id,
        'received_at' => now()->toDateString(),
        'lines' => [['product_id' => $a->id, 'quantity' => '5']],
    ])->assertForbidden();
});

it('los códigos se numeran por empresa', function (): void {
    $a = productoDeRemesa('Refresco');

    expect(remesaDe([['product_id' => $a->id, 'quantity' => '1']])->code)->toBe('ENT-000001')
        ->and(remesaDe([['product_id' => $a->id, 'quantity' => '1']])->code)->toBe('ENT-000002');
});
