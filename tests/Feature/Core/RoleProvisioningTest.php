<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Services\RoleProvisioner;
use App\Modules\Core\Tenancy\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(CurrentCompany::class)->forget();
});

it('provisiona roles y permisos por empresa al crearla', function (): void {
    $company = app(CompanyService::class)->create(new CreateCompanyData(name: 'Acme'));

    $roles = Role::where('company_id', $company->id)->pluck('name');

    expect($roles)->toContain('owner', 'admin', 'staff', 'driver');

    app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);
    expect(Role::findByName('owner', 'web')->hasPermissionTo('company.manage'))->toBeTrue()
        ->and(Role::findByName('staff', 'web')->hasPermissionTo('company.manage'))->toBeFalse();
});

it('mantiene los roles aislados entre empresas', function (): void {
    // Cada empresa tiene su juego COMPLETO de roles, sin compartir ninguno: los permisos son
    // globales, pero los roles pertenecen a un tenant (spatie con equipos).
    //
    // El número sale de RoleProvisioner y no se escribe a mano: cuando se añadió «driver» este test
    // se puso en rojo por decir 3, que era la cifra de entonces y no la regla.
    $cuantos = count(RoleProvisioner::ROLES);

    $a = app(CompanyService::class)->create(new CreateCompanyData(name: 'A'));
    $b = app(CompanyService::class)->create(new CreateCompanyData(name: 'B'));

    expect(Role::where('company_id', $a->id)->count())->toBe($cuantos)
        ->and(Role::where('company_id', $b->id)->count())->toBe($cuantos)
        // Y ninguno se cuela de una empresa a la otra.
        ->and(Role::whereIn('company_id', [$a->id, $b->id])->count())->toBe($cuantos * 2);
});

it('un usuario owner obtiene los permisos de su empresa', function (): void {
    $company = app(CompanyService::class)->create(new CreateCompanyData(name: 'Perms'));

    $user = User::create([
        'company_id' => $company->id,
        'name' => 'Owner',
        'email' => 'owner@perms.test',
        'password' => 'secret-password',
    ]);

    app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);
    $user->assignRole('owner');

    expect($user->hasPermissionTo('users.manage'))->toBeTrue();
});
