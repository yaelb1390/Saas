<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\Inventory\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(CurrentCompany::class)->forget();
    $this->companyA = app(CompanyService::class)->create(new CreateCompanyData(name: 'Empresa A'));
    $this->companyB = app(CompanyService::class)->create(new CreateCompanyData(name: 'Empresa B'));

    $this->super = User::create([
        'company_id' => null, 'is_super_admin' => true,
        'name' => 'Super', 'email' => 'super@switch.test', 'password' => 'secret-password',
    ]);

    // Un producto que solo existe en la empresa B.
    app(CurrentCompany::class)->set($this->companyB->id);
    Product::create(['sku' => 'PB', 'name' => 'Producto de B', 'price' => '10']);
    app(CurrentCompany::class)->forget();
});

it('el super admin cambia de empresa y ve los datos de esa empresa', function (): void {
    $this->actingAs($this->super)
        ->post(route('panel.company.switch', $this->companyB))
        ->assertRedirect(route('dashboard'));

    $this->actingAs($this->super)
        ->get(route('panel.products'))
        ->assertOk()
        ->assertSee('Producto de B');
});

it('un usuario de empresa no puede cambiar de empresa', function (): void {
    $userA = User::create([
        'company_id' => $this->companyA->id, 'name' => 'Empleado A',
        'email' => 'a@switch.test', 'password' => 'secret-password',
    ]);

    $this->actingAs($userA)
        ->post(route('panel.company.switch', $this->companyB))
        ->assertForbidden();
});
