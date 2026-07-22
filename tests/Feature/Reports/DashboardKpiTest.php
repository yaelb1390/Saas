<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\Reports\Services\ReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutVite();
    app(CurrentCompany::class)->forget();
    $this->company = app(CompanyService::class)->create(new CreateCompanyData(name: 'KPI Co'));
});

it('el resumen ejecutivo devuelve los indicadores esperados', function (): void {
    app(CurrentCompany::class)->set($this->company->id);

    $summary = app(ReportService::class)->executiveSummary();

    expect($summary)->toHaveKeys([
        'sales_total', 'sales_count', 'cash_balance',
        'open_opportunities', 'pending_deliveries', 'products', 'low_stock',
    ]);
});

it('el dashboard muestra el resumen ejecutivo', function (): void {
    $user = withRole(User::create([
        'company_id' => $this->company->id,
        'name' => 'Gerente',
        'email' => 'gerente@kpi.test',
        'password' => 'secret-password',
    ]));

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertOk()
        ->assertSee('Resumen ejecutivo')
        ->assertSee('Balance de caja');
});
