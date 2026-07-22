<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Core\Models\Company;
use App\Modules\Core\Tenancy\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(CurrentCompany::class)->forget();
    $this->super = User::create([
        'company_id' => null, 'is_super_admin' => true,
        'name' => 'Super', 'email' => 'super@onboard.test', 'password' => 'secret-password',
    ]);
});

function newCompanyPayload(array $overrides = []): array
{
    return array_merge([
        '_form' => 'company_create',
        'name' => 'Comercial La Nueva',
        'tax_id' => '131000009',
        'owner_name' => 'Dueño Nuevo',
        'owner_email' => 'dueno@lanueva.test',
        'owner_password' => 'contrasena-larga',
        'owner_password_confirmation' => 'contrasena-larga',
    ], $overrides);
}

it('el super admin crea una empresa con su propietario y su plan', function (): void {
    $this->actingAs($this->super)
        ->post(route('platform.companies.store'), newCompanyPayload(['modules' => ['pos', 'sales', 'inventory']]))
        ->assertRedirect();

    $company = Company::where('name', 'Comercial La Nueva')->firstOrFail();

    // La empresa nace con su plan, su sucursal y su almacén por defecto.
    expect($company->modules)->toBe(['pos', 'sales', 'inventory'])
        ->and($company->branches()->count())->toBe(1)
        ->and($company->warehouses()->count())->toBe(1);

    // El propietario existe, pertenece a la empresa y tiene el rol owner en su contexto.
    $owner = User::where('email', 'dueno@lanueva.test')->firstOrFail();
    app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);
    expect($owner->company_id)->toBe($company->id)
        ->and($owner->fresh()->hasRole('owner'))->toBeTrue();
});

it('sin módulos marcados la empresa arranca con el plan completo (NULL)', function (): void {
    $this->actingAs($this->super)
        ->post(route('platform.companies.store'), newCompanyPayload())
        ->assertRedirect();

    expect(Company::where('name', 'Comercial La Nueva')->value('modules'))->toBeNull();
});

it('el propietario recién creado puede iniciar sesión y entrar a su plan', function (): void {
    $this->actingAs($this->super)
        ->post(route('platform.companies.store'), newCompanyPayload(['modules' => ['pos', 'reports']]))
        ->assertRedirect();

    $owner = User::where('email', 'dueno@lanueva.test')->firstOrFail();

    // Entra a un módulo de su plan…
    $this->actingAs($owner)->get(route('panel.pos'))->assertOk();
    // …y no a uno fuera de él.
    $this->actingAs($owner)->get(route('panel.invoices'))->assertForbidden();
});

it('rechaza un correo de propietario ya usado', function (): void {
    User::create([
        'company_id' => null, 'name' => 'Existente',
        'email' => 'dueno@lanueva.test', 'password' => 'secret-password',
    ]);

    $this->actingAs($this->super)
        ->post(route('platform.companies.store'), newCompanyPayload())
        ->assertSessionHasErrors('owner_email');

    expect(Company::where('name', 'Comercial La Nueva')->exists())->toBeFalse();
});

it('un usuario que no es super admin no puede crear empresas', function (): void {
    $regular = User::create([
        'company_id' => null, 'name' => 'Normal',
        'email' => 'normal@onboard.test', 'password' => 'secret-password',
    ]);

    $this->actingAs($regular)
        ->post(route('platform.companies.store'), newCompanyPayload())
        ->assertForbidden();

    expect(Company::where('name', 'Comercial La Nueva')->exists())->toBeFalse();
});
