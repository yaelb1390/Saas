<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Enums\BillingCycle;
use App\Modules\Core\Enums\SubscriptionStatus;
use App\Modules\Core\Models\Plan;
use App\Modules\Core\Models\Subscription;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Support\SubscriptionNotice;
use App\Modules\Core\Tenancy\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(CurrentCompany::class)->forget();
    $this->company = app(CompanyService::class)->create(new CreateCompanyData(name: 'Aviso Co'));
    app(CurrentCompany::class)->set($this->company->id);

    $this->owner = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Dueño',
        'email' => 'owner@aviso.test', 'password' => 'secret-password',
    ]), 'owner');

    $this->plan = Plan::create([
        'name' => 'Mensual', 'slug' => 'mensual', 'price' => '1000', 'billing_cycle' => 'monthly',
        'trial_days' => 14, 'modules' => null, 'is_active' => true,
    ]);
});

/**
 * Crea una suscripción activa que vence dentro de $dias días.
 */
function subActivaEnDias(int $dias): Subscription
{
    return Subscription::create([
        'company_id' => test()->company->id, 'plan_id' => test()->plan->id,
        'status' => SubscriptionStatus::Active,
        'current_period_start' => Carbon::now()->subMonth(),
        'current_period_end' => Carbon::now()->addDays($dias),
    ]);
}

// ---------------------------------------------------------------- Ciclo trimestral

it('el ciclo trimestral avanza tres meses', function (): void {
    $desde = Carbon::parse('2026-01-15');

    expect(BillingCycle::Quarterly->advance($desde)->toDateString())->toBe('2026-04-15')
        ->and(BillingCycle::Quarterly->label())->toBe('Trimestral');
});

it('el umbral de aviso escala con el ciclo', function (): void {
    expect(BillingCycle::Monthly->noticeThresholdDays())->toBe(5)
        ->and(BillingCycle::Quarterly->noticeThresholdDays())->toBe(10)
        ->and(BillingCycle::Yearly->noticeThresholdDays())->toBe(30);
});

// ---------------------------------------------------------------- Modelo

it('daysUntilRenewal cuenta los días hasta la renovación', function (): void {
    $sub = subActivaEnDias(4);

    expect($sub->daysUntilRenewal())->toBe(4)
        ->and($sub->isTrialing())->toBeFalse();
});

it('la cuenta de días baja al cambiar de día, no a la hora de creación', function (): void {
    $sub = Subscription::create([
        'company_id' => $this->company->id, 'plan_id' => $this->plan->id,
        'status' => SubscriptionStatus::Trialing,
        'trial_ends_at' => Carbon::parse('2026-08-03 08:00:00'),
    ]);

    // Tarde del día en que se creó: 15 días.
    Carbon::setTestNow('2026-07-19 20:00:00');
    expect($sub->daysUntilRenewal())->toBe(15);

    // Madrugada del día siguiente: baja a 14 (antes se quedaba en 15 hasta las 08:00).
    Carbon::setTestNow('2026-07-20 02:00:00');
    expect($sub->daysUntilRenewal())->toBe(14);

    // Más tarde el mismo día: sigue 14 (no baja dentro del mismo día).
    Carbon::setTestNow('2026-07-20 23:30:00');
    expect($sub->daysUntilRenewal())->toBe(14);

    // El día del vencimiento: 0 (vence hoy).
    Carbon::setTestNow('2026-08-03 09:00:00');
    expect($sub->daysUntilRenewal())->toBe(0);

    // Un día después: negativo (venció ayer).
    Carbon::setTestNow('2026-08-04 09:00:00');
    expect($sub->daysUntilRenewal())->toBe(-1);

    Carbon::setTestNow();
});

// ---------------------------------------------------------------- Presenter del aviso

it('la prueba genera un aviso con cuenta atrás', function (): void {
    $sub = Subscription::create([
        'company_id' => $this->company->id, 'plan_id' => $this->plan->id,
        'status' => SubscriptionStatus::Trialing,
        'trial_ends_at' => Carbon::now()->addDays(6),
    ]);

    $notice = SubscriptionNotice::for($sub);

    expect($notice)->not->toBeNull()
        ->and($notice->isTrial)->toBeTrue()
        ->and($notice->level)->toBe('info') // 6 días > 3 → info
        ->and($notice->message)->toContain('prueba')
        ->and($notice->days)->toBe(6);
});

it('la prueba a punto de terminar es crítica', function (): void {
    $sub = Subscription::create([
        'company_id' => $this->company->id, 'plan_id' => $this->plan->id,
        'status' => SubscriptionStatus::Trialing,
        'trial_ends_at' => Carbon::now()->addDays(2),
    ]);

    expect(SubscriptionNotice::for($sub)->level)->toBe('critical');
});

it('una suscripción de pago avisa solo dentro del umbral del ciclo', function (): void {
    // Mensual → umbral 5 días. A 4 días avisa (warning); a 10 días, no.
    expect(SubscriptionNotice::for(subActivaEnDias(4))?->level)->toBe('warning');

    Subscription::query()->delete();
    expect(SubscriptionNotice::for(subActivaEnDias(10)))->toBeNull();
});

it('un plan anual avisa con más anticipación', function (): void {
    $anual = Plan::create([
        'name' => 'Anual', 'slug' => 'anual', 'price' => '10000',
        'billing_cycle' => 'yearly', 'trial_days' => 0, 'modules' => null, 'is_active' => true,
    ]);
    $sub = Subscription::create([
        'company_id' => $this->company->id, 'plan_id' => $anual->id,
        'status' => SubscriptionStatus::Active,
        'current_period_start' => Carbon::now()->subYear(),
        'current_period_end' => Carbon::now()->addDays(20), // dentro del umbral anual (30)
    ]);

    expect(SubscriptionNotice::for($sub)?->level)->toBe('warning');
});

it('no avisa si la suscripción no es usable (ya la maneja la suspensión)', function (): void {
    $sub = Subscription::create([
        'company_id' => $this->company->id, 'plan_id' => $this->plan->id,
        'status' => SubscriptionStatus::Suspended,
        'current_period_end' => Carbon::now()->addDays(2),
    ]);

    expect(SubscriptionNotice::for($sub))->toBeNull();
});

it('no hay aviso sin suscripción', function (): void {
    expect(SubscriptionNotice::for(null))->toBeNull();
});

it('la clave de descarte cambia con la fecha de renovación', function (): void {
    $a = SubscriptionNotice::for(subActivaEnDias(2));
    Subscription::query()->delete();
    $b = SubscriptionNotice::for(subActivaEnDias(3));

    // Distinta fecha de vencimiento → distinta clave → el aviso reaparece al renovar.
    expect($a->dismissKey())->not->toBe($b->dismissKey());
});

// ---------------------------------------------------------------- Integración con el panel

it('el banner de aviso aparece en el panel cuando la prueba está por vencer', function (): void {
    Subscription::create([
        'company_id' => $this->company->id, 'plan_id' => $this->plan->id,
        'status' => SubscriptionStatus::Trialing,
        'trial_ends_at' => Carbon::now()->addDays(2),
    ]);

    $this->actingAs($this->owner)
        ->get(route('panel.account'))
        ->assertOk()
        ->assertSee('prueba', false)
        ->assertSee('subscriptionNotice', false); // el componente Alpine del aviso
});

it('la página de cuenta muestra los botones de contacto y pago', function (): void {
    config()->set('platform.support_whatsapp', '18095551234');
    config()->set('platform.support_email', 'pagos@bm.test');
    config()->set('platform.support_paypal', 'https://paypal.me/bmbusiness');

    Subscription::create([
        'company_id' => $this->company->id, 'plan_id' => $this->plan->id,
        'status' => SubscriptionStatus::Trialing,
        'trial_ends_at' => Carbon::now()->addDays(10),
    ]);

    $this->actingAs($this->owner)
        ->get(route('panel.account'))
        ->assertOk()
        ->assertSee('wa.me/18095551234', false)
        ->assertSee('mailto:pagos@bm.test', false)
        ->assertSee('https://paypal.me/bmbusiness', false)
        ->assertSee('activar tu plan', false);
});

it('el botón de PayPal no aparece si no está configurado', function (): void {
    config()->set('platform.support_paypal', '');

    Subscription::create([
        'company_id' => $this->company->id, 'plan_id' => $this->plan->id,
        'status' => SubscriptionStatus::Trialing,
        'trial_ends_at' => Carbon::now()->addDays(10),
    ]);

    $this->actingAs($this->owner)
        ->get(route('panel.account'))
        ->assertOk()
        ->assertDontSee('Pagar con PayPal');
});

it('el super admin no recibe avisos de vencimiento', function (): void {
    Subscription::create([
        'company_id' => $this->company->id, 'plan_id' => $this->plan->id,
        'status' => SubscriptionStatus::Trialing,
        'trial_ends_at' => Carbon::now()->addDays(1),
    ]);

    $super = User::create([
        'company_id' => null, 'is_super_admin' => true,
        'name' => 'Super', 'email' => 'super@aviso.test', 'password' => 'secret-password',
    ]);

    // Con la empresa activa en sesión, el super admin igualmente NO ve el componente de aviso.
    $this->actingAs($super)->withSession(['active_company_id' => $this->company->id])
        ->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee('subscriptionNotice', false);
});
