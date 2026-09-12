<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\Inventory\DTOs\ScanUnitData;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Exceptions\SerialScanException;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\Inventory\Models\Stock;
use App\Modules\Inventory\Services\SerialScanService;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Sales\DTOs\CreateSaleData;
use App\Modules\Sales\DTOs\SaleLineData;
use App\Modules\Sales\Services\SaleService;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * VENDER LA UNIDAD CONCRETA.
 *
 * Al vender un producto serializado se hacen DOS cosas en la misma transacción: baja el stock por
 * cantidad —como en cualquier venta— y la unidad de esa serie pasa a «vendida». La invariante del
 * módulo se cumple también al salir: contador y unidades disponibles siguen cuadrando.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(CurrentCompany::class)->forget();
    $this->company = app(CompanyService::class)->create(new CreateCompanyData(name: 'Venta Serie Co'));
    app(CurrentCompany::class)->set($this->company->id);

    withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Dueña',
        'email' => 'duena@vs.test', 'password' => 'secret-password',
    ]), 'owner');

    $this->warehouse = $this->company->warehouses()->where('is_default', true)->firstOrFail();

    $this->telefono = Product::create([
        'sku' => 'TEL-V', 'name' => 'Teléfono', 'cost' => '20000', 'price' => '35000',
        'tracks_serials' => true,
    ]);

    // Tres unidades en stock, dadas de alta por el flujo real.
    app(SerialScanService::class)->alta($this->telefono, $this->warehouse, [
        new ScanUnitData('IMEI-1'),
        new ScanUnitData('IMEI-2'),
        new ScanUnitData('IMEI-3'),
    ]);

    $this->sales = app(SaleService::class);
});

function ventaDeSerie(?string $serie): CreateSaleData
{
    return new CreateSaleData(
        warehouseId: test()->warehouse->id,
        lines: [new SaleLineData(test()->telefono->id, '1', '35000', serial: $serie)],
        paid: '35000',
    );
}

it('vender por serie marca ESA unidad y baja el stock', function (): void {
    $venta = $this->sales->complete(ventaDeSerie('IMEI-2'));

    $vendida = ProductUnit::where('serial', 'IMEI-2')->firstOrFail();

    expect($vendida->status)->toBe(ProductUnit::VENDIDA)
        ->and($vendida->sale_id)->toBe($venta->id)
        ->and($vendida->sold_at)->not->toBeNull()
        // Las otras dos siguen disponibles.
        ->and($this->telefono->unidadesDisponibles($this->warehouse->id))->toBe(2)
        // Y el contador cuadra: eran 3, se vendió 1, quedan 2.
        ->and((float) Stock::query()->where('product_id', $this->telefono->id)->value('quantity'))->toBe(2.0);
});

/*
 * SIN SERIE, LA VENTA DE UN SERIALIZADO SE CAE. Vender «un teléfono» sin decir cuál dejaría el stock
 * descontado sin una unidad detrás — el descuadre que todo el módulo evita.
 */
it('no deja vender un serializado sin decir que unidad', function (): void {
    expect(fn () => $this->sales->complete(ventaDeSerie(null)))
        ->toThrow(SerialScanException::class);

    // Y no dejó rastro: ninguna unidad tocada, el stock intacto.
    expect(ProductUnit::where('status', ProductUnit::VENDIDA)->count())->toBe(0)
        ->and((float) Stock::query()->where('product_id', $this->telefono->id)->value('quantity'))->toBe(3.0);
});

it('no deja vender una serie que no esta disponible', function (): void {
    expect(fn () => $this->sales->complete(ventaDeSerie('IMEI-QUE-NO-EXISTE')))
        ->toThrow(SerialScanException::class);

    expect((float) Stock::query()->where('product_id', $this->telefono->id)->value('quantity'))->toBe(3.0);
});

it('no deja vender dos veces la misma unidad', function (): void {
    $this->sales->complete(ventaDeSerie('IMEI-1'));

    // El segundo intento la encuentra ya vendida.
    expect(fn () => $this->sales->complete(ventaDeSerie('IMEI-1')))
        ->toThrow(SerialScanException::class);

    expect(ProductUnit::where('serial', 'IMEI-1')->first()->status)->toBe(ProductUnit::VENDIDA)
        ->and($this->telefono->unidadesDisponibles($this->warehouse->id))->toBe(2);
});

/*
 * Y un producto NORMAL se sigue vendiendo sin ninguna serie, como toda la vida: el módulo no toca a
 * quien no lo usa.
 */
it('un producto no serializado se vende sin serie, como siempre', function (): void {
    $bateo = Product::create(['sku' => 'BAT-V', 'name' => 'Batida', 'cost' => '50', 'price' => '150']);
    app(StockService::class)
        ->increase($bateo, $this->warehouse, StockMovementType::Purchase, '10');

    $venta = $this->sales->complete(new CreateSaleData(
        warehouseId: $this->warehouse->id,
        lines: [new SaleLineData($bateo->id, '2', '150')],
        paid: '300',
    ));

    expect($venta->total)->toBe('300.00')
        ->and((float) Stock::query()->where('product_id', $bateo->id)->value('quantity'))->toBe(8.0);
});
