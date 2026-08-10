<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Models\Warehouse;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Reports\Services\ReportService;
use App\Modules\Sales\DTOs\CreateSaleData;
use App\Modules\Sales\DTOs\SaleLineData;
use App\Modules\Sales\Services\SaleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

/*
 * El módulo de Reportes solo puede enseñar lo de la empresa activa.
 *
 * El aislamiento lo da el CompanyScope, pero los reportes tienen un segundo camino por el que se
 * puede escapar y que ningún scope vigila: la CACHÉ. Si una clave olvidara el id de empresa, la
 * segunda empresa que abriera la pantalla vería las cifras de la primera —sin una sola consulta mal
 * escrita—. Por eso aquí se prueban los dos caminos.
 */

uses(RefreshDatabase::class);

/** Crea una empresa con su dueño y le registra una venta del importe indicado. */
function empresaConVenta(string $nombre, string $importe, string $correo): array
{
    app(CurrentCompany::class)->forget();
    $company = app(CompanyService::class)->create(new CreateCompanyData(name: $nombre));
    app(CurrentCompany::class)->set($company->id);

    $owner = withRole(User::create([
        'company_id' => $company->id, 'name' => "Dueño {$nombre}",
        'email' => $correo, 'password' => 'secret-password',
    ]), 'owner');

    $product = Product::create(['sku' => 'P', 'name' => 'Producto', 'cost' => '1', 'price' => $importe]);
    $warehouse = Warehouse::query()->where('is_default', true)->orderBy('id')->first();
    app(StockService::class)->increase($product, $warehouse, StockMovementType::Initial, '100');

    app(SaleService::class)->complete(new CreateSaleData(
        warehouseId: (int) $warehouse->id,
        lines: [new SaleLineData(productId: (int) $product->id, quantity: '1', unitPrice: $importe)],
        paid: $importe,
    ));

    return [$company, $owner];
}

/** Cambia la empresa activa y limpia la instancia cacheada del tenant. */
function activar(int $companyId): void
{
    app(CurrentCompany::class)->forget();
    app(CurrentCompany::class)->set($companyId);
}

it('cada empresa ve solo sus propias ventas en el resumen', function (): void {
    [$uno] = empresaConVenta('Heladería Uno', '100', 'uno@r.test');
    [$dos] = empresaConVenta('Heladería Dos', '777', 'dos@r.test');

    activar((int) $uno->id);
    expect(app(ReportService::class)->executiveSummary()['sales_total'])->toBe('100.00');

    activar((int) $dos->id);
    expect(app(ReportService::class)->executiveSummary()['sales_total'])->toBe('777.00');
});

it('la caché del resumen no se filtra entre empresas', function (): void {
    // Este es el fallo silencioso que ningún scope atrapa: si la clave de caché perdiera el id de
    // empresa, la segunda vería las cifras de la primera aunque las consultas fueran correctas.
    [$uno] = empresaConVenta('Cacheada Uno', '100', 'cuno@r.test');
    [$dos] = empresaConVenta('Cacheada Dos', '777', 'cdos@r.test');

    activar((int) $uno->id);
    app(ReportService::class)->executiveSummary(); // deja la caché caliente

    activar((int) $dos->id);

    expect(app(ReportService::class)->executiveSummary()['sales_total'])->toBe('777.00');
});

it('la caché de las tendencias tampoco se filtra', function (): void {
    [$uno] = empresaConVenta('Tendencia Uno', '100', 'tuno@r.test');
    [$dos] = empresaConVenta('Tendencia Dos', '777', 'tdos@r.test');

    activar((int) $uno->id);
    $serieUno = app(ReportService::class)->salesTrend();

    activar((int) $dos->id);
    $serieDos = app(ReportService::class)->salesTrend();

    expect(array_sum($serieUno))->toBe(100.0)
        ->and(array_sum($serieDos))->toBe(777.0);
});

it('el reporte por fechas solo cuenta las ventas de la empresa activa', function (): void {
    [$uno] = empresaConVenta('Fechas Uno', '100', 'funo@r.test');
    empresaConVenta('Fechas Dos', '777', 'fdos@r.test');

    activar((int) $uno->id);
    $reporte = app(ReportService::class)->salesReport(now()->subDay(), now()->addDay());

    expect($reporte['total'])->toBe('100.00')
        ->and($reporte['count'])->toBe(1);
});

it('la pantalla de reportes no muestra cifras de otra empresa', function (): void {
    [$uno, $ownerUno] = empresaConVenta('Pantalla Uno', '100', 'puno@r.test');
    empresaConVenta('Pantalla Dos', '777777', 'pdos@r.test');

    activar((int) $uno->id);

    $this->actingAs($ownerUno)->get(route('panel.reports'))
        ->assertOk()
        // El importe llamativo de la otra empresa no puede aparecer por ninguna parte.
        ->assertDontSee('777,777')
        ->assertDontSee('777777');
});

it('la exportación de ventas tampoco cruza empresas', function (): void {
    [$uno, $ownerUno] = empresaConVenta('Export Uno', '100', 'euno@r.test');
    empresaConVenta('Export Dos', '777777', 'edos@r.test');

    activar((int) $uno->id);

    $csv = $this->actingAs($ownerUno)->get(route('panel.export.sales'))
        ->assertOk()->streamedContent();

    // Una sola fila de datos —la venta propia— además de la cabecera, y ni rastro de la ajena.
    $filas = array_filter(explode("\n", trim($csv)));

    expect($filas)->toHaveCount(2)
        ->and($csv)->toContain('V-000001')
        ->and($csv)->not->toContain('777777');
});

it('al cambiar de empresa, el resumen cambia con ella', function (): void {
    // Es el flujo del super administrador: una sola sesión, dos empresas, y en ningún momento debe
    // arrastrar las cifras de la anterior.
    [$uno] = empresaConVenta('Cambio Uno', '100', 'kuno@r.test');
    [$dos] = empresaConVenta('Cambio Dos', '777', 'kdos@r.test');

    $vistas = [];

    foreach ([$uno->id, $dos->id, $uno->id] as $id) {
        activar((int) $id);
        $vistas[] = app(ReportService::class)->executiveSummary()['sales_total'];
    }

    expect($vistas)->toBe(['100.00', '777.00', '100.00']);
});

it('las claves de caché del resumen llevan el id de la empresa', function (): void {
    [$uno] = empresaConVenta('Clave Uno', '100', 'lluno@r.test');

    activar((int) $uno->id);
    app(ReportService::class)->executiveSummary();

    // Comprobación directa del contrato: si alguien quita el id de la clave, esto falla.
    expect(Cache::has("company:{$uno->id}:executive-summary"))->toBeTrue()
        ->and(Cache::has('executive-summary'))->toBeFalse();
});
