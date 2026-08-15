<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Reports\Services\AlertService;
use App\Modules\Reports\Services\ReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(CurrentCompany::class)->forget();
    $this->company = app(CompanyService::class)->create(new CreateCompanyData(name: 'Alert Co'));
    $this->user = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Admin', 'email' => 'admin@alert.test', 'password' => 'secret-password',
    ]));
    app(CurrentCompany::class)->set($this->company->id);
    $this->warehouse = $this->company->warehouses()->where('is_default', true)->firstOrFail();
});

it('genera una alerta de stock bajo', function (): void {
    $product = Product::create(['sku' => 'LS', 'name' => 'Casi agotado', 'price' => '10']);
    app(StockService::class)->increase($product, $this->warehouse, StockMovementType::Purchase, '2');

    $alerts = app(AlertService::class)->forCurrentCompany();

    expect(collect($alerts)->firstWhere('key', 'low_stock'))->not->toBeNull()
        ->and(collect($alerts)->firstWhere('key', 'low_stock')['count'])->toBe(1);
});

it('no genera alertas cuando todo está en orden', function (): void {
    $product = Product::create(['sku' => 'OK', 'name' => 'Con stock', 'price' => '10']);
    app(StockService::class)->increase($product, $this->warehouse, StockMovementType::Purchase, '100');

    expect(app(AlertService::class)->forCurrentCompany())->toBe([]);
});

/*
 * Los tres de abajo cubren el aviso que no cuadraba con nada: con el inventario vacío, la campana
 * seguía diciendo «8 productos con stock bajo» y al pulsar el listado salía vacío.
 *
 * La causa era que la campana contaba filas de la tabla `stock` en vez de productos. Ninguno de los
 * tres casos lo habría detectado el test de arriba, porque con un solo producto en un solo almacén
 * y sin borrar nada, contar filas y contar productos da lo mismo.
 */

it('deja de avisar de un producto borrado', function (): void {
    // Al borrar un producto sus filas de existencia SIGUEN ahí (el borrado es lógico). Contando
    // filas, la alerta se volvía inmortal: nada de lo que hiciera el cliente la bajaba.
    $product = Product::create(['sku' => 'DEL', 'name' => 'Se va', 'price' => '10']);
    app(StockService::class)->increase($product, $this->warehouse, StockMovementType::Purchase, '2');

    $product->delete();
    Cache::flush(); // la campana se sirve un minuto desde caché

    expect(app(AlertService::class)->forCurrentCompany())->toBe([]);
});

it('cuenta el producto una vez aunque esté en varios almacenes', function (): void {
    // El aviso dice «productos». Contando filas, el mismo producto en dos almacenes decía 2.
    $otro = $this->company->warehouses()->create(['name' => 'Sucursal', 'is_default' => false]);

    $product = Product::create(['sku' => 'MW', 'name' => 'En dos sitios', 'price' => '10']);
    app(StockService::class)->increase($product, $this->warehouse, StockMovementType::Purchase, '1');
    app(StockService::class)->increase($product, $otro, StockMovementType::Purchase, '1');

    $alerta = collect(app(AlertService::class)->forCurrentCompany())->firstWhere('key', 'low_stock');

    expect($alerta['count'])->toBe(1)
        ->and($alerta['title'])->toBe('1 producto con stock bajo');
});

it('no avisa de lo que no lleva seguimiento de existencia', function (): void {
    // Para un servicio o algo que se hace al momento, «stock bajo» no significa nada.
    $product = Product::create(['sku' => 'SVC', 'name' => 'Servicio', 'price' => '10', 'track_stock' => false]);
    app(StockService::class)->increase($product, $this->warehouse, StockMovementType::Purchase, '1');

    expect(app(AlertService::class)->forCurrentCompany())->toBe([]);
});

it('la campana y el listado al que lleva enseñan la misma cifra', function (): void {
    // Es la promesa que el aviso le hace al cliente: si pulsa, verá exactamente eso. Cualquier
    // consulta nueva que se desvíe de Product::stockBajo() rompe este test.
    $bajo = Product::create(['sku' => 'B1', 'name' => 'Bajo', 'price' => '10']);
    app(StockService::class)->increase($bajo, $this->warehouse, StockMovementType::Purchase, '1');

    $borrado = Product::create(['sku' => 'B2', 'name' => 'Borrado', 'price' => '10']);
    app(StockService::class)->increase($borrado, $this->warehouse, StockMovementType::Purchase, '1');
    $borrado->delete();

    $sobrado = Product::create(['sku' => 'B3', 'name' => 'De sobra', 'price' => '10']);
    app(StockService::class)->increase($sobrado, $this->warehouse, StockMovementType::Purchase, '90');

    $campana = collect(app(AlertService::class)->forCurrentCompany())->firstWhere('key', 'low_stock');
    $listado = Product::query()->filtered(null, true)->count();
    $tarjeta = app(ReportService::class)->computeExecutiveSummary()['low_stock'];

    expect($campana['count'])->toBe(1)
        ->and($listado)->toBe(1)
        ->and($tarjeta)->toBe(1);
});

it('la campana muestra las alertas en la barra superior', function (): void {
    $this->withoutVite();
    $product = Product::create(['sku' => 'LS2', 'name' => 'Bajo', 'price' => '10']);
    app(StockService::class)->increase($product, $this->warehouse, StockMovementType::Purchase, '1');

    $this->actingAs($this->user)
        ->get('/dashboard')
        ->assertOk()
        ->assertSee('stock bajo');
});
