<?php

declare(strict_types=1);

use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\Company;
use App\Modules\Core\Tenancy\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // Contexto de plataforma (sin tenant) para preparar datos de dos empresas.
    app(CurrentCompany::class)->forget();

    $this->companyA = Company::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
    $this->companyB = Company::create(['name' => 'Empresa B', 'slug' => 'empresa-b']);
});

it('asigna company_id automáticamente al crear con un tenant activo', function (): void {
    app(CurrentCompany::class)->set($this->companyA->id);

    $branch = Branch::create(['name' => 'Sucursal Auto']);

    expect($branch->company_id)->toBe($this->companyA->id);
});

it('aísla las consultas a la empresa activa', function (): void {
    Branch::create(['company_id' => $this->companyA->id, 'name' => 'A1']);
    Branch::create(['company_id' => $this->companyA->id, 'name' => 'A2']);
    Branch::create(['company_id' => $this->companyB->id, 'name' => 'B1']);

    app(CurrentCompany::class)->set($this->companyA->id);
    expect(Branch::count())->toBe(2)
        ->and(Branch::pluck('name')->all())->toEqualCanonicalizing(['A1', 'A2']);

    app(CurrentCompany::class)->set($this->companyB->id);
    expect(Branch::count())->toBe(1)
        ->and(Branch::first()->name)->toBe('B1');
});

it('no aplica filtro cuando no hay tenant activo (plataforma)', function (): void {
    Branch::create(['company_id' => $this->companyA->id, 'name' => 'A1']);
    Branch::create(['company_id' => $this->companyB->id, 'name' => 'B1']);

    app(CurrentCompany::class)->forget();

    expect(Branch::count())->toBe(2);
});

it('withoutCompanyScope ignora el aislamiento de forma explícita', function (): void {
    Branch::create(['company_id' => $this->companyA->id, 'name' => 'A1']);
    Branch::create(['company_id' => $this->companyB->id, 'name' => 'B1']);

    app(CurrentCompany::class)->set($this->companyA->id);

    expect(Branch::count())->toBe(1)
        ->and(Branch::withoutCompanyScope()->count())->toBe(2);
});
