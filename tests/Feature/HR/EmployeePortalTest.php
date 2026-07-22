<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\HR\DTOs\CreateEmployeeData;
use App\Modules\HR\Services\HrService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutVite();
    app(CurrentCompany::class)->forget();
    $this->company = app(CompanyService::class)->create(new CreateCompanyData(name: 'Portal Co'));
    app(CurrentCompany::class)->set($this->company->id);
});

it('el empleado ve su ficha y asistencias en el portal', function (): void {
    $user = withRole(User::create([
        'company_id' => $this->company->id,
        'name' => 'Empleado Uno',
        'email' => 'empleado@portal.test',
        'password' => 'secret-password',
    ]));

    $employee = app(HrService::class)->hire(new CreateEmployeeData(
        name: 'Empleado Uno',
        position: 'Cajero',
        userId: $user->id,
    ));
    app(HrService::class)->clockIn($employee);

    $this->actingAs($user)
        ->get('/portal/perfil')
        ->assertOk()
        ->assertSee('Empleado Uno')
        ->assertSee('Cajero');
});
