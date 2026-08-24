<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Models\Warehouse;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\CRM\Enums\OpportunityStatus;
use App\Modules\CRM\Models\Customer;
use App\Modules\CRM\Models\Opportunity;
use App\Modules\CRM\Models\Pipeline;
use App\Modules\Inventory\Models\Product;
use App\Modules\Reports\Services\ReportService;
use App\Modules\Sales\Enums\SaleStatus;
use App\Modules\Sales\Models\Sale;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * Cómo estaban las cifras hace un mes.
 *
 * No hay tabla de fotos diarias, así que el pasado se RECONSTRUYE de las marcas de tiempo que ya
 * existen. Eso es exactamente lo que hay que vigilar: una reconstrucción mal hecha no falla ni avisa
 * —devuelve un número perfectamente creíble— y el porcentaje que sale de ahí se lee como un dato
 * medido. Un «−40 % en ventas» falso hace tomar decisiones falsas.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(CurrentCompany::class)->forget();
    $this->company = app(CompanyService::class)->create(new CreateCompanyData(name: 'Tendencias SRL'));
    withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Admin',
        'email' => 'admin@tendencias.test', 'password' => 'secret-password',
    ]));
    app(CurrentCompany::class)->set($this->company->id);
});

/** Una venta completada con fecha, que es lo único que aquí importa. */
function ventaDeHace(int $dias, string $total): Sale
{
    $venta = Sale::create([
        'code' => 'V-'.$dias.'-'.random_int(1000, 9999),
        'status' => SaleStatus::Completed,
        'warehouse_id' => Warehouse::query()->firstOrFail()->id,
        'subtotal' => $total, 'tax' => '0', 'total' => $total, 'payment_method' => 'cash',
    ]);

    // A pelo y sin tocar updated_at: `created_at` es el eje de toda la reconstrucción.
    $venta->forceFill(['created_at' => now()->subDays($dias)])->saveQuietly();

    return $venta->refresh();
}

it('las ventas de hace un mes no incluyen las de después', function (): void {
    ventaDeHace(45, '1000');   // dentro del «antes»
    ventaDeHace(10, '500');    // después del corte: solo en «ahora»

    $t = app(ReportService::class)->computeExecutiveTrends(30);

    expect($t['sales_total']['antes'])->toBe(1000.0)
        ->and($t['sales_total']['ahora'])->toBe(1500.0);
});

it('una oportunidad cerrada después del corte contaba como abierta entonces', function (): void {
    /*
     * El caso que distingue reconstruir de contar.
     *
     * Contar las abiertas de HOY y llamarlas «las de hace un mes» daría cero y un −100 % inventado.
     * Lo que se pregunta es otra cosa: ¿estaba abierta ENTONCES? Y lo estaba, porque se cerró
     * después.
     */
    $cliente = Customer::create(['name' => 'Cliente']);
    $pipeline = Pipeline::query()->where('is_default', true)->firstOrFail();

    $op = Opportunity::create([
        'customer_id' => $cliente->id, 'pipeline_id' => $pipeline->id,
        'stage_id' => $pipeline->stages()->orderBy('position')->firstOrFail()->id,
        'title' => 'Cerrada la semana pasada', 'amount' => '5000',
        'status' => OpportunityStatus::Won, 'closed_at' => now()->subDays(7),
    ]);
    $op->forceFill(['created_at' => now()->subDays(60)])->saveQuietly();

    $t = app(ReportService::class)->computeExecutiveTrends(30);

    expect($t['open_opportunities']['antes'])->toBe(1.0)
        ->and($t['open_opportunities']['ahora'])->toBe(0.0);
});

it('un producto creado después del corte no existía entonces', function (): void {
    $viejo = Product::create(['sku' => 'VIEJO', 'name' => 'De siempre', 'price' => '10']);
    $viejo->forceFill(['created_at' => now()->subDays(90)])->saveQuietly();

    Product::create(['sku' => 'NUEVO', 'name' => 'De esta semana', 'price' => '10']);

    $t = app(ReportService::class)->computeExecutiveTrends(30);

    expect($t['products']['antes'])->toBe(1.0)
        ->and($t['products']['ahora'])->toBe(2.0);
});

it('un producto borrado esta semana sí existía hace un mes', function (): void {
    // Con borrado suave la fecha queda guardada, así que el pasado se puede contar bien. Ignorarla
    // haría desaparecer del pasado todo lo que se borró después, y el catálogo parecería crecer.
    $borrado = Product::create(['sku' => 'FUERA', 'name' => 'Descatalogado', 'price' => '10']);
    $borrado->forceFill(['created_at' => now()->subDays(90)])->saveQuietly();
    $borrado->delete();

    $t = app(ReportService::class)->computeExecutiveTrends(30);

    expect($t['products']['antes'])->toBe(1.0)
        ->and($t['products']['ahora'])->toBe(0.0);
});

it('no inventa la tendencia de lo que no puede reconstruir', function (): void {
    /*
     * «Entregas pendientes» y «stock bajo» no llevan tendencia porque no hay de dónde sacarla: una
     * entrega cancelada no deja marca de cuándo dejó de estar pendiente y las existencias no guardan
     * su historia. Antes que un porcentaje supuesto —que se lee igual que uno medido—, ninguno.
     */
    $t = app(ReportService::class)->computeExecutiveTrends(30);

    expect($t)->not->toHaveKey('pending_deliveries')
        ->and($t)->not->toHaveKey('low_stock');
});

it('las tendencias de otra empresa no se cuelan en las de esta', function (): void {
    $otra = app(CompanyService::class)->create(new CreateCompanyData(name: 'La de al lado'));
    app(CurrentCompany::class)->set($otra->id);
    ventaDeHace(45, '99999');

    app(CurrentCompany::class)->set($this->company->id);
    ventaDeHace(45, '1000');

    $t = app(ReportService::class)->computeExecutiveTrends(30);

    expect($t['sales_total']['antes'])->toBe(1000.0)
        ->and($t['sales_total']['ahora'])->toBe(1000.0);
});
