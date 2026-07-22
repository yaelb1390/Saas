<?php

declare(strict_types=1);

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

/*
 * La caché de indicadores/alertas se guarda por empresa. Estos tests defienden lo único que puede
 * salir MAL al cachear en un sistema multiempresa: que los datos de una empresa se sirvan a otra.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Cache::flush();
    app(CurrentCompany::class)->forget();
});

/**
 * Crea una empresa con N productos y deja el tenant activo apuntando a ella.
 */
function companyWithProducts(string $name, int $products): object
{
    $company = app(CompanyService::class)->create(new CreateCompanyData(name: $name));
    app(CurrentCompany::class)->set($company->id);

    $warehouse = $company->warehouses()->where('is_default', true)->firstOrFail();
    for ($i = 1; $i <= $products; $i++) {
        $p = Product::create(['sku' => "{$name}-{$i}", 'name' => "Prod {$i}", 'cost' => '1', 'price' => '10']);
        app(StockService::class)->increase($p, $warehouse, StockMovementType::Purchase, '50');
    }

    return $company;
}

it('el resumen cacheado NO se filtra entre empresas', function (): void {
    $a = companyWithProducts('Alfa', 2);
    $summaryA = app(ReportService::class)->executiveSummary();

    $b = companyWithProducts('Beta', 5);
    $summaryB = app(ReportService::class)->executiveSummary();

    expect($summaryA['products'])->toBe(2)
        ->and($summaryB['products'])->toBe(5);

    // Volver a la primera empresa debe devolver SU dato, no el de la última consultada.
    app(CurrentCompany::class)->set($a->id);
    expect(app(ReportService::class)->executiveSummary()['products'])->toBe(2);
});

it('las alertas cacheadas NO se filtran entre empresas', function (): void {
    // Alfa: 2 productos con stock 50 (sin alerta de stock bajo).
    $a = companyWithProducts('Alfa', 2);
    expect(app(AlertService::class)->forCurrentCompany())->toBe([]);

    // Beta: un producto con stock bajo → sí genera alerta.
    $b = companyWithProducts('Beta', 1);
    $warehouse = $b->warehouses()->where('is_default', true)->firstOrFail();
    $low = Product::create(['sku' => 'BETA-LOW', 'name' => 'Poco', 'cost' => '1', 'price' => '10']);
    app(StockService::class)->increase($low, $warehouse, StockMovementType::Purchase, '1');

    $alertsB = app(AlertService::class)->forCurrentCompany();
    expect($alertsB)->not->toBe([]);

    // Alfa sigue sin alertas: no hereda las de Beta.
    app(CurrentCompany::class)->set($a->id);
    expect(app(AlertService::class)->forCurrentCompany())->toBe([]);
});

it('el resumen se sirve de caché dentro del minuto y refleja el dato al expirar', function (): void {
    $company = companyWithProducts('Gamma', 1);

    expect(app(ReportService::class)->executiveSummary()['products'])->toBe(1);

    // Se añade un producto: el valor cacheado no cambia todavía.
    Product::create(['sku' => 'G-2', 'name' => 'Nuevo', 'cost' => '1', 'price' => '10']);
    expect(app(ReportService::class)->executiveSummary()['products'])->toBe(1);

    // El cálculo fresco (sin caché) sí lo ve: confirma que solo era la caché, no un dato perdido.
    expect(app(ReportService::class)->computeExecutiveSummary()['products'])->toBe(2);

    // Al vencer la caché, el resumen se pone al día.
    Cache::forget("company:{$company->id}:executive-summary");
    expect(app(ReportService::class)->executiveSummary()['products'])->toBe(2);
});
