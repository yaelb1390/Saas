<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\Inventory\DTOs\ScanUnitData;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Exceptions\ProductUnitException;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\Inventory\Models\Stock;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Services\SerialScanService;
use App\Modules\Inventory\Services\UnitAdjustmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

/*
 * LA PANTALLA «UNIDADES EN SERIE»: listar, corregir y dar de baja.
 *
 * Aquí se prueba la regla que sostiene el módulo, ahora al revés: dar de alta suma uno al stock;
 * borrar una unidad disponible RESTA uno, por la puerta con kardex. Y lo que NO se puede hacer:
 * borrar una vendida (es historial y garantía) o cambiarle la serie (es su identidad).
 *
 * `stockDe()` es el helper global de SerialScanTest: no se redefine aquí.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(CurrentCompany::class)->forget();
    $this->company = app(CompanyService::class)->create(new CreateCompanyData(name: 'Unidades Co'));
    app(CurrentCompany::class)->set($this->company->id);

    $this->admin = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Dueña',
        'email' => 'duena@units.test', 'password' => 'secret-password',
    ]), 'owner');

    $this->warehouse = $this->company->warehouses()->where('is_default', true)->firstOrFail();

    $this->telefono = Product::create([
        'sku' => 'TEL-U', 'name' => 'Teléfono', 'cost' => '20000', 'price' => '35000',
        'tracks_serials' => true,
    ]);

    // Dos unidades: stock queda en 2. La invariante arranca cuadrada.
    app(SerialScanService::class)->alta($this->telefono, $this->warehouse, [
        new ScanUnitData('IMEI-A'),
        new ScanUnitData('IMEI-B'),
    ]);

    // El stock del teléfono, ahora mismo. Closure y no helper global: `stockDe()` de SerialScanTest
    // solo existe cuando ese fichero está en la corrida, y redefinirlo tumbaría la suite entera.
    $this->stockActual = fn (): float => (float) Stock::query()
        ->where('product_id', $this->telefono->id)
        ->where('warehouse_id', $this->warehouse->id)
        ->value('quantity');
});

/*
 * LA INVARIANTE, al revés. Borrar una unidad disponible baja el stock en uno, con un movimiento de
 * ajuste que enlaza a la unidad. Si se quita la línea del `decrease` del servicio, este test cae:
 * el stock se quedaría en 2 y no habría movimiento de ajuste.
 */
it('borrar una unidad disponible baja el stock en uno y la archiva', function (): void {
    $unidad = ProductUnit::where('serial', 'IMEI-A')->firstOrFail();

    expect(($this->stockActual)())->toBe(2.0);

    app(UnitAdjustmentService::class)->borrar($unidad);

    // El contador bajó a 1, y solo queda una unidad viva.
    expect(($this->stockActual)())->toBe(1.0)
        ->and(ProductUnit::count())->toBe(1)
        ->and(ProductUnit::withTrashed()->find($unidad->id)->trashed())->toBeTrue();

    // Y el kardex tiene el movimiento de ajuste, de -1, enlazado a esa unidad.
    $movimiento = StockMovement::query()
        ->where('reference_type', ProductUnit::class)
        ->where('reference_id', $unidad->id)
        ->where('type', StockMovementType::Adjustment)
        ->first();

    expect($movimiento)->not->toBeNull()
        ->and($movimiento->quantity)->toBe('-1.000');
});

/*
 * UNA VENDIDA NO SE BORRA. Su historia es la garantía, y su stock ya bajó al venderse: volver a
 * bajarlo lo dejaría en negativo. El servicio se niega y no toca nada.
 */
it('no borra una unidad que no está disponible', function (): void {
    $unidad = ProductUnit::where('serial', 'IMEI-A')->firstOrFail();
    $unidad->update(['status' => ProductUnit::VENDIDA]);

    expect(fn () => app(UnitAdjustmentService::class)->borrar($unidad))
        ->toThrow(ProductUnitException::class);

    // Ni se archivó ni se movió el stock.
    expect(ProductUnit::withTrashed()->find($unidad->id)->trashed())->toBeFalse()
        ->and(($this->stockActual)())->toBe(2.0);
});

it('editar cambia precio, condición y color, pero nunca la serie', function (): void {
    $unidad = ProductUnit::where('serial', 'IMEI-A')->firstOrFail();

    $this->actingAs($this->admin)
        ->put(route('panel.products.units.update', $unidad), [
            'condition' => 'usado',
            'color' => 'Grafito',
            'price' => '28000',
            // Se intenta colar una serie nueva: debe ignorarse.
            'serial' => 'IMEI-FALSO',
        ])
        ->assertRedirect();

    $unidad->refresh();
    expect($unidad->condition)->toBe('usado')
        ->and($unidad->color)->toBe('Grafito')
        ->and((float) $unidad->price)->toBe(28000.0)
        ->and($unidad->serial)->toBe('IMEI-A');
});

it('un precio vacío deja que la unidad siga el precio del catálogo', function (): void {
    $unidad = ProductUnit::where('serial', 'IMEI-A')->firstOrFail();
    $unidad->update(['price' => '30000']);

    $this->actingAs($this->admin)
        ->put(route('panel.products.units.update', $unidad), ['price' => ''])
        ->assertRedirect();

    // Sin precio propio, precioDeVenta() cae al del producto.
    expect($unidad->refresh()->price)->toBeNull()
        ->and((float) $unidad->precioDeVenta())->toBe(35000.0);
});

it('el borrado por la ruta baja el stock y avisa', function (): void {
    $unidad = ProductUnit::where('serial', 'IMEI-A')->firstOrFail();

    $this->actingAs($this->admin)
        ->delete(route('panel.products.units.destroy', $unidad))
        ->assertRedirect()
        ->assertSessionHas('panel_ok');

    expect(($this->stockActual)())->toBe(1.0);
});

it('la pantalla lista las unidades y filtra por estado', function (): void {
    $this->withoutVite();

    // Por omisión, las disponibles: se ven las dos series.
    $html = $this->actingAs($this->admin)->get(route('panel.serial.units'))->assertOk()->getContent();
    expect($html)->toContain('IMEI-A')->and($html)->toContain('IMEI-B');

    // Filtro «vendidas»: como ninguna se vendió, no aparecen.
    $vendidas = $this->actingAs($this->admin)
        ->get(route('panel.serial.units', ['estado' => 'vendidas']))
        ->assertOk()->getContent();
    expect($vendidas)->not->toContain('IMEI-A');
});

it('el filtro por producto solo muestra las de ese producto', function (): void {
    $this->withoutVite();

    $otro = Product::create(['sku' => 'TEL-Z', 'name' => 'Otro', 'cost' => '1', 'price' => '2', 'tracks_serials' => true]);
    app(SerialScanService::class)->alta($otro, $this->warehouse, [new ScanUnitData('IMEI-Z')]);

    $html = $this->actingAs($this->admin)
        ->get(route('panel.serial.units', ['product_id' => $this->telefono->id]))
        ->assertOk()->getContent();

    expect($html)->toContain('IMEI-A')->and($html)->not->toContain('IMEI-Z');
});

/*
 * AISLAMIENTO. La unidad de otra empresa no se toca: el route-model binding pasa por el CompanyScope,
 * así que su id devuelve 404 desde mi empresa, no un borrado silencioso del inventario ajeno.
 */
it('no se puede borrar la unidad de otra empresa', function (): void {
    $otra = app(CompanyService::class)->create(new CreateCompanyData(name: 'Ajena'));
    app(CurrentCompany::class)->set($otra->id);
    $suyo = Product::create(['sku' => 'AJ', 'name' => 'Ajeno', 'cost' => '1', 'price' => '2', 'tracks_serials' => true]);
    $suAlmacen = $otra->warehouses()->where('is_default', true)->firstOrFail();
    app(SerialScanService::class)->alta($suyo, $suAlmacen, [new ScanUnitData('IMEI-AJENO')]);
    $ajena = ProductUnit::withoutCompanyScope()->where('serial', 'IMEI-AJENO')->firstOrFail();

    app(CurrentCompany::class)->set($this->company->id);

    $this->actingAs($this->admin)
        ->delete(route('panel.products.units.destroy', $ajena->id))
        ->assertNotFound();

    // Y sigue viva.
    expect(ProductUnit::withoutCompanyScope()->find($ajena->id))->not->toBeNull();
});

/*
 * LOS PERMISOS. Ver la lista pide `products.view`; borrar y editar piden `stock.adjust` —el mismo que
 * dar entrada de mercancía—. El cajero (staff) no tiene ninguno de los dos, así que ni ve la lista.
 * Y quien solo tiene `products.view` la ve pero no puede borrar.
 */
it('el cajero sin permisos no ve la pantalla', function (): void {
    $cajero = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Cajero',
        'email' => 'cajero@units.test', 'password' => 'secret-password',
    ]), 'staff');

    $this->actingAs($cajero)->get(route('panel.serial.units'))->assertForbidden();
});

it('con products.view se ve la lista, pero borrar exige stock.adjust', function (): void {
    $this->withoutVite();

    $soloLectura = User::create([
        'company_id' => $this->company->id, 'name' => 'Solo lectura',
        'email' => 'lectura@units.test', 'password' => 'secret-password',
    ]);

    $registrar = app(PermissionRegistrar::class);
    $registrar->setPermissionsTeamId($this->company->id);
    $soloLectura->givePermissionTo('products.view');
    $registrar->forgetCachedPermissions();

    // Ve la lista…
    $this->actingAs($soloLectura)->get(route('panel.serial.units'))->assertOk();

    // …pero no puede borrar: eso es stock.adjust, que no tiene.
    $unidad = ProductUnit::where('serial', 'IMEI-A')->firstOrFail();
    $this->actingAs($soloLectura)
        ->delete(route('panel.products.units.destroy', $unidad))
        ->assertForbidden();

    // Nada se movió.
    expect(($this->stockActual)())->toBe(2.0);
});
