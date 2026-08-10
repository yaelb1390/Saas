<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Enums\SubscriptionStatus;
use App\Modules\Core\Models\Plan;
use App\Modules\Core\Models\PolarWebhookEvent;
use App\Modules\Core\Models\Subscription;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Tenancy\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/*
 * Webhook de Polar: convierte los avisos de la pasarela en accesos.
 *
 * Es una puerta abierta a internet, sin sesión, que otorga y retira acceso de pago. Dos familias de
 * riesgo se cubren aquí:
 *
 *  - SEGURIDAD: sin firma válida no se acepta nada. Quien pudiera colar un aviso se regalaría la
 *    suscripción que quisiera.
 *  - DINERO: Polar reintenta cada aviso hasta 10 veces y una sola compra dispara varios eventos.
 *    Ni los reintentos ni los eventos hermanos pueden acabar sumando períodos de más.
 */

uses(RefreshDatabase::class);

const POLAR_PRODUCTO_PRO = 'a1f622d8-48d1-4de2-aa25-84e49102045b';

beforeEach(function (): void {
    app(CurrentCompany::class)->forget();

    // Secreto en base64, como lo entrega Polar.
    config(['polar.webhook_secret' => base64_encode('secreto-de-pruebas'), 'polar.webhook_tolerance' => 300]);

    $this->company = app(CompanyService::class)->create(new CreateCompanyData(name: 'Heladería Yael'));

    $this->owner = User::create([
        'company_id' => $this->company->id,
        'name' => 'Dueña',
        'email' => 'duena@heladeria.test',
        'password' => 'secret-password',
    ]);

    $this->plan = Plan::create([
        'name' => 'Pro', 'slug' => 'pro', 'price' => '1500', 'billing_cycle' => 'monthly',
        'trial_days' => 0, 'modules' => null, 'is_active' => true,
        'polar_product_id' => POLAR_PRODUCTO_PRO,
    ]);

    app(CurrentCompany::class)->forget(); // el webhook llega sin empresa activa, como en producción
});

/** Firma un cuerpo tal como lo haría Polar. */
function firmaPolar(string $payload, string $id, int $timestamp): string
{
    $secret = (string) config('polar.webhook_secret');
    $key = base64_decode($secret, true) ?: $secret;

    return 'v1,'.base64_encode(hash_hmac('sha256', "{$id}.{$timestamp}.{$payload}", $key, binary: true));
}

/**
 * Envía un aviso al webhook. Sin opciones va correctamente firmado.
 *
 * @param  array<string, mixed>  $body
 * @param  array<string, mixed>  $opciones  id, timestamp, signature (para forzar casos inválidos)
 */
function enviarAPolar(array $body, array $opciones = []): TestResponse
{
    $payload = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $id = $opciones['id'] ?? 'evt_'.Str::random(12);
    $timestamp = $opciones['timestamp'] ?? time();

    return test()->call('POST', '/webhooks/polar', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_WEBHOOK_ID' => $id,
        'HTTP_WEBHOOK_TIMESTAMP' => (string) $timestamp,
        'HTTP_WEBHOOK_SIGNATURE' => $opciones['signature'] ?? firmaPolar((string) $payload, (string) $id, (int) $timestamp),
    ], (string) $payload);
}

/**
 * Aviso de pago. Por defecto identifica al comprador por su correo (una compra desde un enlace
 * suelto de Polar, sin datos adjuntos).
 *
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function pagoPolar(array $extra = []): array
{
    return ['type' => 'order.paid', 'data' => array_merge([
        'id' => 'ord_'.Str::random(6),
        'object' => 'order',
        'product_id' => POLAR_PRODUCTO_PRO,
        'subscription_id' => 'sub_polar_1',
        'customer_id' => 'cus_polar_1',
        'customer' => ['id' => 'cus_polar_1', 'email' => 'duena@heladeria.test'],
    ], $extra)];
}

/**
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function suscripcionPolar(string $tipo, array $extra = []): array
{
    return ['type' => $tipo, 'data' => array_merge([
        'id' => 'sub_polar_1',
        'object' => 'subscription',
        'product_id' => POLAR_PRODUCTO_PRO,
        'customer_id' => 'cus_polar_1',
        'customer' => ['id' => 'cus_polar_1', 'email' => 'duena@heladeria.test'],
    ], $extra)];
}

// ---------------------------------------------------------------- Seguridad

it('rechaza un aviso sin firma', function (): void {
    // Sin esto, un POST a mano activaría la suscripción de cualquiera.
    enviarAPolar(pagoPolar(), ['signature' => ''])->assertStatus(401);

    expect(Subscription::count())->toBe(0);
});

it('rechaza una firma que no cuadra', function (): void {
    enviarAPolar(pagoPolar(), ['signature' => 'v1,'.base64_encode('firma inventada')])
        ->assertStatus(401);

    expect(Subscription::count())->toBe(0);
});

it('rechaza un aviso legítimo pero reenviado tiempo después', function (): void {
    // Ataque de repetición: alguien captura el aviso de un pago real y lo reenvía cada mes para
    // renovar gratis. La firma cuadraría; lo que lo delata es el sello de tiempo.
    $viejo = time() - 3600;

    enviarAPolar(pagoPolar(), ['timestamp' => $viejo])->assertStatus(401);

    expect(Subscription::count())->toBe(0);
});

it('rechaza todo si no hay secreto configurado', function (): void {
    // Callar y responder 200 sería peor: Polar daría los avisos por entregados y los pagos se
    // perderían en silencio.
    config(['polar.webhook_secret' => '']);

    enviarAPolar(pagoPolar())->assertStatus(503);
});

it('acepta el secreto tal cual, sin codificar en base64', function (): void {
    // Los paneles no siempre entregan el secreto en base64; debe funcionar copiado tal cual.
    config(['polar.webhook_secret' => 'secreto-plano-sin-base64']);

    enviarAPolar(pagoPolar())->assertStatus(202);

    expect(Subscription::count())->toBe(1);
});

// ---------------------------------------------------------------- Activación

it('un pago activa la suscripción de la empresa', function (): void {
    enviarAPolar(pagoPolar())->assertStatus(202);

    $subscription = $this->company->subscription;

    expect($subscription)->not->toBeNull()
        ->and($subscription->status)->toBe(SubscriptionStatus::Active)
        ->and($subscription->plan_id)->toBe($this->plan->id)
        ->and($subscription->isUsable())->toBeTrue();
});

it('guarda los identificadores de Polar para los avisos futuros', function (): void {
    // Sin ellos, la baja que llegue meses después no sabría a qué suscripción aplicar.
    enviarAPolar(pagoPolar())->assertStatus(202);

    expect($this->company->subscription->polar_subscription_id)->toBe('sub_polar_1')
        ->and($this->company->subscription->polar_customer_id)->toBe('cus_polar_1');
});

it('copia el período que manda Polar en vez de calcularlo', function (): void {
    // Que la fecha la mande Polar es lo que hace que los avisos repetidos o desordenados converjan
    // siempre al mismo resultado.
    $fin = Carbon::now()->addMonths(3)->startOfSecond();

    enviarAPolar(pagoPolar(['current_period_end' => $fin->toIso8601String()]))->assertStatus(202);

    expect($this->company->subscription->current_period_end->toIso8601String())
        ->toBe($fin->toIso8601String());
});

it('identifica la empresa por los datos adjuntos del cobro', function (): void {
    // El camino fiable: el company_id que la propia aplicación adjunta al crear el cobro.
    $body = pagoPolar([
        'customer' => ['id' => 'cus_polar_1', 'email' => 'desconocido@otro.test'],
        'metadata' => ['company_id' => (string) $this->company->id],
    ]);

    enviarAPolar($body)->assertStatus(202);

    expect($this->company->subscription)->not->toBeNull();
});

it('deja el pago sin aplicar y anotado si no reconoce a la empresa', function (): void {
    // Nunca se adivina: se registra para resolverlo a mano. Activar «la empresa más probable»
    // sería regalarle el plan a quien no pagó.
    $body = pagoPolar(['customer' => ['id' => 'cus_x', 'email' => 'nadie@ninguna.test']]);

    enviarAPolar($body)->assertStatus(202);

    expect(Subscription::count())->toBe(0)
        ->and(PolarWebhookEvent::first()->result)->toBe(PolarWebhookEvent::RESULT_UNRESOLVED);
});

it('deja el pago sin aplicar si el producto no está enlazado con ningún plan', function (): void {
    $body = pagoPolar(['product_id' => 'producto-que-nadie-enlazó']);

    enviarAPolar($body)->assertStatus(202);

    expect(Subscription::count())->toBe(0)
        ->and(PolarWebhookEvent::first()->result)->toBe(PolarWebhookEvent::RESULT_UNRESOLVED);
});

// ---------------------------------------------------------------- Dinero: nada se cobra dos veces

it('el mismo aviso repetido no extiende el período dos veces', function (): void {
    // Polar reintenta hasta 10 veces ante cualquier respuesta que no sea 2xx. Sin idempotencia, un
    // simple tiempo de espera agotado regalaría meses de servicio.
    $body = pagoPolar();

    enviarAPolar($body, ['id' => 'evt_repetido'])->assertStatus(202);
    $primerFin = $this->company->subscription->current_period_end;

    enviarAPolar($body, ['id' => 'evt_repetido'])->assertStatus(202);

    expect($this->company->subscription->fresh()->current_period_end->toIso8601String())
        ->toBe($primerFin->toIso8601String())
        ->and(PolarWebhookEvent::count())->toBe(1);
});

it('los dos avisos de una misma compra no suman dos períodos', function (): void {
    // Una compra dispara «order.paid» Y «subscription.active». Son eventos distintos, así que la
    // idempotencia por identificador no los cubre: lo que los cubre es que solo el pago suma tiempo.
    enviarAPolar(pagoPolar())->assertStatus(202);
    $trasElPago = $this->company->subscription->current_period_end;

    enviarAPolar(suscripcionPolar('subscription.active'))->assertStatus(202);

    expect($this->company->subscription->fresh()->current_period_end->toIso8601String())
        ->toBe($trasElPago->toIso8601String());
});

it('una renovación posterior sí encadena un período nuevo', function (): void {
    // La otra cara: un pago de verdad en el mes siguiente debe alargar, no quedarse quieto.
    enviarAPolar(pagoPolar())->assertStatus(202);
    $primerFin = $this->company->subscription->current_period_end;

    enviarAPolar(pagoPolar())->assertStatus(202); // otro evento, otro pago

    expect($this->company->subscription->fresh()->current_period_end->greaterThan($primerFin))->toBeTrue();
});

// ---------------------------------------------------------------- Bajas

it('pedir la baja NO corta el acceso ya pagado', function (): void {
    // «canceled» en Polar significa «no renovar»: el período en curso está pagado y cortarlo aquí
    // sería quitarle un servicio a quien lo pagó.
    enviarAPolar(pagoPolar())->assertStatus(202);

    enviarAPolar(suscripcionPolar('subscription.canceled'))->assertStatus(202);

    $subscription = $this->company->subscription->fresh();

    expect($subscription->status)->toBe(SubscriptionStatus::Active)
        ->and($subscription->isUsable())->toBeTrue()
        ->and($subscription->cancelled_at)->not->toBeNull(); // queda constancia de la baja
});

it('la revocación sí retira el acceso', function (): void {
    // «revoked» es cuando la baja surte efecto: ahí sí se corta.
    enviarAPolar(pagoPolar())->assertStatus(202);

    enviarAPolar(suscripcionPolar('subscription.revoked'))->assertStatus(202);

    $subscription = $this->company->subscription->fresh();

    expect($subscription->status)->toBe(SubscriptionStatus::Cancelled)
        ->and($subscription->isUsable())->toBeFalse();
});

it('encuentra la suscripción por el identificador de Polar aunque no reconozca el correo', function (): void {
    // El caso real de una baja meses después: el aviso solo trae identificadores de Polar.
    enviarAPolar(pagoPolar())->assertStatus(202);

    enviarAPolar(suscripcionPolar('subscription.revoked', [
        'customer' => ['id' => 'cus_polar_1', 'email' => 'correo-cambiado@otro.test'],
    ]))->assertStatus(202);

    expect($this->company->subscription->fresh()->status)->toBe(SubscriptionStatus::Cancelled);
});

it('volver atrás en la baja reactiva la suscripción', function (): void {
    enviarAPolar(pagoPolar())->assertStatus(202);
    enviarAPolar(suscripcionPolar('subscription.canceled'))->assertStatus(202);

    enviarAPolar(suscripcionPolar('subscription.uncanceled'))->assertStatus(202);

    $subscription = $this->company->subscription->fresh();

    expect($subscription->cancelled_at)->toBeNull()
        ->and($subscription->isUsable())->toBeTrue();
});

// ---------------------------------------------------------------- Resto

it('acepta y anota los eventos que no le incumben', function (): void {
    // Polar envía muchos tipos. Responder 2xx evita que los reintente 10 veces sin motivo.
    enviarAPolar(['type' => 'checkout.created', 'data' => ['id' => 'chk_1']])->assertStatus(202);

    expect(PolarWebhookEvent::first()->result)->toBe(PolarWebhookEvent::RESULT_IGNORED)
        ->and(Subscription::count())->toBe(0);
});

it('deja el cuerpo entero guardado para poder auditar', function (): void {
    enviarAPolar(pagoPolar())->assertStatus(202);

    $event = PolarWebhookEvent::first();

    expect($event->type)->toBe('order.paid')
        ->and($event->payload['data']['product_id'])->toBe(POLAR_PRODUCTO_PRO)
        ->and($event->company_id)->toBe($this->company->id);
});
