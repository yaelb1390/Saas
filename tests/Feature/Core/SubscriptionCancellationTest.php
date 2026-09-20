<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Enums\SubscriptionStatus;
use App\Modules\Core\Models\Plan;
use App\Modules\Core\Models\SystemEvent;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Services\SubscriptionService;
use App\Modules\Core\Tenancy\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

/*
 * Cancelar la suscripción desde el panel, y arrepentirse.
 *
 * Cancelar es dejar de renovar, NO cortar el acceso: el cliente ya pagó el período y lo conserva
 * hasta el final. Polar es la fuente de verdad, así que lo primero es pedírselo a él, y solo si lo
 * acepta se anota en la app. Lo contrario dejaría a la pantalla diciendo «cancelada» de algo que
 * Polar sigue cobrando.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(CurrentCompany::class)->forget();

    config(['polar.access_token' => 'polar_oat_de_prueba', 'polar.server' => 'sandbox']);

    $this->company = app(CompanyService::class)->create(new CreateCompanyData(
        name: 'Heladería', email: 'duena@heladeria.example.com',
    ));
    app(CurrentCompany::class)->set($this->company->id);

    $this->owner = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Dueña',
        'email' => 'duena@heladeria.example.com', 'password' => 'secret-password',
    ]), 'owner');

    $this->plan = Plan::create([
        'name' => 'Pro', 'slug' => 'pro', 'price' => '1500', 'billing_cycle' => 'monthly',
        'trial_days' => 15, 'modules' => null, 'is_active' => true,
        'polar_product_id' => 'ad5bee12-beb1-48ee-b6ec-1eb5c9d1b6fe',
    ]);

    // Una empresa que ya pagó: activa, enlazada con su suscripción de Polar y con el período vigente.
    $this->subscription = app(SubscriptionService::class)->subscribe($this->company, $this->plan, withTrial: false);
    $this->subscription->update([
        'polar_subscription_id' => 'sub_de_prueba',
        'current_period_end' => now()->addDays(20),
    ]);
});

// ------------------------------------------------------------------ Cancelar

it('cancelar deja de renovar pero conserva el acceso hasta el fin del período', function (): void {
    Http::fake(['*/v1/subscriptions/sub_de_prueba' => Http::response(['id' => 'sub_de_prueba'], 200)]);

    $this->actingAs($this->owner)
        ->post(route('panel.account.cancel'))
        ->assertRedirect(route('panel.account'));

    $suscripcion = $this->subscription->fresh();

    // No se toca el estado ni el período: cortar aquí le quitaría un servicio por el que ya pagó.
    expect($suscripcion->cancelled_at)->not->toBeNull()
        ->and($suscripcion->status)->toBe(SubscriptionStatus::Active)
        ->and($suscripcion->isUsable())->toBeTrue()
        ->and($suscripcion->endsAtPeriodEnd())->toBeTrue()
        ->and(session('panel_ok'))->toContain('Sigues con acceso completo hasta el '.$suscripcion->current_period_end->format('d/m/Y'));

    Http::assertSent(fn ($request): bool => $request->method() === 'PATCH'
        && str_ends_with($request->url(), '/v1/subscriptions/sub_de_prueba')
        && $request->data() === ['cancel_at_period_end' => true]);
});

it('cancelar dos veces seguidas solo se lo pide una vez a Polar', function (): void {
    // Un doble clic no puede convertirse en un error ni en dos peticiones.
    Http::fake(['*/v1/subscriptions/sub_de_prueba' => Http::response(['id' => 'sub_de_prueba'], 200)]);

    $this->actingAs($this->owner)->post(route('panel.account.cancel'))->assertRedirect(route('panel.account'));
    $this->actingAs($this->owner)->post(route('panel.account.cancel'))->assertRedirect(route('panel.account'));

    Http::assertSentCount(1);
    expect(session('panel_error'))->toBeNull();
});

it('si Polar dice que ya estaba cancelada, para el cliente es un éxito', function (): void {
    // Comprobado contra la API real: cancelar una ya cancelada responde 403 «AlreadyCanceledSubscription».
    // Pasa cuando la app va por detrás de Polar. Lo que el cliente quería ya es verdad.
    Http::fake(['*/v1/subscriptions/sub_de_prueba' => Http::response([
        'error' => 'AlreadyCanceledSubscription',
        'detail' => 'This subscription is already canceled or will be at the end of the period.',
    ], 403)]);

    $this->actingAs($this->owner)->post(route('panel.account.cancel'))->assertRedirect(route('panel.account'));

    expect($this->subscription->fresh()->endsAtPeriodEnd())->toBeTrue()
        ->and(session('panel_error'))->toBeNull();
});

it('si Polar falla, no se anota la baja y el cliente lo ve', function (): void {
    // Anotarla igual sería decirle «cancelada» a alguien a quien Polar va a seguir cobrando.
    Http::fake(['*/v1/subscriptions/sub_de_prueba' => Http::response(['error' => 'Boom'], 500)]);

    $this->actingAs($this->owner)->post(route('panel.account.cancel'))->assertRedirect();

    expect($this->subscription->fresh()->cancelled_at)->toBeNull()
        ->and(session('panel_error'))->toContain('No pudimos cancelar');
});

it('un rechazo de Polar por otro motivo sigue siendo un fallo', function (int $estado, array $cuerpo): void {
    // Solo dos errores concretos equivalen a «ya está como pides». Un 403 por falta de permiso del token
    // no puede confundirse con ellos: sería decirle al cliente que canceló sin haber cancelado nada.
    Http::fake(['*/v1/subscriptions/sub_de_prueba' => Http::response($cuerpo, $estado)]);

    $this->actingAs($this->owner)->post(route('panel.account.cancel'))->assertRedirect();

    expect($this->subscription->fresh()->cancelled_at)->toBeNull()
        ->and(session('panel_error'))->toContain('No pudimos cancelar');
})->with([
    'sin permiso' => [403, ['error' => 'NotPermitted']],
    'no existe en ese entorno' => [404, ['error' => 'ResourceNotFound']],
    'petición inválida' => [422, ['detail' => []]],
]);

it('un fallo de Polar deja rastro para quien opera la plataforma', function (): void {
    Http::fake(['*/v1/subscriptions/sub_de_prueba' => Http::response(['error' => 'Boom'], 500)]);

    $this->actingAs($this->owner)->post(route('panel.account.cancel'));

    expect(SystemEvent::query()->where('type', 'integration.failed')->where('company_id', $this->company->id)->count())->toBe(1);
});

it('sin pasarela configurada no se cancela nada', function (): void {
    config(['polar.access_token' => null]);
    Http::fake();

    $this->actingAs($this->owner)->post(route('panel.account.cancel'))->assertRedirect();

    Http::assertNothingSent();
    expect($this->subscription->fresh()->cancelled_at)->toBeNull()
        ->and(session('panel_error'))->toContain('No pudimos cancelar');
});

it('una suscripción asignada a mano no se cancela desde el panel', function (): void {
    // Sin Polar detrás no hay nada que cancelar allí, y la petición no tendría a qué apuntar.
    $this->subscription->update(['polar_subscription_id' => null]);
    Http::fake();

    $this->actingAs($this->owner)->post(route('panel.account.cancel'))->assertRedirect();

    Http::assertNothingSent();
    expect(session('panel_error'))->toContain('no se puede cancelar desde aquí');
});

it('una empresa en prueba no tiene nada que cancelar', function (): void {
    app(SubscriptionService::class)->subscribe($this->company, $this->plan);
    Http::fake();

    $this->actingAs($this->owner)->post(route('panel.account.cancel'))->assertRedirect();

    Http::assertNothingSent();
    expect(session('panel_error'))->toContain('no se puede cancelar desde aquí');
});

it('deja constancia de quién pidió la baja y de la reactivación', function (): void {
    Http::fake(['*/v1/subscriptions/sub_de_prueba' => Http::response(['id' => 'sub_de_prueba'], 200)]);

    $this->actingAs($this->owner)->post(route('panel.account.cancel'));
    $this->actingAs($this->owner)->post(route('panel.account.resume'));

    $baja = SystemEvent::query()->where('type', 'subscription.cancel_requested')->first();

    expect($baja)->not->toBeNull()
        ->and($baja->company_id)->toBe($this->company->id)
        ->and($baja->user_id)->toBe($this->owner->id)
        ->and(SystemEvent::query()->where('type', 'subscription.resumed')->count())->toBe(1);
});

// -------------------------------------------------------- Quién puede y quién no

it('un cajero no puede cancelar la suscripción de la empresa', function (): void {
    $cajero = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Cajero',
        'email' => 'cajero@heladeria.example.com', 'password' => 'secret-password',
    ]), 'staff');
    Http::fake();

    $this->actingAs($cajero)->post(route('panel.account.cancel'))->assertForbidden();
    $this->actingAs($cajero)->post(route('panel.account.resume'))->assertForbidden();

    Http::assertNothingSent();
    expect($this->subscription->fresh()->cancelled_at)->toBeNull();
});

it('sin sesión no se puede cancelar', function (): void {
    Http::fake();

    $this->post(route('panel.account.cancel'))->assertRedirect('/login');

    Http::assertNothingSent();
});

it('no se puede cancelar la suscripción de otra empresa', function (): void {
    // La suscripción sale siempre de la empresa activa, nunca de la petición: no hay identificador
    // que manipular. El dueño de otra empresa solo puede tocar la suya.
    $otra = app(CompanyService::class)->create(new CreateCompanyData(name: 'Ajena'));
    $ajeno = withRole(User::create([
        'company_id' => $otra->id, 'name' => 'Ajeno',
        'email' => 'ajeno@ajena.example.com', 'password' => 'secret-password',
    ]), 'owner');
    Http::fake();

    $this->actingAs($ajeno)->post(route('panel.account.cancel'))->assertRedirect();

    Http::assertNothingSent();
    expect($this->subscription->fresh()->cancelled_at)->toBeNull();
});

// ------------------------------------------------------------------ Reactivar

it('reactivar vuelve a renovar la suscripción', function (): void {
    $this->subscription->update(['cancelled_at' => now()]);
    Http::fake(['*/v1/subscriptions/sub_de_prueba' => Http::response(['id' => 'sub_de_prueba'], 200)]);

    $this->actingAs($this->owner)
        ->post(route('panel.account.resume'))
        ->assertRedirect(route('panel.account'));

    expect($this->subscription->fresh()->cancelled_at)->toBeNull()
        ->and($this->subscription->fresh()->renewsAutomatically())->toBeTrue()
        ->and(session('panel_ok'))->toContain('se renovará el');

    Http::assertSent(fn ($request): bool => $request->method() === 'PATCH'
        && $request->data() === ['cancel_at_period_end' => false]);
});

it('si Polar dice que no estaba cancelada, reactivar es un éxito', function (): void {
    // 409 «SubscriptionNotScheduledToCancel» en la API real: la app iba por detrás de Polar.
    $this->subscription->update(['cancelled_at' => now()]);
    Http::fake(['*/v1/subscriptions/sub_de_prueba' => Http::response([
        'error' => 'SubscriptionNotScheduledToCancel',
        'detail' => 'This subscription is not scheduled to be canceled, so it cannot be uncanceled.',
    ], 409)]);

    $this->actingAs($this->owner)->post(route('panel.account.resume'))->assertRedirect(route('panel.account'));

    expect($this->subscription->fresh()->cancelled_at)->toBeNull()
        ->and(session('panel_error'))->toBeNull();
});

it('si Polar falla al reactivar, la baja se mantiene', function (): void {
    $this->subscription->update(['cancelled_at' => now()]);
    Http::fake(['*/v1/subscriptions/sub_de_prueba' => Http::response(['error' => 'Boom'], 500)]);

    $this->actingAs($this->owner)->post(route('panel.account.resume'))->assertRedirect();

    expect($this->subscription->fresh()->endsAtPeriodEnd())->toBeTrue()
        ->and(session('panel_error'))->toContain('No pudimos reactivar');
});

it('reactivar una suscripción que ya se renueva no molesta a Polar', function (): void {
    Http::fake();

    $this->actingAs($this->owner)->post(route('panel.account.resume'))->assertRedirect(route('panel.account'));

    Http::assertNothingSent();
    expect(session('panel_error'))->toBeNull();
});

it('pasado el fin del período ya no se puede reactivar', function (): void {
    // Polar ya la revocó: hay que contratar de nuevo, que es otra pantalla.
    $this->subscription->update([
        'status' => SubscriptionStatus::Cancelled,
        'cancelled_at' => now()->subDay(),
        'current_period_end' => now()->subDay(),
    ]);
    Http::fake();

    $this->actingAs($this->owner)->post(route('panel.account.resume'))->assertRedirect();

    Http::assertNothingSent();
    expect(session('panel_error'))->toContain('no se puede reactivar desde aquí');
});

// ------------------------------------------------------------------- Pantalla

it('la pantalla ofrece cancelar a quien Polar cobra sola', function (): void {
    $this->actingAs($this->owner)->get(route('panel.account'))
        ->assertOk()
        ->assertSee('Cancelar suscripción')
        ->assertSee('action="'.route('panel.account.cancel').'"', false)
        ->assertDontSee('action="'.route('panel.account.resume').'"', false);
});

it('el diálogo de cancelar no usa «Cancelar» como salida', function (): void {
    // Con «Cancelar» de botón de descarte, en un diálogo que pregunta «¿Cancelar tu suscripción?» no
    // se sabría qué hace cada botón. La salida dice lo que conserva.
    $this->actingAs($this->owner)->get(route('panel.account'))
        ->assertOk()
        ->assertSee('Mantener mi suscripci', false);
});

it('a quien ya pidió la baja le ofrece reactivar y no cancelar', function (): void {
    $this->subscription->update(['cancelled_at' => now()]);

    $this->actingAs($this->owner)->get(route('panel.account'))
        ->assertOk()
        ->assertSee('Reactivar mi suscripción')
        ->assertSee('action="'.route('panel.account.resume').'"', false)
        ->assertDontSee('action="'.route('panel.account.cancel').'"', false);
});

it('a una empresa en prueba no se le ofrece cancelar', function (): void {
    app(SubscriptionService::class)->subscribe($this->company, $this->plan);

    $this->actingAs($this->owner)->get(route('panel.account'))
        ->assertOk()
        ->assertDontSee('action="'.route('panel.account.cancel').'"', false)
        ->assertDontSee('action="'.route('panel.account.resume').'"', false);
});

it('a una suscripción asignada a mano no se le ofrece cancelar', function (): void {
    $this->subscription->update(['polar_subscription_id' => null]);

    $this->actingAs($this->owner)->get(route('panel.account'))
        ->assertOk()
        ->assertDontSee('action="'.route('panel.account.cancel').'"', false);
});

it('los formularios de cancelar y reactivar no se anidan dentro de otro', function (): void {
    // Un <form> dentro de otro es HTML inválido y el navegador desmonta el interior en silencio.
    foreach ([null, now()] as $baja) {
        $this->subscription->update(['cancelled_at' => $baja]);

        $html = $this->actingAs($this->owner)->get(route('panel.account'))->assertOk()->getContent();

        expect(substr_count($html, '<form'))->toBe(substr_count($html, '</form>'));
    }
});
