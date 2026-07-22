<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Tenancy\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(CurrentCompany::class)->forget();
    $this->company = app(CompanyService::class)->create(new CreateCompanyData(name: 'Perfil Plataforma Co'));
    app(CurrentCompany::class)->set($this->company->id);

    $this->owner = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Dueño',
        'email' => 'owner@perfil.test', 'password' => 'secret-password',
    ]), 'owner');

    $this->super = User::create([
        'company_id' => null, 'is_super_admin' => true,
        'name' => 'Super', 'email' => 'super@perfil.test', 'password' => 'secret-password',
    ]);
});

it('el super admin ve el tipo de negocio en el panel de empresas', function (): void {
    $this->actingAs($this->super)
        ->get(route('platform.companies'))
        ->assertOk()
        ->assertSee('Tipo de negocio (POS)')
        ->assertSee('Salón / Uñas');
});

it('el super admin define el tipo de negocio y las opciones de una empresa', function (): void {
    $this->actingAs($this->super)
        ->put(route('platform.companies.pos', $this->company), [
            'profile' => 'salon',
            'options' => ['tip' => '1', 'attendant' => '1', 'services' => '1'],
        ])
        ->assertRedirect()
        ->assertSessionHas('panel_ok');

    $pos = $this->company->fresh()->settings['pos'];
    expect($pos['profile'])->toBe('salon')
        ->and($pos['options']['tip'])->toBeTrue()
        ->and($pos['options']['serial'])->toBeFalse(); // no enviada → false
});

it('rechaza un tipo de negocio inválido', function (): void {
    $this->actingAs($this->super)
        ->put(route('platform.companies.pos', $this->company), ['profile' => 'inventado'])
        ->assertSessionHasErrors('profile');
});

it('el dueño de una empresa NO puede cambiar el tipo de negocio (es del operador)', function (): void {
    $this->actingAs($this->owner)
        ->put(route('platform.companies.pos', $this->company), ['profile' => 'salon'])
        ->assertForbidden();

    // La empresa (que no es super admin) tampoco ve el panel de plataforma.
    $this->actingAs($this->owner)->get(route('platform.companies'))->assertForbidden();
});
