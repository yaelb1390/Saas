<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\CRM\Models\Customer;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Loans\DTOs\CreateLoanData;
use App\Modules\Loans\Enums\LoanFrequency;
use App\Modules\Loans\Services\LoanService;
use App\Modules\Reports\Services\ReportService;
use App\Modules\Sales\DTOs\CreateSaleData;
use App\Modules\Sales\DTOs\SaleLineData;
use App\Modules\Sales\Services\SaleService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(CurrentCompany::class)->forget();
    $this->company = app(CompanyService::class)->create(new CreateCompanyData(name: 'Charts Co'));
    app(CurrentCompany::class)->set($this->company->id);
    $this->reports = app(ReportService::class);
});

/**
 * Atajo: crea un préstamo simple para la empresa activa.
 *
 * @param  array<string, mixed>  $o
 */
function chartLoan(array $o = [])
{
    $customer = Customer::create(['name' => $o['customer'] ?? 'Cliente Gráfico']);

    return app(LoanService::class)->create(new CreateLoanData(
        customerId: $customer->id,
        principal: $o['principal'] ?? '10000',
        installmentsCount: $o['count'] ?? 4,
        frequency: LoanFrequency::Monthly,
        startDate: '2026-08-01',
        interestRate: $o['rate'] ?? '20',
    ));
}

it('collectionsTrend devuelve 14 días con ceros y suma el cobro de hoy', function (): void {
    $loan = chartLoan();
    app(LoanService::class)->registerPayment($loan, '3000');

    $trend = $this->reports->collectionsTrend();

    expect($trend)->toHaveCount(14)
        ->and($trend[now()->format('Y-m-d')])->toBe(3000.0)
        ->and($trend[now()->subDays(5)->format('Y-m-d')])->toBe(0.0);
});

it('collectionsTrend está aislado por empresa', function (): void {
    // Otra empresa registra un cobro; no debe contaminar la serie de la empresa principal.
    $otra = app(CompanyService::class)->create(new CreateCompanyData(name: 'Otra Co'));
    app(CurrentCompany::class)->set($otra->id);
    $loanAjeno = chartLoan(['customer' => 'Ajeno', 'principal' => '5000', 'count' => 1, 'rate' => '0']);
    app(LoanService::class)->registerPayment($loanAjeno, '5000');

    app(CurrentCompany::class)->set($this->company->id);

    expect(array_sum($this->reports->collectionsTrend()))->toBe(0.0);
});

it('salesTrend suma las ventas completadas de hoy', function (): void {
    $warehouse = $this->company->warehouses()->where('is_default', true)->firstOrFail();
    $product = Product::create(['sku' => 'P1', 'name' => 'Prod', 'cost' => '10', 'price' => '50']);
    app(StockService::class)->increase($product, $warehouse, StockMovementType::Purchase, '10');
    app(SaleService::class)->complete(new CreateSaleData(
        warehouseId: $warehouse->id,
        lines: [new SaleLineData(productId: $product->id, quantity: '2', unitPrice: '50')],
        paid: '200',
        customerName: 'Cliente',
    ));

    $trend = $this->reports->salesTrend();

    expect($trend)->toHaveCount(14)
        ->and($trend[now()->format('Y-m-d')])->toBe(100.0);
});

it('loanStatusCounts cuenta vigentes y saldados de la empresa', function (): void {
    $paid = chartLoan(['principal' => '1000', 'count' => 1, 'rate' => '0']);
    app(LoanService::class)->registerPayment($paid, '1000'); // queda saldado
    chartLoan(['principal' => '2000', 'count' => 2, 'rate' => '0']); // queda vigente

    expect($this->reports->loanStatusCounts())->toBe(['active' => 1, 'paid' => 1]);
});

it('el dashboard muestra la sección Análisis para una empresa con préstamos', function (): void {
    $user = withRole(User::create([
        'company_id' => $this->company->id,
        'name' => 'Dueño',
        'email' => 'duenio@charts.test',
        'password' => 'secret-password',
    ]));

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertOk()
        ->assertSee('Análisis')
        ->assertSee('Cobros por día');
});

it('el dashboard oculta Análisis si la empresa no tiene préstamos ni ventas', function (): void {
    // Restringimos los módulos a solo CRM: sin loans ni sales, no hay gráficos que mostrar.
    $this->company->update(['modules' => ['crm']]);
    app(CurrentCompany::class)->forget();

    $user = withRole(User::create([
        'company_id' => $this->company->id,
        'name' => 'Dueño CRM',
        'email' => 'crm@charts.test',
        'password' => 'secret-password',
    ]));

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertOk()
        ->assertDontSee('Análisis');
});
