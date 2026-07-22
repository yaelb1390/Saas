<?php

declare(strict_types=1);

use App\Modules\Billing\Enums\NcfType;
use App\Modules\Billing\Models\FiscalSequence;
use App\Modules\Billing\Services\InvoiceService;
use App\Modules\Core\Cache\CompanyCache;
use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\CRM\Models\Customer;
use App\Modules\CRM\Services\CustomerPortalService;
use App\Modules\Delivery\Services\DeliveryService;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Sales\DTOs\CreateSaleData;
use App\Modules\Sales\DTOs\SaleLineData;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Services\SaleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/*
 * El portal del cliente cachea sus tres listas (facturas, ventas, entregas) por empresa y las
 * invalida por evento de dominio, no por tiempo. Estos tests defienden las dos garantías: que una
 * lectura repetida NO vuelve a la base de datos, y que una mutación SÍ la refresca.
 */

uses(RefreshDatabase::class);

/**
 * Completa una venta para el cliente en la empresa activa. Devuelve la venta creada.
 */
function cacheSaleFor(Customer $customer, string $sku, string $price = '50'): Sale
{
    $company = app(CurrentCompany::class)->model();
    $warehouse = $company->warehouses()->where('is_default', true)->firstOrFail();
    $product = Product::create(['sku' => $sku, 'name' => 'Producto Cache', 'cost' => '10', 'price' => $price]);
    app(StockService::class)->increase($product, $warehouse, StockMovementType::Purchase, '10');

    return app(SaleService::class)->complete(new CreateSaleData(
        warehouseId: $warehouse->id,
        lines: [new SaleLineData(productId: $product->id, quantity: '1', unitPrice: $price)],
        paid: $price,
        customerId: $customer->id,
    ));
}

/**
 * Cuenta cuántas consultas SELECT tocan las tablas de las listas del portal durante $callback.
 */
function portalListQueries(Closure $callback): int
{
    $count = 0;
    DB::listen(function ($query) use (&$count): void {
        foreach (['"sales"', '"invoices"', '"deliveries"'] as $table) {
            if (str_contains($query->sql, $table)) {
                $count++;

                return;
            }
        }
    });

    $callback();

    // Se quita el listener para no contaminar el resto del test (Laravel no expone un forget directo,
    // pero cada test corre en su propio contenedor por RefreshDatabase).
    return $count;
}

beforeEach(function (): void {
    Cache::flush();
    app(CurrentCompany::class)->forget();

    $this->company = app(CompanyService::class)->create(new CreateCompanyData(name: 'Cache Co'));
    app(CurrentCompany::class)->set($this->company->id);

    $this->customer = Customer::create(['name' => 'Cliente Cache', 'phone' => '18095550000']);
    $this->portal = app(CustomerPortalService::class);
});

it('la segunda visita al portal no vuelve a consultar las listas', function (): void {
    // Datos en las tres listas: una venta, su factura y una entrega.
    $sale = cacheSaleFor($this->customer, 'CACHE-1');
    FiscalSequence::create([
        'type' => NcfType::Consumo, 'next_number' => 1,
        'range_from' => 1, 'range_to' => 1000, 'is_active' => true,
    ]);
    app(InvoiceService::class)->issueForSale($sale, NcfType::Consumo);
    app(DeliveryService::class)->create('Calle Cache #1', sale: $sale);

    $link = $this->portal->linkFor($this->customer);

    // Primera visita: calienta la caché (aquí SÍ se consultan las listas).
    $this->get($link)->assertOk();

    // Segunda visita: debe salir de caché sin tocar sales/invoices/deliveries.
    $queries = portalListQueries(function () use ($link, $sale): void {
        $this->get($link)->assertOk()->assertSee($sale->code);
    });

    expect($queries)->toBe(0);
});

it('completar una nueva venta invalida la caché y la refleja en la siguiente visita', function (): void {
    $primera = cacheSaleFor($this->customer, 'CACHE-A');
    $link = $this->portal->linkFor($this->customer);

    // Se calienta la caché con solo la primera venta.
    $this->get($link)->assertOk()->assertSee($primera->code)->assertDontSee('SEGUNDA');

    // Nueva venta del cliente: dispara SaleCompleted, que incrementa la versión de caché de la empresa.
    $segunda = cacheSaleFor($this->customer, 'SEGUNDA');

    // La siguiente visita ve la venta nueva: la caché quedó invalidada por el evento, no por tiempo.
    $this->get($link)->assertOk()
        ->assertSee($primera->code)
        ->assertSee($segunda->code);
});

it('invalidar la caché de una empresa no afecta la de otra', function (): void {
    $cache = app(CompanyCache::class);
    $otra = app(CompanyService::class)->create(new CreateCompanyData(name: 'Otra Cache SRL'));

    // Ambas empresas cachean un recurso bajo la misma etiqueta lógica.
    $valorA = $cache->remember($this->company->id, 'portal:probe', fn (): string => 'A');
    $valorB = $cache->remember($otra->id, 'portal:probe', fn (): string => 'B');
    expect($valorA)->toBe('A')->and($valorB)->toBe('B');

    // Se invalida solo la empresa A.
    $cache->flush($this->company->id);

    // A recalcula (nueva versión); B sigue sirviéndose de su caché intacta.
    expect($cache->remember($this->company->id, 'portal:probe', fn (): string => 'A2'))->toBe('A2')
        ->and($cache->remember($otra->id, 'portal:probe', fn (): string => 'B2'))->toBe('B');
});
