<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Core\Enums\SubscriptionStatus;
use App\Modules\Core\Models\Company;
use App\Modules\Core\Models\Plan;
use App\Modules\Core\Models\Subscription;
use App\Modules\Core\Tenancy\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(CurrentCompany::class)->forget();
    // El registro adjunta el plan de config('bmos.trial.plan_slug') = 'empresarial' (todos los módulos).
    Plan::create([
        'name' => 'Empresarial', 'slug' => 'empresarial', 'price' => '7000',
        'billing_cycle' => 'monthly', 'trial_days' => 0, 'modules' => null, 'is_active' => true,
    ]);
});

it('muestra el formulario de registro', function (): void {
    $this->get('/registro')
        ->assertOk()
        ->assertSee('Crea tu cuenta')
        ->assertSee('prueba');
});

it('registra una empresa en prueba con los módulos elegidos', function (): void {
    $this->post('/registro', [
        'company_name' => 'Mi Negocio',
        'owner_name' => 'Juan Dueño',
        'owner_email' => 'juan@negocio.test',
        'password' => 'Password123!',
        'password_confirmation' => 'Password123!',
        'modules' => ['loans', 'crm'],
    ])->assertRedirect(route('dashboard'));

    $this->assertAuthenticated();

    $user = User::where('email', 'juan@negocio.test')->firstOrFail();
    $company = Company::findOrFail($user->company_id);

    expect($company->name)->toBe('Mi Negocio')
        ->and($company->modules)->toBe(['loans', 'crm']); // solo lo que eligió

    $sub = Subscription::where('company_id', $company->id)->firstOrFail();

    expect($sub->status)->toBe(SubscriptionStatus::Trialing)
        ->and($sub->trial_ends_at->isFuture())->toBeTrue()
        ->and($sub->purge_at)->not->toBeNull()
        ->and($sub->purge_at->greaterThan($sub->trial_ends_at))->toBeTrue(); // 24 h después
});

it('rechaza un correo ya registrado', function (): void {
    User::create(['name' => 'Existente', 'email' => 'dup@x.test', 'password' => 'secret', 'is_active' => true]);

    $this->post('/registro', [
        'company_name' => 'Otra', 'owner_name' => 'X', 'owner_email' => 'dup@x.test',
        'password' => 'Password123!', 'password_confirmation' => 'Password123!', 'modules' => ['pos'],
    ])->assertSessionHasErrors('owner_email');
});

it('exige al menos un módulo', function (): void {
    $this->post('/registro', [
        'company_name' => 'Otra', 'owner_name' => 'X', 'owner_email' => 'nuevo@x.test',
        'password' => 'Password123!', 'password_confirmation' => 'Password123!',
    ])->assertSessionHasErrors('modules');
});
