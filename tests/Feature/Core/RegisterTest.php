<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Core\Enums\SubscriptionStatus;
use App\Modules\Core\Models\Company;
use App\Modules\Core\Models\Plan;
use App\Modules\Core\Models\Subscription;
use App\Modules\Core\Support\ModuleRegistry;
use App\Modules\Core\Tenancy\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * Alta self-service: el cliente crea su empresa y arranca la prueba del PLAN que elija.
 *
 * Antes elegía módulos sueltos. El cambio importa por lo que ahora NO se guarda: la empresa no fija
 * su propia lista de módulos, sino que hereda la del plan. Congelar una copia sería dejarla fuera de
 * cualquier módulo que el plan gane más adelante.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(CurrentCompany::class)->forget();

    // Un plan recortado y otro completo: así se comprueba que lo elegido manda de verdad.
    $this->basico = Plan::create([
        'name' => 'Básico', 'slug' => 'basico', 'price' => '750',
        'billing_cycle' => 'monthly', 'trial_days' => 0,
        'modules' => ['pos', 'inventory'], 'is_active' => true,
    ]);

    $this->completo = Plan::create([
        'name' => 'Empresarial', 'slug' => 'empresarial', 'price' => '3000',
        'billing_cycle' => 'monthly', 'trial_days' => 0, 'modules' => null, 'is_active' => true,
    ]);
});

/**
 * Datos válidos del formulario.
 *
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function altaValida(array $extra = []): array
{
    return array_merge([
        'company_name' => 'Mi Negocio',
        'owner_name' => 'Juan Dueño',
        'owner_email' => 'juan@negocio.test',
        'password' => 'Password123!',
        'password_confirmation' => 'Password123!',
    ], $extra);
}

it('muestra el formulario de registro', function (): void {
    $this->get('/registro')
        ->assertOk()
        ->assertSee('Crea tu cuenta')
        ->assertSee('prueba');
});

it('ofrece los planes activos y no los retirados', function (): void {
    Plan::create([
        'name' => 'Descatalogado', 'slug' => 'viejo', 'price' => '100',
        'billing_cycle' => 'monthly', 'trial_days' => 0, 'modules' => null, 'is_active' => false,
    ]);

    $this->get('/registro')
        ->assertOk()
        ->assertSee('Básico')
        ->assertSee('Empresarial')
        ->assertDontSee('Descatalogado');
});

it('registra una empresa en prueba con el plan elegido', function (): void {
    $this->post('/registro', altaValida(['plan_id' => $this->basico->id]))
        ->assertRedirect(route('dashboard'));

    $this->assertAuthenticated();

    $user = User::where('email', 'juan@negocio.test')->firstOrFail();
    $company = Company::findOrFail($user->company_id);
    $sub = Subscription::where('company_id', $company->id)->firstOrFail();

    expect($company->name)->toBe('Mi Negocio')
        ->and($sub->plan_id)->toBe($this->basico->id)
        ->and($sub->status)->toBe(SubscriptionStatus::Trialing)
        ->and($sub->trial_ends_at->isFuture())->toBeTrue()
        ->and($sub->purge_at)->not->toBeNull()
        ->and($sub->purge_at->greaterThan($sub->trial_ends_at))->toBeTrue(); // 24 h después
});

it('la empresa hereda los módulos del plan en vez de congelar una copia', function (): void {
    // `company.modules` en NULL es lo que hace que, si el plan gana un módulo mañana, esta empresa
    // lo reciba sola. Guardar aquí una copia la dejaría anclada para siempre.
    $this->post('/registro', altaValida(['plan_id' => $this->basico->id]));

    $company = Company::firstWhere('name', 'Mi Negocio');

    expect($company->modules)->toBeNull()
        ->and($company->activeModules())->toBe(['pos', 'inventory']);
});

it('elegir el plan completo da acceso a todos los módulos', function (): void {
    $this->post('/registro', altaValida(['plan_id' => $this->completo->id]));

    $company = Company::firstWhere('name', 'Mi Negocio');

    expect($company->activeModules())->toBe(ModuleRegistry::keys());
});

it('la prueba dura lo que dice la configuración, no lo que diga el plan', function (): void {
    // El plan lleva sus propios «días de prueba» para las altas que hace el operador; aquí no
    // mandan. La pantalla promete unos días concretos y tienen que ser esos para todos los planes.
    config(['bmos.trial.days' => 15]);
    $this->basico->update(['trial_days' => 3]);

    $this->post('/registro', altaValida(['plan_id' => $this->basico->id]));

    $sub = Subscription::firstOrFail();

    expect((int) round(now()->diffInDays($sub->trial_ends_at, false)))->toBe(15);
});

it('rechaza un plan retirado de la venta aunque se envíe su id a mano', function (): void {
    // Ocultarlo de la lista no basta: el formulario se puede manipular.
    $retirado = Plan::create([
        'name' => 'Retirado', 'slug' => 'retirado', 'price' => '1',
        'billing_cycle' => 'monthly', 'trial_days' => 0, 'modules' => null, 'is_active' => false,
    ]);

    $this->post('/registro', altaValida(['plan_id' => $retirado->id]))
        ->assertSessionHasErrors('plan_id');

    expect(Company::count())->toBe(0);
});

it('exige elegir un plan', function (): void {
    $this->post('/registro', altaValida())->assertSessionHasErrors('plan_id');

    expect(Company::count())->toBe(0);
});

it('rechaza un correo ya registrado', function (): void {
    User::create(['name' => 'Existente', 'email' => 'dup@x.test', 'password' => 'secret', 'is_active' => true]);

    $this->post('/registro', altaValida([
        'owner_email' => 'dup@x.test', 'plan_id' => $this->basico->id,
    ]))->assertSessionHasErrors('owner_email');
});
