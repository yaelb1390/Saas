<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Enums\SubscriptionStatus;
use App\Modules\Core\Models\Plan;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Services\SubscriptionService;
use App\Modules\Core\Tenancy\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * Pantalla de planes y cambio de plan.
 *
 * Una sola pantalla para dos públicos: quien todavía no es cliente la usa para comparar antes de
 * registrarse, y quien ya lo es, para cambiar de plan. Lo único que cambia es el botón.
 *
 * Lo que más se cuida aquí es la regla del dinero: durante la prueba el cambio es gratis, pero fuera
 * de ella NO puede serlo. `changePlan()` sobre una suscripción que no está al día arranca un período
 * completo sin comprobar ningún pago; exponer eso al cliente sería regalarle un ciclo cada vez que
 * cambiara de plan.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(CurrentCompany::class)->forget();
    config(['bmos.registration.default_plan_slug' => 'basico']);

    $this->basico = Plan::create([
        'name' => 'Básico', 'slug' => 'basico', 'price' => '750',
        'billing_cycle' => 'monthly', 'trial_days' => 0,
        'modules' => ['pos', 'inventory'], 'is_active' => true,
    ]);

    $this->pro = Plan::create([
        'name' => 'Pro', 'slug' => 'pro', 'price' => '1500',
        'billing_cycle' => 'monthly', 'trial_days' => 0, 'modules' => null, 'is_active' => true,
    ]);

    $this->company = app(CompanyService::class)->create(new CreateCompanyData(name: 'Heladería'));
    app(CurrentCompany::class)->set($this->company->id);

    $this->owner = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Dueña',
        'email' => 'duena@heladeria.test', 'password' => 'secret-password',
    ]), 'owner');

    app(CurrentCompany::class)->forget();
});

/** Deja la empresa en prueba vigente del plan Básico. */
function ponerEnPrueba(): void
{
    app(SubscriptionService::class)->startSelfServiceTrial(test()->company, test()->basico, 15);
}

// ---------------------------------------------------------------- Pantalla

it('se puede consultar sin tener cuenta', function (): void {
    // Es su razón de ser: comparar antes de registrarse.
    $this->get(route('plans.public'))
        ->assertOk()
        ->assertSee('Básico')
        ->assertSee('Pro')
        ->assertSee('Empezar gratis');
});

it('no muestra los planes retirados de la venta', function (): void {
    Plan::create([
        'name' => 'Descatalogado', 'slug' => 'viejo', 'price' => '100',
        'billing_cycle' => 'monthly', 'trial_days' => 0, 'modules' => null, 'is_active' => false,
    ]);

    $this->get(route('plans.public'))->assertOk()->assertDontSee('Descatalogado');
});

it('explica qué hace cada módulo, no solo cómo se llama', function (): void {
    // «Facturación» no le dice nada a quien todavía no es cliente.
    $this->get(route('plans.public'))
        ->assertOk()
        ->assertSee('Cobra en mostrador');
});

it('a un cliente en prueba le ofrece cambiar, y le marca el suyo', function (): void {
    ponerEnPrueba();

    $this->actingAs($this->owner)->get(route('plans.public'))
        ->assertOk()
        ->assertSee('Es tu plan actual')
        ->assertSee('Probar este plan')
        ->assertDontSee('Empezar gratis');
});

it('a un cajero le enseña los planes pero ningún botón', function (): void {
    ponerEnPrueba();

    $cajero = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Cajero',
        'email' => 'cajero@heladeria.test', 'password' => 'secret-password',
    ]), 'staff');

    $this->actingAs($cajero)->get(route('plans.public'))
        ->assertOk()
        ->assertSee('Básico')
        ->assertDontSee('Probar este plan');
});

// ---------------------------------------------------------------- Cambio de plan

it('durante la prueba el cambio es inmediato y no toca el reloj', function (): void {
    ponerEnPrueba();
    $antes = $this->company->subscription;
    $finPrueba = $antes->trial_ends_at;
    $purga = $antes->purge_at;

    $this->actingAs($this->owner)
        ->post(route('panel.account.plan', $this->pro))
        ->assertRedirect(route('panel.account'));

    $sub = $this->company->subscription->fresh();

    expect($sub->plan_id)->toBe($this->pro->id)
        ->and($sub->status)->toBe(SubscriptionStatus::Trialing)
        // Cambiar de plan no regala días ni retrasa el borrado de los datos de prueba.
        ->and($sub->trial_ends_at->eq($finPrueba))->toBeTrue()
        ->and($sub->purge_at->eq($purga))->toBeTrue();
});

it('cambiar de plan cambia de verdad los módulos que ve', function (): void {
    ponerEnPrueba();

    expect($this->company->fresh()->activeModules())->toBe(['pos', 'inventory']);

    $this->actingAs($this->owner)->post(route('panel.account.plan', $this->pro));

    expect($this->company->fresh()->activeModules())->toContain('crm');
});

it('con la prueba vencida NO se cambia gratis', function (): void {
    // Aquí está el dinero: `changePlan()` sobre una suscripción no usable arranca un período entero
    // sin cobrar. Si esta puerta se abriera, cambiar de plan sería un mes gratis cada vez.
    ponerEnPrueba();
    $this->company->subscription->update(['trial_ends_at' => now()->subDay()]);

    $this->actingAs($this->owner)
        ->post(route('panel.account.plan', $this->pro))
        ->assertRedirect(route('panel.account'));

    $sub = $this->company->subscription->fresh();

    expect($sub->plan_id)->toBe($this->basico->id) // no cambió
        ->and($sub->status)->toBe(SubscriptionStatus::Trialing)
        ->and(session('panel_error'))->toContain('Tu prueba ya terminó');
});

it('una suscripción ya pagada tampoco cambia gratis', function (): void {
    app(SubscriptionService::class)->subscribe($this->company, $this->basico, withTrial: false);

    $this->actingAs($this->owner)->post(route('panel.account.plan', $this->pro));

    expect($this->company->subscription->fresh()->plan_id)->toBe($this->basico->id);
});

it('rechaza cambiar a un plan retirado aunque se envíe su id a mano', function (): void {
    ponerEnPrueba();
    $retirado = Plan::create([
        'name' => 'Retirado', 'slug' => 'retirado', 'price' => '1',
        'billing_cycle' => 'monthly', 'trial_days' => 0, 'modules' => null, 'is_active' => false,
    ]);

    $this->actingAs($this->owner)->post(route('panel.account.plan', $retirado));

    expect($this->company->subscription->fresh()->plan_id)->toBe($this->basico->id);
});

it('un cajero no puede cambiar el plan de la empresa', function (): void {
    ponerEnPrueba();

    $cajero = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Cajero',
        'email' => 'cajero@heladeria.test', 'password' => 'secret-password',
    ]), 'staff');

    $this->actingAs($cajero)->post(route('panel.account.plan', $this->pro))->assertForbidden();

    expect($this->company->subscription->fresh()->plan_id)->toBe($this->basico->id);
});

it('el menú lateral enseña Suscripción al dueño', function (): void {
    ponerEnPrueba();

    $this->actingAs($this->owner)->get(route('panel.account'))
        ->assertOk()
        ->assertSee('Suscripción');
});
