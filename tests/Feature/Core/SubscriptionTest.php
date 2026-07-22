<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Enums\SubscriptionStatus;
use App\Modules\Core\Models\Plan;
use App\Modules\Core\Models\Subscription;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Services\SubscriptionService;
use App\Modules\Core\Tenancy\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(CurrentCompany::class)->forget();
    $this->company = app(CompanyService::class)->create(new CreateCompanyData(name: 'Suscrip Co'));
    app(CurrentCompany::class)->set($this->company->id);

    $this->owner = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Dueño',
        'email' => 'owner@sub.test', 'password' => 'secret-password',
    ]), 'owner');

    $this->super = User::create([
        'company_id' => null, 'is_super_admin' => true,
        'name' => 'Super', 'email' => 'super@sub.test', 'password' => 'secret-password',
    ]);

    $this->planPro = Plan::create([
        'name' => 'Pro', 'slug' => 'pro', 'price' => '3500', 'billing_cycle' => 'monthly',
        'trial_days' => 14, 'modules' => ['pos', 'sales', 'billing'], 'is_active' => true,
    ]);
    $this->planBasico = Plan::create([
        'name' => 'Básico', 'slug' => 'basico', 'price' => '1500', 'billing_cycle' => 'monthly',
        'trial_days' => 0, 'modules' => ['pos'], 'is_active' => true,
    ]);
});

// ---------------------------------------------------------------- Ciclo de vida (servicio)

it('suscribe con período de prueba', function (): void {
    $sub = app(SubscriptionService::class)->subscribe($this->company, $this->planPro);

    expect($sub->status)->toBe(SubscriptionStatus::Trialing)
        ->and($sub->trial_ends_at->isFuture())->toBeTrue()
        ->and($sub->isUsable())->toBeTrue();
});

it('registrar un pago activa la suscripción y extiende el período', function (): void {
    $service = app(SubscriptionService::class);
    $sub = $service->subscribe($this->company, $this->planBasico); // sin prueba → activa

    $firstEnd = $sub->current_period_end;
    $service->registerPayment($sub);

    expect($sub->refresh()->status)->toBe(SubscriptionStatus::Active)
        ->and($sub->current_period_end->gt($firstEnd))->toBeTrue();
});

it('una suscripción vencida deja de dar acceso', function (): void {
    $sub = Subscription::create([
        'company_id' => $this->company->id, 'plan_id' => $this->planPro->id,
        'status' => SubscriptionStatus::Active,
        'current_period_start' => Carbon::now()->subMonth(),
        'current_period_end' => Carbon::now()->subDay(), // venció ayer
    ]);

    expect($sub->isUsable())->toBeFalse();
});

// ---------------------------------------------------------------- Gating por módulos del plan

it('el acceso a los módulos sale del plan de la suscripción', function (): void {
    app(SubscriptionService::class)->subscribe($this->company, $this->planPro);

    // Pro incluye pos/sales/billing, no inventario ni IA.
    $this->actingAs($this->owner)->get(route('panel.pos'))->assertOk();
    $this->actingAs($this->owner)->get(route('panel.invoices'))->assertOk();
    $this->actingAs($this->owner)->get(route('panel.products'))->assertForbidden();
});

it('con la suscripción suspendida el panel redirige al aviso', function (): void {
    $service = app(SubscriptionService::class);
    $sub = $service->subscribe($this->company, $this->planPro);
    $service->suspend($sub);

    // Un módulo de su plan ahora redirige al aviso de suspensión (no 200).
    $this->actingAs($this->owner)->get(route('panel.pos'))->assertRedirect(route('panel.suspended'));

    // El aviso es accesible y muestra el mensaje de suspensión.
    $this->actingAs($this->owner)->get(route('panel.suspended'))
        ->assertOk()
        ->assertSee('suspendida', false);
});

it('una empresa suspendida (is_active false) bloquea a sus usuarios', function (): void {
    // Sin suscripción, pero la empresa fue suspendida por el operador.
    $this->company->update(['is_active' => false]);

    $this->actingAs($this->owner)->get(route('dashboard'))->assertRedirect(route('panel.suspended'));
    $this->actingAs($this->owner)->get(route('panel.pos'))->assertRedirect(route('panel.suspended'));

    // El aviso sigue accesible.
    $this->actingAs($this->owner)->get(route('panel.suspended'))->assertOk();
});

it('el aviso de suspensión redirige al panel si la cuenta está al día', function (): void {
    // Empresa activa y sin suscripción → no hay nada que avisar.
    $this->actingAs($this->owner)->get(route('panel.suspended'))->assertRedirect(route('dashboard'));
});

it('el super admin no queda bloqueado por una empresa suspendida', function (): void {
    $this->company->update(['is_active' => false]);

    $this->actingAs($this->super)->withSession(['active_company_id' => $this->company->id])
        ->get(route('dashboard'))->assertOk();
});

it('las empresas sin suscripción conservan el acceso (heredado)', function (): void {
    // La empresa de este test no tiene suscripción → módulos por la columna (todos).
    $this->actingAs($this->owner)->get(route('panel.pos'))->assertOk();
    $this->actingAs($this->owner)->get(route('panel.products'))->assertOk();
});

// ---------------------------------------------------------------- Panel de plataforma

it('el super admin suscribe una empresa a un plan', function (): void {
    $this->actingAs($this->super)
        ->post(route('platform.companies.subscribe', $this->company), ['plan_id' => $this->planPro->id, 'with_trial' => '1'])
        ->assertRedirect();

    expect($this->company->fresh()->subscription->plan_id)->toBe($this->planPro->id);
});

it('pone en prueba a una empresa que YA tenía suscripción activa', function (): void {
    // Estado de partida: activa y al día (como las empresas reales del usuario).
    app(SubscriptionService::class)->subscribe($this->company, $this->planBasico, withTrial: false);
    expect($this->company->fresh()->subscription->status)->toBe(SubscriptionStatus::Active);

    // El operador marca «iniciar prueba» con un plan que ofrece prueba (Pro, 14 días).
    $this->actingAs($this->super)
        ->post(route('platform.companies.subscribe', $this->company), ['plan_id' => $this->planPro->id, 'with_trial' => '1'])
        ->assertRedirect();

    $sub = $this->company->fresh()->subscription;
    expect($sub->status)->toBe(SubscriptionStatus::Trialing)
        ->and($sub->plan_id)->toBe($this->planPro->id)
        ->and($sub->trial_ends_at->isFuture())->toBeTrue();
});

it('cambiar de plan SIN marcar prueba conserva el estado activo', function (): void {
    app(SubscriptionService::class)->subscribe($this->company, $this->planBasico, withTrial: false);

    $this->actingAs($this->super)
        ->post(route('platform.companies.subscribe', $this->company), ['plan_id' => $this->planPro->id])
        ->assertRedirect();

    $sub = $this->company->fresh()->subscription;
    expect($sub->status)->toBe(SubscriptionStatus::Active) // no se convirtió en prueba
        ->and($sub->plan_id)->toBe($this->planPro->id);
});

it('marcar prueba con un plan sin días de prueba no inicia prueba', function (): void {
    // planBasico tiene trial_days = 0 → aunque se marque, no hay prueba que dar.
    $this->actingAs($this->super)
        ->post(route('platform.companies.subscribe', $this->company), ['plan_id' => $this->planBasico->id, 'with_trial' => '1'])
        ->assertRedirect();

    expect($this->company->fresh()->subscription->status)->toBe(SubscriptionStatus::Active);
});

it('el super admin registra un pago desde el panel', function (): void {
    app(SubscriptionService::class)->subscribe($this->company, $this->planPro);

    $this->actingAs($this->super)
        ->post(route('platform.companies.payment', $this->company))
        ->assertRedirect();

    expect($this->company->fresh()->subscription->status)->toBe(SubscriptionStatus::Active);
});

it('un dueño no puede gestionar planes ni suscripciones', function (): void {
    $this->actingAs($this->owner)->get(route('platform.plans'))->assertForbidden();
    $this->actingAs($this->owner)
        ->post(route('platform.companies.subscribe', $this->company), ['plan_id' => $this->planPro->id])
        ->assertForbidden();
});

it('el super admin crea un plan', function (): void {
    $this->actingAs($this->super)
        ->post(route('platform.plans.store'), [
            'name' => 'Enterprise', 'slug' => 'enterprise', 'price' => '9000',
            'billing_cycle' => 'yearly', 'trial_days' => 0, 'modules' => ['pos', 'sales'],
        ])
        ->assertRedirect();

    expect(Plan::where('slug', 'enterprise')->exists())->toBeTrue();
});

it('el super admin edita un plan', function (): void {
    $this->actingAs($this->super)
        ->put(route('platform.plans.update', $this->planPro), [
            'name' => 'Pro Plus', 'slug' => 'pro', 'price' => '4200',
            'billing_cycle' => 'quarterly', 'trial_days' => 30,
            'modules' => ['pos', 'sales'], 'is_active' => '1',
        ])
        ->assertRedirect()
        ->assertSessionHas('panel_ok');

    $plan = $this->planPro->fresh();
    expect($plan->name)->toBe('Pro Plus')
        ->and((float) $plan->price)->toBe(4200.0)
        ->and($plan->billing_cycle->value)->toBe('quarterly')
        ->and($plan->trial_days)->toBe(30)
        ->and($plan->moduleKeys())->toBe(['pos', 'sales']);
});

it('editar un plan conserva su propio identificador (slug único ignora el plan)', function (): void {
    // Reusar el MISMO slug del plan no debe chocar con la regla unique.
    $this->actingAs($this->super)
        ->put(route('platform.plans.update', $this->planPro), [
            'name' => 'Pro', 'slug' => 'pro', 'price' => '3500',
            'billing_cycle' => 'monthly', 'trial_days' => 14,
            'modules' => ['pos', 'sales', 'billing'], 'is_active' => '1',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();
});

it('editar un plan con el slug de OTRO plan es rechazado', function (): void {
    $this->actingAs($this->super)
        ->put(route('platform.plans.update', $this->planPro), [
            'name' => 'Pro', 'slug' => 'basico', 'price' => '3500', // slug ya usado por planBasico
            'billing_cycle' => 'monthly', 'trial_days' => 14,
            'modules' => ['pos'], 'is_active' => '1',
        ])
        ->assertSessionHasErrors('slug');
});

it('no permite borrar un plan con suscripciones', function (): void {
    app(SubscriptionService::class)->subscribe($this->company, $this->planPro);

    $this->actingAs($this->super)
        ->delete(route('platform.plans.destroy', $this->planPro))
        ->assertRedirect()
        ->assertSessionHas('panel_error');

    expect(Plan::whereKey($this->planPro->id)->exists())->toBeTrue();
});
