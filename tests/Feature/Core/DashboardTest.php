<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Tenancy\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutVite();
    app(CurrentCompany::class)->forget();
});

it('redirige a login cuando el usuario no está autenticado', function (): void {
    $this->get('/dashboard')->assertRedirect('/login');
});

it('muestra el dashboard con el contexto de la empresa del usuario', function (): void {
    $company = app(CompanyService::class)->create(new CreateCompanyData(name: 'Tenant HTTP'));

    $user = withRole(User::create([
        'company_id' => $company->id,
        'name' => 'Ana Gerente',
        'email' => 'ana@tenant.test',
        'password' => 'secret-password',
    ]));

    app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);
    $user->assignRole('owner');

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertOk()
        ->assertSee('Tenant HTTP')     // empresa activa resuelta por el middleware
        ->assertSee('Ana Gerente')
        ->assertSee('owner');          // rol en el contexto de la empresa
});
