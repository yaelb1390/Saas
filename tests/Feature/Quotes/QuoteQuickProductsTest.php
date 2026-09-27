<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Sales\DTOs\CreateSaleData;
use App\Modules\Sales\DTOs\SaleLineData;
use App\Modules\Sales\Services\SaleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

/*
 * "Productos rápidos" en Nueva cotización: los mismos más-vendidos que ya se ofrecen en el
 * mostrador (MasVendidos + ProductLookupPresenter), para no tener que abrir el desplegable y
 * buscar cada vez que se cotiza lo de siempre. Se reutiliza el servicio tal cual: aquí solo se
 * prueba que la pantalla de cotizar los pida y los pinte, y que un producto que ya no se puede
 * vender no aparezca (el <select> no tendría con qué emparejarlo).
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(CurrentCompany::class)->forget();
    $this->company = app(CompanyService::class)->create(new CreateCompanyData(name: 'Cafetería Yasmehilin'));
    $this->owner = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Dueña',
        'email' => 'duena@cafeteria.test', 'password' => 'secret-password',
    ]));
    app(CurrentCompany::class)->set($this->company->id);

    $this->warehouse = $this->company->warehouses()->where('is_default', true)->firstOrFail();
});

/** Una venta completada de $cantidad unidades de $producto, para que cuente como "vendido". */
function venderParaEstadistica(Product $producto, string $cantidad): void
{
    app(SaleService::class)->complete(new CreateSaleData(
        warehouseId: test()->warehouse->id,
        lines: [new SaleLineData(productId: $producto->id, quantity: $cantidad, unitPrice: (string) $producto->price)],
        paid: '100000',
    ));
}

it('la pantalla de nueva cotización trae los más vendidos como productos rápidos', function (): void {
    $pollo = Product::create(['sku' => 'POL-1', 'name' => 'Pollo Solo', 'cost' => '40', 'price' => '75']);
    app(StockService::class)->increase($pollo, $this->warehouse, StockMovementType::Purchase, '50');
    venderParaEstadistica($pollo, '5');

    Cache::flush();

    $respuesta = $this->actingAs($this->owner)->get(route('panel.quotes.create'));

    $respuesta->assertOk()
        ->assertSee('Productos rápidos')
        ->assertSee('Pollo Solo');
});

it('un producto desactivado no sale como rápido: el desplegable no tendría con qué emparejarlo', function (): void {
    $pollo = Product::create(['sku' => 'POL-1', 'name' => 'Pollo Solo', 'cost' => '40', 'price' => '75']);
    app(StockService::class)->increase($pollo, $this->warehouse, StockMovementType::Purchase, '50');
    venderParaEstadistica($pollo, '5');
    $pollo->update(['is_active' => false]);

    Cache::flush();

    $this->actingAs($this->owner)->get(route('panel.quotes.create'))
        ->assertOk()
        ->assertDontSee('Pollo Solo');
});

it('sin ventas todavía, no se pinta la sección de productos rápidos', function (): void {
    Cache::flush();

    $this->actingAs($this->owner)->get(route('panel.quotes.create'))
        ->assertOk()
        ->assertDontSee('Productos rápidos');
});
