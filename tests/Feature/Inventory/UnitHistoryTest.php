<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\CRM\Models\Customer;
use App\Modules\Inventory\DTOs\ScanUnitData;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Services\SerialScanService;
use App\Modules\Inventory\Support\UnitHistory;
use App\Modules\Sales\DTOs\CreateSaleData;
use App\Modules\Sales\DTOs\SaleLineData;
use App\Modules\Sales\Services\SaleService;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * LA CONSULTA POR SERIE: la razón de ser de todo el módulo.
 *
 * Serializar no sirve de nada si, cuando un cliente vuelve seis meses después con un teléfono roto, no
 * se puede saber si lo compró aquí, cuándo y qué era. Estos tests comprueban justo esa pregunta,
 * contestada desde lo único que trae el cliente: el número de serie.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(CurrentCompany::class)->forget();
    $this->company = app(CompanyService::class)->create(new CreateCompanyData(name: 'Historial Co'));
    app(CurrentCompany::class)->set($this->company->id);

    $this->admin = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Dueña',
        'email' => 'duena@hist.test', 'password' => 'secret-password',
    ]), 'owner');

    $this->warehouse = $this->company->warehouses()->where('is_default', true)->firstOrFail();

    $this->telefono = Product::create([
        'sku' => 'TEL-H', 'name' => 'Teléfono', 'cost' => '20000', 'price' => '35000',
        'tracks_serials' => true,
    ]);

    app(SerialScanService::class)->alta($this->telefono, $this->warehouse, [
        new ScanUnitData('IMEI-HIST'),
    ]);
});

it('una unidad recien dada de alta se encuentra por su serie, disponible', function (): void {
    $ficha = UnitHistory::porSerie('IMEI-HIST');

    expect($ficha)->not->toBeNull()
        ->and($ficha->unidad->product->name)->toBe('Teléfono')
        ->and($ficha->estaVendida())->toBeFalse()
        // Su historia arranca con la entrada al inventario.
        ->and($ficha->linea()[0]['hecho'])->toBe('Entró al inventario');
});

/*
 * EL CASO PARA EL QUE EXISTE LA PANTALLA: el cliente vuelve, y se ve a quién se le vendió el aparato
 * y cuándo. Se busca por serie una unidad YA VENDIDA — la que más se busca, porque el cliente vuelve
 * después de comprar.
 */
it('tras vender, la serie dice a quien se le vendio', function (): void {
    $juana = Customer::create(['name' => 'Juana Pérez', 'phone' => '8090000000']);

    app(SaleService::class)->complete(new CreateSaleData(
        warehouseId: $this->warehouse->id,
        lines: [new SaleLineData($this->telefono->id, '1', '35000', serial: 'IMEI-HIST')],
        paid: '35000',
        customerId: $juana->id,
    ));

    $ficha = UnitHistory::porSerie('IMEI-HIST');

    expect($ficha->estaVendida())->toBeTrue()
        ->and($ficha->aQuien())->toBe('Juana Pérez');

    // Y la historia tiene los dos hitos: entró y se vendió, a Juana.
    $linea = $ficha->linea();
    expect($linea)->toHaveCount(2)
        ->and($linea[1]['hecho'])->toBe('Se vendió')
        ->and($linea[1]['detalle'])->toBe('Juana Pérez');
});

it('una venta sin cliente identificado sale como consumidor final', function (): void {
    app(SaleService::class)->complete(new CreateSaleData(
        warehouseId: $this->warehouse->id,
        lines: [new SaleLineData($this->telefono->id, '1', '35000', serial: 'IMEI-HIST')],
        paid: '35000',
    ));

    expect(UnitHistory::porSerie('IMEI-HIST')->aQuien())->toBe('Consumidor final');
});

it('una serie que no existe no encuentra nada', function (): void {
    expect(UnitHistory::porSerie('NO-EXISTE'))->toBeNull()
        ->and(UnitHistory::porSerie('  '))->toBeNull();
});

/*
 * NO SE CRUZAN LAS EMPRESAS: la serie de otra empresa no aparece, aunque coincida el número. La
 * garantía de un cliente es de su tienda, no de la de al lado.
 */
it('no encuentra una serie de otra empresa', function (): void {
    $otra = app(CompanyService::class)->create(new CreateCompanyData(name: 'Otra'));
    app(CurrentCompany::class)->set($otra->id);
    $suyo = Product::create(['sku' => 'AJ', 'name' => 'Ajeno', 'cost' => '1', 'price' => '2', 'tracks_serials' => true]);
    $suAlmacen = $otra->warehouses()->where('is_default', true)->firstOrFail();
    app(SerialScanService::class)->alta($suyo, $suAlmacen, [new ScanUnitData('IMEI-HIST')]);

    app(CurrentCompany::class)->set($this->company->id);

    // Buscando desde MI empresa, la serie idéntica de la otra no debe aparecer: encuentro la mía.
    $ficha = UnitHistory::porSerie('IMEI-HIST');
    expect($ficha->unidad->company_id)->toBe($this->company->id);
});

// ------------------------------------------------------------------ La pantalla

it('la pantalla muestra la ficha al buscar una serie', function (): void {
    $this->withoutVite();

    $html = $this->actingAs($this->admin)
        ->get(route('panel.serial.history', ['serie' => 'IMEI-HIST']))
        ->assertOk()->getContent();

    expect($html)->toContain('IMEI-HIST')
        ->and($html)->toContain('Teléfono')
        ->and($html)->toContain('Disponible');
});

it('la pantalla lo dice cuando la serie no existe', function (): void {
    $this->withoutVite();

    $html = $this->actingAs($this->admin)
        ->get(route('panel.serial.history', ['serie' => 'FANTASMA']))
        ->assertOk()->getContent();

    expect($html)->toContain('No hay ninguna unidad con esa serie');
});
