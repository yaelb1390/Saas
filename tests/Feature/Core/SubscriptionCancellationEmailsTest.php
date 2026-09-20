<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Enums\SubscriptionStatus;
use App\Modules\Core\Events\SubscriptionCancellationRequested;
use App\Modules\Core\Events\SubscriptionResumed;
use App\Modules\Core\Mail\SubscriptionCancelledMail;
use App\Modules\Core\Mail\SubscriptionResumedMail;
use App\Modules\Core\Models\Plan;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Services\PolarWebhookHandler;
use App\Modules\Core\Services\SubscriptionService;
use App\Modules\Core\Tenancy\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

/*
 * Los correos de baja y de reactivación.
 *
 * Polar manda los suyos —«Your subscription is no longer canceled»— en inglés y con su marca. Estos
 * son los de la empresa: en español, con el nombre de quien cancela, hasta cuándo conserva el acceso
 * y cómo arrepentirse.
 *
 * Lo delicado no es el texto sino el REPARTO. La baja llega por dos puertas —el botón del panel y el
 * aviso de Polar, que también salta si el cliente cancela desde el portal de Polar— y el cliente tiene
 * que recibir UN correo: ni dos (parece un fallo) ni ninguno (no sabe si canceló). Casi todo lo de
 * abajo prueba eso.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(CurrentCompany::class)->forget();

    config([
        'polar.access_token' => 'polar_oat_de_prueba', 'polar.server' => 'sandbox',
        'platform.support_whatsapp' => '18095551234', 'platform.support_email' => 'soporte@bm.test',
    ]);

    $this->company = app(CompanyService::class)->create(new CreateCompanyData(
        name: 'Heladería', email: 'duena@heladeria.example.com',
    ));
    app(CurrentCompany::class)->set($this->company->id);

    $this->owner = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Yael Berroa',
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

    // El aviso de Polar tal como lo procesa el manejador. Se llama al manejador y no a la ruta para no
    // depender de la firma: aquí se prueba qué pasa con el aviso, no si viene de Polar.
    $this->aviso = fn (string $tipo): array => [
        'type' => $tipo,
        'data' => [
            'object' => 'subscription', 'id' => 'sub_de_prueba', 'status' => 'active',
            'product_id' => 'ad5bee12-beb1-48ee-b6ec-1eb5c9d1b6fe',
            'current_period_end' => $this->subscription->fresh()->current_period_end->toIso8601String(),
            'metadata' => ['company_id' => (string) $this->company->id],
        ],
    ];

    Mail::fake();
});

// ------------------------------------------------------------- El correo de baja

it('al cancelar desde el panel, el cliente recibe un correo propio', function (): void {
    Http::fake(['*/v1/subscriptions/sub_de_prueba' => Http::response(['id' => 'sub_de_prueba'], 200)]);

    $this->actingAs($this->owner)->post(route('panel.account.cancel'))->assertRedirect(route('panel.account'));

    Mail::assertSent(SubscriptionCancelledMail::class, 1);
    Mail::assertSent(SubscriptionCancelledMail::class, fn (SubscriptionCancelledMail $correo): bool => $correo->hasTo('duena@heladeria.example.com')
        && $correo->planName === 'Pro'
        && $correo->companyName === 'Heladería'
        && $correo->accessUntil->isSameDay($this->subscription->fresh()->current_period_end));
});

it('el correo de baja dice lo que el cliente necesita saber, en español', function (): void {
    $correo = new SubscriptionCancelledMail(
        ownerName: 'Yael Berroa Pérez', companyName: 'Heladería', planName: 'Prueba RD$40',
        accessUntil: Carbon::parse('2026-10-20'), daysLeft: 30, accountUrl: 'https://bmos.test/panel/cuenta',
        supportWhatsapp: '18095551234', supportEmail: 'soporte@bm.test',
    );

    $correo->assertHasSubject('Cancelaste tu suscripción · Sigues con acceso hasta el 20/10/2026 · BM Business OS');

    $correo->assertSeeInHtml('Hola, Yael')                       // nombre de pila, no el nombre completo
        ->assertDontSeeInHtml('Berroa')
        ->assertSeeInHtml('Heladería')
        ->assertSeeInHtml('no pierdes nada de lo que ya pagaste')
        ->assertSeeInHtml('Hasta el 20/10/2026')
        ->assertSeeInHtml('te quedan 30 días')
        ->assertSeeInHtml('Prueba RD$40')
        ->assertSeeInHtml('Tus datos están a salvo')
        ->assertSeeInHtml('Reactivar mi suscripción')
        ->assertSeeInHtml('https://bmos.test/panel/cuenta', false)
        ->assertSeeInHtml('BM Business OS');

    // La versión en texto plano existe y dice lo mismo: hay clientes de correo que solo muestran esa.
    $correo->assertSeeInText('Hasta el 20/10/2026')
        ->assertSeeInText('Tus datos están a salvo')
        ->assertSeeInText('https://bmos.test/panel/cuenta');
});

it('responder al correo llega a soporte y no a una dirección de envío que nadie lee', function (): void {
    // El correo invita a contar por qué se cancela: sin esto la respuesta se perdería.
    $correo = new SubscriptionCancelledMail(
        ownerName: 'Yael', companyName: 'Heladería', planName: 'Pro', accessUntil: Carbon::parse('2026-10-20'),
        daysLeft: 30, accountUrl: 'https://bmos.test/panel/cuenta',
        supportWhatsapp: '18095551234', supportEmail: 'soporte@bm.test',
    );

    $correo->assertHasReplyTo('soporte@bm.test');
});

it('el correo de baja habla en singular cuando queda un solo día', function (): void {
    $correo = new SubscriptionCancelledMail(
        ownerName: 'Yael', companyName: 'Heladería', planName: 'Pro', accessUntil: Carbon::parse('2026-10-20'),
        daysLeft: 1, accountUrl: 'https://bmos.test/panel/cuenta',
        supportWhatsapp: '18095551234', supportEmail: 'soporte@bm.test',
    );

    $correo->assertSeeInHtml('te queda 1 día')->assertDontSeeInHtml('te quedan');
});

// ------------------------------------------- Un solo correo, venga la baja por donde venga

it('si el cliente cancela desde el portal de Polar, también recibe el correo', function (): void {
    // Aquí el botón del panel nunca se pulsó: solo llega el aviso de Polar. Sin esto, apagar los correos
    // de Polar dejaría a estos clientes sin ninguna confirmación.
    app(PolarWebhookHandler::class)->handle('evt_1', ($this->aviso)('subscription.canceled'));

    Mail::assertSent(SubscriptionCancelledMail::class, 1);
    Mail::assertSent(SubscriptionCancelledMail::class, fn (SubscriptionCancelledMail $correo): bool => $correo->hasTo('duena@heladeria.example.com'));
    expect($this->subscription->fresh()->endsAtPeriodEnd())->toBeTrue();
});

it('si el aviso de Polar llega después del botón, no manda un segundo correo', function (): void {
    // Es el caso normal: pulsar «Cancelar» hace que Polar mande su aviso unos segundos después.
    Http::fake(['*/v1/subscriptions/sub_de_prueba' => Http::response(['id' => 'sub_de_prueba'], 200)]);

    $this->actingAs($this->owner)->post(route('panel.account.cancel'));
    app(PolarWebhookHandler::class)->handle('evt_1', ($this->aviso)('subscription.canceled'));

    Mail::assertSent(SubscriptionCancelledMail::class, 1);
});

it('si el aviso de Polar llega EN MEDIO del clic, tampoco salen dos', function (): void {
    // La carrera de verdad: Polar contesta la petición Y manda su aviso antes de que la app anote la
    // baja. El aviso la anota primero (y avisa al cliente); cuando el botón llega a anotarla, ya está.
    Http::fake(['*/v1/subscriptions/sub_de_prueba' => function () {
        app(PolarWebhookHandler::class)->handle('evt_carrera', ($this->aviso)('subscription.canceled'));

        return Http::response(['id' => 'sub_de_prueba'], 200);
    }]);

    $this->actingAs($this->owner)->post(route('panel.account.cancel'))->assertRedirect(route('panel.account'));

    Mail::assertSent(SubscriptionCancelledMail::class, 1);
    expect($this->subscription->fresh()->endsAtPeriodEnd())->toBeTrue()
        ->and(session('panel_error'))->toBeNull();
});

it('un aviso repetido de Polar no manda otro correo', function (): void {
    // Con el mismo id lo frena el registro de avisos; con otro id, lo frena que la baja ya está anotada.
    app(PolarWebhookHandler::class)->handle('evt_1', ($this->aviso)('subscription.canceled'));
    app(PolarWebhookHandler::class)->handle('evt_1', ($this->aviso)('subscription.canceled'));
    app(PolarWebhookHandler::class)->handle('evt_2', ($this->aviso)('subscription.canceled'));

    Mail::assertSent(SubscriptionCancelledMail::class, 1);
});

it('pulsar cancelar dos veces seguidas manda un solo correo', function (): void {
    Http::fake(['*/v1/subscriptions/sub_de_prueba' => Http::response(['id' => 'sub_de_prueba'], 200)]);

    $this->actingAs($this->owner)->post(route('panel.account.cancel'));
    $this->actingAs($this->owner)->post(route('panel.account.cancel'));

    Mail::assertSent(SubscriptionCancelledMail::class, 1);
});

it('una baja que llega cuando el acceso ya terminó no manda un correo que sería mentira', function (): void {
    // «Sigues con acceso hasta…» solo es verdad si el acceso sigue en pie.
    $this->subscription->update(['current_period_end' => now()->subDay()]);

    app(PolarWebhookHandler::class)->handle('evt_1', ($this->aviso)('subscription.canceled'));

    Mail::assertNothingSent();
});

it('una baja rechazada por Polar no manda ningún correo', function (): void {
    // Si Polar no la acepta, la app no la anota, y decirle al cliente que canceló sería mentirle.
    Http::fake(['*/v1/subscriptions/sub_de_prueba' => Http::response(['error' => 'Boom'], 500)]);

    $this->actingAs($this->owner)->post(route('panel.account.cancel'));

    Mail::assertNothingSent();
});

// ----------------------------------------------------------- El correo de reactivación

it('al reactivar desde el panel, el cliente recibe el correo de que sigue activa', function (): void {
    $this->subscription->update(['cancelled_at' => now()]);
    Http::fake(['*/v1/subscriptions/sub_de_prueba' => Http::response(['id' => 'sub_de_prueba'], 200)]);

    $this->actingAs($this->owner)->post(route('panel.account.resume'))->assertRedirect(route('panel.account'));

    Mail::assertSent(SubscriptionResumedMail::class, 1);
    Mail::assertSent(SubscriptionResumedMail::class, fn (SubscriptionResumedMail $correo): bool => $correo->hasTo('duena@heladeria.example.com')
        && $correo->planName === 'Pro'
        && $correo->renewsAt->isSameDay($this->subscription->fresh()->current_period_end));
});

it('el correo de reactivación dice que sigue todo como antes', function (): void {
    $correo = new SubscriptionResumedMail(
        ownerName: 'Yael Berroa', companyName: 'Heladería', planName: 'Prueba RD$40', planPrice: '40',
        billingCycleLabel: 'Mensual', renewsAt: Carbon::parse('2026-10-20'), accountUrl: 'https://bmos.test/panel/cuenta',
        supportWhatsapp: '18095551234', supportEmail: 'soporte@bm.test',
    );

    $correo->assertHasSubject('Tu suscripción sigue activa · BM Business OS');
    $correo->assertHasReplyTo('soporte@bm.test');
    $correo->assertSeeInHtml('Qué bueno que te quedas, Yael')
        ->assertDontSeeInHtml('Berroa')
        ->assertSeeInHtml('no perdiste nada')
        ->assertSeeInHtml('RD$ 40.00')
        ->assertSeeInHtml('20/10/2026')
        ->assertSeeInText('Próxima renovación: 20/10/2026');
});

it('si el aviso de Polar de la reactivación llega después del botón, no manda un segundo correo', function (): void {
    $this->subscription->update(['cancelled_at' => now()]);
    Http::fake(['*/v1/subscriptions/sub_de_prueba' => Http::response(['id' => 'sub_de_prueba'], 200)]);

    $this->actingAs($this->owner)->post(route('panel.account.resume'));
    app(PolarWebhookHandler::class)->handle('evt_1', ($this->aviso)('subscription.uncanceled'));

    Mail::assertSent(SubscriptionResumedMail::class, 1);
});

it('si el aviso de la reactivación llega EN MEDIO del clic, tampoco salen dos', function (): void {
    $this->subscription->update(['cancelled_at' => now()]);
    Http::fake(['*/v1/subscriptions/sub_de_prueba' => function () {
        app(PolarWebhookHandler::class)->handle('evt_carrera', ($this->aviso)('subscription.uncanceled'));

        return Http::response(['id' => 'sub_de_prueba'], 200);
    }]);

    $this->actingAs($this->owner)->post(route('panel.account.resume'))->assertRedirect(route('panel.account'));

    Mail::assertSent(SubscriptionResumedMail::class, 1);
    expect($this->subscription->fresh()->renewsAutomatically())->toBeTrue();
});

it('si el cliente reactiva desde el portal de Polar, también recibe el correo', function (): void {
    $this->subscription->update(['cancelled_at' => now()]);

    app(PolarWebhookHandler::class)->handle('evt_1', ($this->aviso)('subscription.uncanceled'));

    Mail::assertSent(SubscriptionResumedMail::class, 1);
    expect($this->subscription->fresh()->cancelled_at)->toBeNull();
});

it('los avisos de alta o de cobro de Polar no mandan el correo de reactivación', function (): void {
    // Un aviso `active` NO significa que alguien se arrepintió: también salta en cada renovación. Sin
    // esta guarda, cada cobro mensual mandaría un «¡Qué bueno que te quedas!».
    app(PolarWebhookHandler::class)->handle('evt_1', ($this->aviso)('subscription.active'));
    app(PolarWebhookHandler::class)->handle('evt_2', ($this->aviso)('subscription.created'));

    Mail::assertNothingSent();
});

// ------------------------------------------------------------ Si el correo falla

it('si el correo falla, la baja se anota igual y el cliente no ve un error', function (): void {
    // El SMTP puede estar caído. Eso no puede deshacer una baja que Polar ya aceptó ni devolverle un
    // error a quien acaba de cancelar.
    Mail::shouldReceive('to')->andThrow(new RuntimeException('SMTP caído'));
    Http::fake(['*/v1/subscriptions/sub_de_prueba' => Http::response(['id' => 'sub_de_prueba'], 200)]);

    $this->actingAs($this->owner)->post(route('panel.account.cancel'))->assertRedirect(route('panel.account'));

    expect($this->subscription->fresh()->endsAtPeriodEnd())->toBeTrue()
        ->and(session('panel_error'))->toBeNull()
        ->and(session('panel_ok'))->toContain('Cancelaste tu suscripción');
});

it('si el correo falla, el aviso de Polar se procesa igual', function (): void {
    // Un error aquí haría que Polar reintentara el aviso hasta 10 veces por un fallo del correo.
    Mail::shouldReceive('to')->andThrow(new RuntimeException('SMTP caído'));

    $resultado = app(PolarWebhookHandler::class)->handle('evt_1', ($this->aviso)('subscription.canceled'));

    expect($resultado->result)->toBe('applied')
        ->and($this->subscription->fresh()->endsAtPeriodEnd())->toBeTrue();
});

it('sin un correo al que escribir no se rompe nada', function (): void {
    $this->company->update(['email' => null]);
    $this->owner->update(['is_active' => false]);
    Http::fake(['*/v1/subscriptions/sub_de_prueba' => Http::response(['id' => 'sub_de_prueba'], 200)]);

    $this->actingAs($this->owner)->post(route('panel.account.cancel'))->assertRedirect(route('panel.account'));

    Mail::assertNothingSent();
    expect($this->subscription->fresh()->endsAtPeriodEnd())->toBeTrue();
});

// ---------------------------------------------- El punto de enganche para automatizaciones

it('la baja dispara el evento para automatizaciones una sola vez, por cualquier puerta', function (): void {
    Event::fake([SubscriptionCancellationRequested::class]);
    Http::fake(['*/v1/subscriptions/sub_de_prueba' => Http::response(['id' => 'sub_de_prueba'], 200)]);

    $this->actingAs($this->owner)->post(route('panel.account.cancel'));
    app(PolarWebhookHandler::class)->handle('evt_1', ($this->aviso)('subscription.canceled'));

    Event::assertDispatchedTimes(SubscriptionCancellationRequested::class, 1);
    // Quién pulsó el botón viaja en el evento: n8n puede distinguir una baja del panel de una de Polar.
    Event::assertDispatched(SubscriptionCancellationRequested::class, fn (SubscriptionCancellationRequested $evento): bool => $evento->userId === $this->owner->id
        && $evento->subscription->is($this->subscription));
});

it('la baja hecha en Polar dispara el evento sin usuario', function (): void {
    Event::fake([SubscriptionCancellationRequested::class]);

    app(PolarWebhookHandler::class)->handle('evt_1', ($this->aviso)('subscription.canceled'));

    Event::assertDispatched(SubscriptionCancellationRequested::class, fn (SubscriptionCancellationRequested $evento): bool => $evento->userId === null);
});

it('la reactivación dispara su evento una sola vez, por cualquier puerta', function (): void {
    Event::fake([SubscriptionResumed::class]);
    $this->subscription->update(['cancelled_at' => now()]);
    Http::fake(['*/v1/subscriptions/sub_de_prueba' => Http::response(['id' => 'sub_de_prueba'], 200)]);

    $this->actingAs($this->owner)->post(route('panel.account.resume'));
    app(PolarWebhookHandler::class)->handle('evt_1', ($this->aviso)('subscription.uncanceled'));

    Event::assertDispatchedTimes(SubscriptionResumed::class, 1);
});

it('la baja no toca el estado de la suscripción: sigue activa hasta que llegue la revocación', function (): void {
    app(PolarWebhookHandler::class)->handle('evt_1', ($this->aviso)('subscription.canceled'));

    $suscripcion = $this->subscription->fresh();

    expect($suscripcion->status)->toBe(SubscriptionStatus::Active)
        ->and($suscripcion->isUsable())->toBeTrue();
});
