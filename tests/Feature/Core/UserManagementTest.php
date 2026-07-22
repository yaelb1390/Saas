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
    app(CurrentCompany::class)->forget();
    $this->company = app(CompanyService::class)->create(new CreateCompanyData(name: 'Usuarios Co'));
    app(CurrentCompany::class)->set($this->company->id);

    $this->owner = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Dueño',
        'email' => 'owner@users.test', 'password' => 'secret-password',
    ]), 'owner');
});

it('el dueño crea un usuario con su rol', function (): void {
    $this->actingAs($this->owner)
        ->post(route('panel.users.store'), [
            '_form' => 'user_create',
            'name' => 'Nueva Cajera',
            'email' => 'cajera@users.test',
            'role' => 'staff',
            'password' => 'contrasena-larga',
            'password_confirmation' => 'contrasena-larga',
        ])
        ->assertRedirect();

    $created = User::where('email', 'cajera@users.test')->firstOrFail();

    app(PermissionRegistrar::class)->setPermissionsTeamId($this->company->id);
    expect($created->company_id)->toBe($this->company->id)
        ->and($created->fresh()->hasRole('staff'))->toBeTrue();
});

it('valida la confirmación de contraseña y el rol', function (): void {
    $this->actingAs($this->owner)
        ->post(route('panel.users.store'), [
            '_form' => 'user_create',
            'name' => 'X', 'email' => 'x@users.test', 'role' => 'inventado',
            'password' => 'contrasena-larga', 'password_confirmation' => 'otra-cosa',
        ])
        ->assertSessionHasErrors(['password', 'role']);

    expect(User::where('email', 'x@users.test')->exists())->toBeFalse();
});

it('cambia el rol de un usuario existente', function (): void {
    $user = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Asciende',
        'email' => 'asciende@users.test', 'password' => 'secret-password',
    ]), 'staff');

    $this->actingAs($this->owner)
        ->put(route('panel.users.update', $user), [
            '_form' => 'user_edit',
            'name' => 'Asciende', 'email' => 'asciende@users.test', 'role' => 'admin', 'is_active' => '1',
        ])
        ->assertRedirect();

    app(PermissionRegistrar::class)->setPermissionsTeamId($this->company->id);
    expect($user->fresh()->hasRole('admin'))->toBeTrue()
        ->and($user->fresh()->hasRole('staff'))->toBeFalse();
});

it('un usuario desactivado no puede iniciar sesión', function (): void {
    $user = User::create([
        'company_id' => $this->company->id, 'name' => 'Baja',
        'email' => 'baja@users.test', 'password' => 'secret-password', 'is_active' => false,
    ]);

    $this->post('/login', ['email' => 'baja@users.test', 'password' => 'secret-password'])
        ->assertSessionHasErrors();

    $this->assertGuest();
});

it('el dueño no puede desactivar su propia cuenta', function (): void {
    $this->actingAs($this->owner)
        ->post(route('panel.users.toggle', $this->owner))
        ->assertRedirect()
        ->assertSessionHas('panel_error');

    expect($this->owner->fresh()->is_active)->toBeTrue();
});

it('un cajero no puede administrar usuarios', function (): void {
    $cajero = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Cajero',
        'email' => 'cajero@users.test', 'password' => 'secret-password',
    ]), 'staff');

    $this->actingAs($cajero)->get(route('panel.users'))->assertForbidden();

    $this->actingAs($cajero)
        ->post(route('panel.users.store'), [
            'name' => 'Colado', 'email' => 'colado@users.test', 'role' => 'owner',
            'password' => 'contrasena-larga', 'password_confirmation' => 'contrasena-larga',
        ])
        ->assertForbidden();

    expect(User::where('email', 'colado@users.test')->exists())->toBeFalse();
});

it('no permite administrar un usuario de otra empresa', function (): void {
    $other = app(CompanyService::class)->create(new CreateCompanyData(name: 'Ajena Users'));
    $foreign = User::create([
        'company_id' => $other->id, 'name' => 'Ajeno',
        'email' => 'ajeno@users.test', 'password' => 'secret-password',
    ]);

    $this->actingAs($this->owner)
        ->put(route('panel.users.update', $foreign), [
            'name' => 'Hackeado', 'email' => 'ajeno@users.test', 'role' => 'owner', 'is_active' => '1',
        ])
        ->assertNotFound();

    expect($foreign->fresh()->name)->toBe('Ajeno');
});
