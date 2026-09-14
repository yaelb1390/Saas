<?php

declare(strict_types=1);

use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\Inventory\DTOs\ScanUnitData;
use App\Modules\Inventory\Exceptions\SerialScanException;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\Inventory\Models\Stock;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Services\SerialScanService;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * EL ALTA MASIVA DE UNIDADES SERIALIZADAS.
 *
 * La invariante que sostiene el módulo entero: al dar de alta N unidades, el STOCK POR CANTIDAD sube
 * en N. Las unidades añaden «cuáles son» encima del contador de «cuántas hay», sin ser un segundo
 * inventario que descuadre con el primero. Casi todos estos tests comprueban esa cuenta.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(CurrentCompany::class)->forget();
    $this->company = app(CompanyService::class)->create(new CreateCompanyData(name: 'Electrónica Co'));
    app(CurrentCompany::class)->set($this->company->id);

    $this->warehouse = $this->company->warehouses()->where('is_default', true)->firstOrFail();

    $this->telefono = Product::create([
        'sku' => 'TEL-13', 'name' => 'Teléfono 13', 'cost' => '20000', 'price' => '35000',
        'tracks_serials' => true,
    ]);
    $this->scan = app(SerialScanService::class);
});

function serie(string $s, ?string $condicion = null, ?string $precio = null): ScanUnitData
{
    return new ScanUnitData(serial: $s, condition: $condicion, price: $precio);
}

function stockDe(Product $p, int $almacen): string
{
    return (string) (Stock::query()->where('product_id', $p->id)->where('warehouse_id', $almacen)->value('quantity') ?? '0');
}

it('cada serial escaneado crea una unidad Y sube el stock por cantidad', function (): void {
    $r = $this->scan->alta($this->telefono, $this->warehouse, [
        serie('IMEI-001'), serie('IMEI-002'), serie('IMEI-003'),
    ]);

    expect($r['creadas'])->toBe(3)
        ->and(ProductUnit::count())->toBe(3)
        // LA INVARIANTE: tres unidades, tres de stock. Las dos cuentas cuadran.
        ->and((float) stockDe($this->telefono, $this->warehouse->id))->toBe(3.0)
        ->and($this->telefono->unidadesDisponibles($this->warehouse->id))->toBe(3);
});

it('cada unidad guarda su propia serie, condicion y precio', function (): void {
    $this->scan->alta($this->telefono, $this->warehouse, [
        serie('IMEI-A', 'nuevo', '35000'),
        serie('IMEI-B', 'usado', '22000'),   // el usado vale menos, y se respeta
    ]);

    $usado = ProductUnit::where('serial', 'IMEI-B')->firstOrFail();

    expect($usado->condition)->toBe('usado')
        ->and($usado->precioDeVenta())->toBe('22000.00')
        // La que no trae precio propio sigue al catálogo, no a un valor congelado.
        ->and(ProductUnit::where('serial', 'IMEI-A')->first()->precioDeVenta())->toBe('35000.00');
});

it('el kardex enlaza cada movimiento con su unidad', function (): void {
    $r = $this->scan->alta($this->telefono, $this->warehouse, [serie('IMEI-K')]);
    $unidad = $r['unidades'][0];

    $movimiento = StockMovement::query()
        ->where('reference_type', ProductUnit::class)
        ->where('reference_id', $unidad->id)
        ->first();

    expect($movimiento)->not->toBeNull()
        ->and($movimiento->quantity)->toBe('1.000');
});

/*
 * EL SERIAL DUPLICADO SE RECHAZA, NO SE IGNORA. Un IMEI repetido casi siempre es un doble escaneo;
 * dejarlo pasar crearía una unidad fantasma y descuadraría el stock justo donde más caro es.
 */
it('rechaza un serial repetido en la misma tanda, sin descuadrar el stock', function (): void {
    $r = $this->scan->alta($this->telefono, $this->warehouse, [
        serie('IMEI-X'), serie('IMEI-X'), serie('IMEI-Y'),
    ]);

    expect($r['creadas'])->toBe(2)                          // solo la primera X y la Y
        ->and($r['rechazados'])->toHaveCount(1)
        ->and($r['rechazados'][0]['serial'])->toBe('IMEI-X')
        // Y el stock subió 2, no 3: el rechazado no dejó rastro.
        ->and((float) stockDe($this->telefono, $this->warehouse->id))->toBe(2.0);
});

it('rechaza un serial que ya existe de un alta anterior', function (): void {
    $this->scan->alta($this->telefono, $this->warehouse, [serie('IMEI-VIEJO')]);

    $r = $this->scan->alta($this->telefono, $this->warehouse, [serie('IMEI-VIEJO'), serie('IMEI-NUEVO')]);

    expect($r['creadas'])->toBe(1)
        ->and($r['rechazados'][0]['serial'])->toBe('IMEI-VIEJO')
        ->and(ProductUnit::count())->toBe(2);   // el viejo + el nuevo, no tres
});

/*
 * Ni siquiera una serie de una unidad YA VENDIDA se puede reutilizar: la garantía se busca por serie,
 * y dos unidades con la misma llevarían a la equivocada.
 */
it('no reutiliza la serie de una unidad ya vendida', function (): void {
    $r = $this->scan->alta($this->telefono, $this->warehouse, [serie('IMEI-VENDIDO')]);
    $r['unidades'][0]->update(['status' => ProductUnit::VENDIDA, 'sold_at' => now()]);

    $reintento = $this->scan->alta($this->telefono, $this->warehouse, [serie('IMEI-VENDIDO')]);

    expect($reintento['creadas'])->toBe(0)
        ->and($reintento['rechazados'])->toHaveCount(1);
});

// ------------------------------------------------------------------ Lo que no se puede hacer

/*
 * CUALQUIER PRODUCTO SE SERIALIZA AL VUELO. Escanearle una serie a un producto que no la llevaba es
 * la forma de empezar a llevarlo por series: no hay que marcarlo antes.
 */
it('serializa al vuelo un producto que no lo estaba', function (): void {
    $generico = Product::create(['sku' => 'GEN-1', 'name' => 'Genérico', 'cost' => '500', 'price' => '900']);
    expect($generico->tracks_serials)->toBeFalsy();  // recien creado: aun sin cast, es null

    $r = $this->scan->alta($generico, $this->warehouse, [serie('SN-001'), serie('SN-002')]);

    expect($r['creadas'])->toBe(2)
        // Quedó marcado como serializado, sin que nadie tocara el interruptor.
        ->and($generico->fresh()->tracks_serials)->toBeTrue()
        // Y con su stock, como cualquier alta.
        ->and((float) stockDe($generico, $this->warehouse->id))->toBe(2.0);
});

it('no da de alta una tanda vacia', function (): void {
    expect(fn () => $this->scan->alta($this->telefono, $this->warehouse, []))
        ->toThrow(SerialScanException::class);
});
