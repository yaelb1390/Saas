<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Enums\SubscriptionStatus;
use App\Modules\Core\Events\SubscriptionEnded;
use App\Modules\Core\Events\SubscriptionPaymentFailed;
use App\Modules\Core\Mail\SubscriptionEndedMail;
use App\Modules\Core\Mail\SubscriptionExpiringMail;
use App\Modules\Core\Mail\SubscriptionPaymentFailedMail;
use App\Modules\Core\Mail\SubscriptionRenewalNoticeMail;
use App\Modules\Core\Models\Plan;
use App\Modules\Core\Models\SystemEvent;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Services\PolarWebhookHandler;
use App\Modules\Core\Services\SubscriptionService;
use App\Modules\Core\Support\SubscriptionNotice;
use App\Modules\Core\Tenancy\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

/*
 * Los tres correos que faltaban para poder apagar los de Polar, que llegan en inglés: el cobro que falla,
 * la suscripción que termina y el aviso de que se va a renovar sola. Y el arreglo de fondo que los
 * acompaña: a quien Polar cobra solo ya no se le dice «Renueva para no perder el acceso».
 *
 * Como en los correos de baja, lo delicado es el REPARTO más que el texto: Polar reintenta los avisos y
 * cada uno llega por más de una puerta, y el cliente tiene que recibir UN correo, no tres.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutVite();
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
        'polar_product_id' => 'b1c2d3e4-0000-4000-8000-000000000001',
    ]);

    // Una empresa que ya pagó: activa, enlazada con su suscripción y su cliente de Polar, con el período vigente.
    $this->subscription = app(SubscriptionService::class)->subscribe($this->company, $this->plan, withTrial: false);
    $this->subscription->update([
        'polar_subscription_id' => 'sub_de_prueba',
        'polar_customer_id' => 'cus_de_prueba',
        'current_period_end' => now()->addDays(20),
    ]);

    // El aviso de Polar tal como lo procesa el manejador (sin pasar por la firma: se prueba qué pasa con el
    // aviso, no si viene de Polar).
    $this->aviso = fn (string $tipo): array => [
        'type' => $tipo,
        'data' => [
            'object' => 'subscription', 'id' => 'sub_de_prueba', 'status' => 'active',
            'product_id' => 'b1c2d3e4-0000-4000-8000-000000000001',
            'current_period_end' => $this->subscription->fresh()->current_period_end->toIso8601String(),
            'metadata' => ['company_id' => (string) $this->company->id],
        ],
    ];

    Mail::fake();
});

// ================================================================== El cobro que falla

it('un cobro fallido le manda al cliente el correo para actualizar su tarjeta', function (): void {
    $resultado = app(PolarWebhookHandler::class)->handle('evt_1', ($this->aviso)('subscription.past_due'));

    Mail::assertSent(SubscriptionPaymentFailedMail::class, 1);
    Mail::assertSent(SubscriptionPaymentFailedMail::class, fn (SubscriptionPaymentFailedMail $correo): bool => $correo->hasTo('duena@heladeria.example.com')
        && $correo->planName === 'Pro'
        && $correo->companyName === 'Heladería'
        // Una ruta NUESTRA, no el portal de Polar: la sesión del portal caduca en una hora.
        && $correo->updateCardUrl === route('panel.account.portal'));

    expect($resultado->result)->toBe('applied')
        ->and($resultado->note)->toContain('se avisó al cliente');
});

it('un cobro fallido no quita el acceso ni toca el período: solo avisa', function (): void {
    $antes = $this->subscription->fresh();

    app(PolarWebhookHandler::class)->handle('evt_1', ($this->aviso)('subscription.past_due'));

    $despues = $this->subscription->fresh();

    // Quitar el acceso por un cobro fallido es una decisión de negocio aparte; Polar reintenta durante días.
    expect($despues->status)->toBe(SubscriptionStatus::Active)
        ->and($despues->isUsable())->toBeTrue()
        ->and($despues->current_period_end->equalTo($antes->current_period_end))->toBeTrue();
});

it('Polar reintenta el cobro y avisa varias veces: el cliente recibe un solo correo al día', function (): void {
    // Con otro id de aviso cada vez, para que no lo frene el registro de avisos repetidos sino la regla propia.
    $primero = app(PolarWebhookHandler::class)->handle('evt_1', ($this->aviso)('subscription.past_due'));
    $segundo = app(PolarWebhookHandler::class)->handle('evt_2', ($this->aviso)('subscription.past_due'));
    app(PolarWebhookHandler::class)->handle('evt_3', ($this->aviso)('subscription.past_due'));

    Mail::assertSent(SubscriptionPaymentFailedMail::class, 1);
    expect($primero->note)->toContain('se avisó al cliente')
        ->and($segundo->note)->toContain('ya fue avisado hoy');
});

it('si el cobro sigue fallando al día siguiente, se le vuelve a avisar', function (): void {
    app(PolarWebhookHandler::class)->handle('evt_1', ($this->aviso)('subscription.past_due'));

    $this->travel(25)->hours();

    app(PolarWebhookHandler::class)->handle('evt_2', ($this->aviso)('subscription.past_due'));

    Mail::assertSent(SubscriptionPaymentFailedMail::class, 2);
});

it('un cobro fallido sin suscripción que lo reciba queda sin resolver y no manda nada', function (): void {
    $resultado = app(PolarWebhookHandler::class)->handle('evt_1', [
        'type' => 'subscription.past_due',
        'data' => ['object' => 'subscription', 'id' => 'sub_ajena', 'status' => 'past_due', 'metadata' => []],
    ]);

    expect($resultado->result)->toBe('unresolved');
    Mail::assertNothingSent();
});

it('un cobro fallido deja aviso en el registro del sistema, para que lo vea el operador', function (): void {
    app(PolarWebhookHandler::class)->handle('evt_1', ($this->aviso)('subscription.past_due'));

    $evento = SystemEvent::query()->withoutGlobalScopes()->where('type', 'subscription.payment_failed')->first();

    expect($evento)->not->toBeNull()
        ->and($evento->level)->toBe(SystemEvent::AVISO)
        ->and($evento->company_id)->toBe($this->company->id);
});

it('el cobro fallido dispara su evento para automatizaciones una sola vez', function (): void {
    Event::fake([SubscriptionPaymentFailed::class]);

    app(PolarWebhookHandler::class)->handle('evt_1', ($this->aviso)('subscription.past_due'));
    app(PolarWebhookHandler::class)->handle('evt_2', ($this->aviso)('subscription.past_due'));

    Event::assertDispatchedTimes(SubscriptionPaymentFailed::class, 1);
    Event::assertDispatched(SubscriptionPaymentFailed::class, fn (SubscriptionPaymentFailed $evento): bool => $evento->subscription->is($this->subscription));
});

it('el correo de cobro fallido dice qué pasó y qué hacer, en español', function (): void {
    $correo = new SubscriptionPaymentFailedMail(
        ownerName: 'Yael Berroa Pérez', companyName: 'Heladería', planName: 'Pro', planPrice: '40',
        billingCycleLabel: 'Mensual', updateCardUrl: 'https://bmos.test/panel/cuenta/tarjeta',
        supportWhatsapp: '18095551234', supportEmail: 'soporte@bm.test',
    );

    $correo->assertHasSubject('No pudimos cobrar tu suscripción · Actualiza tu tarjeta · BM Business OS');
    $correo->assertHasReplyTo('soporte@bm.test');

    $correo->assertSeeInHtml('Hola, Yael')
        ->assertDontSeeInHtml('Berroa')
        ->assertSeeInHtml('No pudimos cobrar la renovación')
        ->assertSeeInHtml('Heladería')
        ->assertSeeInHtml('RD$ 40.00')
        ->assertSeeInHtml('Actualizar mi tarjeta')
        ->assertSeeInHtml('https://bmos.test/panel/cuenta/tarjeta', false)
        ->assertSeeInHtml('Tus datos no se borran');

    $correo->assertSeeInText('No pudimos cobrar la renovación')
        ->assertSeeInText('Actualizar mi tarjeta:')
        ->assertSeeInText('https://bmos.test/panel/cuenta/tarjeta');
});

it('si el correo de cobro fallido no sale, el aviso de Polar se procesa igual', function (): void {
    // Un error aquí haría que Polar reintentara el aviso por un fallo del SMTP.
    Mail::shouldReceive('to')->andThrow(new RuntimeException('SMTP caído'));

    $resultado = app(PolarWebhookHandler::class)->handle('evt_1', ($this->aviso)('subscription.past_due'));

    expect($resultado->result)->toBe('applied');
});

// =========================================================== La suscripción que termina

it('al revocarse la suscripción, el cliente recibe el correo de que terminó y pierde el acceso', function (): void {
    app(PolarWebhookHandler::class)->handle('evt_1', ($this->aviso)('subscription.revoked'));

    $suscripcion = $this->subscription->fresh();

    expect($suscripcion->status)->toBe(SubscriptionStatus::Cancelled)
        ->and($suscripcion->isUsable())->toBeFalse();

    Mail::assertSent(SubscriptionEndedMail::class, 1);
    Mail::assertSent(SubscriptionEndedMail::class, fn (SubscriptionEndedMail $correo): bool => $correo->hasTo('duena@heladeria.example.com')
        && $correo->planName === 'Pro'
        && $correo->companyName === 'Heladería'
        && $correo->resubscribeUrl === route('panel.account'));
});

it('el correo distingue a quien pidió la baja de a quien se le terminó por otra causa', function (): void {
    // Sin baja pedida: suele ser un cobro que no se pudo hacer. Decirle «como pediste» sería mentirle.
    app(PolarWebhookHandler::class)->handle('evt_1', ($this->aviso)('subscription.revoked'));

    Mail::assertSent(SubscriptionEndedMail::class, fn (SubscriptionEndedMail $correo): bool => $correo->requestedByCustomer === false);
});

it('quien pidió la baja recibe el correo de que terminó, como pidió', function (): void {
    app(PolarWebhookHandler::class)->handle('evt_1', ($this->aviso)('subscription.canceled'));
    app(PolarWebhookHandler::class)->handle('evt_2', ($this->aviso)('subscription.revoked'));

    // `cancel()` pisa `cancelled_at` al retirar el acceso: el correo tiene que haber leído la baja ANTES.
    Mail::assertSent(SubscriptionEndedMail::class, 1);
    Mail::assertSent(SubscriptionEndedMail::class, fn (SubscriptionEndedMail $correo): bool => $correo->requestedByCustomer === true);
});

it('un segundo aviso de revocación no manda otro correo', function (): void {
    app(PolarWebhookHandler::class)->handle('evt_1', ($this->aviso)('subscription.revoked'));
    app(PolarWebhookHandler::class)->handle('evt_1', ($this->aviso)('subscription.revoked')); // mismo id
    app(PolarWebhookHandler::class)->handle('evt_2', ($this->aviso)('subscription.revoked')); // otro id

    Mail::assertSent(SubscriptionEndedMail::class, 1);
    expect(SystemEvent::query()->withoutGlobalScopes()->where('type', 'subscription.ended')->count())->toBe(1);
});

it('terminar una suscripción ya terminada no repite nada', function (): void {
    $servicio = app(SubscriptionService::class);

    expect($servicio->end($this->subscription))->toBeTrue()
        ->and($servicio->end($this->subscription->fresh()))->toBeFalse();

    Mail::assertSent(SubscriptionEndedMail::class, 1);
});

it('la revocación deja rastro y dispara su evento con quién la pidió', function (): void {
    Event::fake([SubscriptionEnded::class]);

    app(PolarWebhookHandler::class)->handle('evt_1', ($this->aviso)('subscription.canceled'));
    app(PolarWebhookHandler::class)->handle('evt_2', ($this->aviso)('subscription.revoked'));

    Event::assertDispatchedTimes(SubscriptionEnded::class, 1);
    Event::assertDispatched(SubscriptionEnded::class, fn (SubscriptionEnded $evento): bool => $evento->requestedByCustomer === true
        && $evento->subscription->is($this->subscription));

    $rastro = SystemEvent::query()->withoutGlobalScopes()->where('type', 'subscription.ended')->first();

    expect($rastro)->not->toBeNull()
        ->and($rastro->context['pidio_la_baja'])->toBeTrue();
});

it('una revocación sin suscripción que la reciba queda sin resolver y no manda nada', function (): void {
    $resultado = app(PolarWebhookHandler::class)->handle('evt_1', [
        'type' => 'subscription.revoked',
        'data' => ['object' => 'subscription', 'id' => 'sub_ajena', 'status' => 'canceled', 'metadata' => []],
    ]);

    expect($resultado->result)->toBe('unresolved');
    Mail::assertNothingSent();
});

it('el correo de suscripción terminada tranquiliza sobre los datos, en las dos versiones', function (bool $pidioLaBaja, string $frase, string $noDebeDecir): void {
    $correo = new SubscriptionEndedMail(
        ownerName: 'Yael Berroa Pérez', companyName: 'Heladería', planName: 'Pro',
        requestedByCustomer: $pidioLaBaja, resubscribeUrl: 'https://bmos.test/panel/cuenta',
        supportWhatsapp: '18095551234', supportEmail: 'soporte@bm.test',
    );

    $correo->assertHasSubject('Tu suscripción ha terminado · Tus datos siguen guardados · BM Business OS');
    $correo->assertHasReplyTo('soporte@bm.test');

    $correo->assertSeeInHtml('Hola, Yael')
        ->assertDontSeeInHtml('Berroa')
        ->assertSeeInHtml($frase)
        ->assertDontSeeInHtml($noDebeDecir)
        ->assertSeeInHtml('Tus datos están a salvo')
        ->assertSeeInHtml('Volver a contratar')
        ->assertSeeInHtml('https://bmos.test/panel/cuenta', false);

    $correo->assertSeeInText($frase)
        ->assertSeeInText('Tus datos están a salvo')
        ->assertSeeInText('https://bmos.test/panel/cuenta');
})->with([
    // Frases de una sola línea en la plantilla: `assertSeeInHtml` no normaliza los saltos de línea.
    'la pidió el cliente' => [true, 'Gracias por haber usado BM Business OS', 'cobrar la renovación'],
    'se terminó por otra causa' => [false, 'cobrar la renovación', 'Gracias por haber usado'],
]);

it('si el correo de suscripción terminada no sale, la revocación se aplica igual', function (): void {
    Mail::shouldReceive('to')->andThrow(new RuntimeException('SMTP caído'));

    $resultado = app(PolarWebhookHandler::class)->handle('evt_1', ($this->aviso)('subscription.revoked'));

    expect($resultado->result)->toBe('applied')
        ->and($this->subscription->fresh()->isUsable())->toBeFalse();
});

it('sin un correo al que escribir, la revocación se aplica y no se rompe nada', function (): void {
    $this->company->update(['email' => null]);
    $this->owner->update(['is_active' => false]);

    app(PolarWebhookHandler::class)->handle('evt_1', ($this->aviso)('subscription.revoked'));

    Mail::assertNothingSent();
    expect($this->subscription->fresh()->isUsable())->toBeFalse();
});

// ============================================ El aviso de renovación (y el «Renueva» que sobraba)

it('a quien Polar cobra solo se le avisa de que SE RENOVARÁ, no de que vence', function (): void {
    $this->subscription->update(['current_period_end' => now()->addDays(3)]);

    $this->artisan('subscriptions:remind-expiring')->assertSuccessful();

    Mail::assertSent(SubscriptionRenewalNoticeMail::class, 1);
    Mail::assertSent(SubscriptionRenewalNoticeMail::class, fn (SubscriptionRenewalNoticeMail $correo): bool => $correo->hasTo('duena@heladeria.example.com')
        && $correo->planName === 'Pro'
        && $correo->daysLeft === 3
        && $correo->renewsAt->isSameDay($this->subscription->fresh()->current_period_end)
        && $correo->accountUrl === route('panel.account')
        && $correo->updateCardUrl === route('panel.account.portal'));

    // El de «vence, renueva a tiempo» es justo el que llevaba a pagar algo que ya se paga solo.
    Mail::assertNotQueued(SubscriptionExpiringMail::class);
    Mail::assertNotSent(SubscriptionExpiringMail::class);
    expect($this->subscription->fresh()->renewal_reminded_at)->not->toBeNull();
});

it('el aviso de renovación sale una sola vez por período', function (): void {
    $this->subscription->update(['current_period_end' => now()->addDays(3)]);

    $this->artisan('subscriptions:remind-expiring');
    $this->artisan('subscriptions:remind-expiring');

    Mail::assertSent(SubscriptionRenewalNoticeMail::class, 1);
});

it('con la renovación aún lejos no avisa', function (): void {
    // Mensual: el umbral es de 5 días.
    $this->subscription->update(['current_period_end' => now()->addDays(20)]);

    $this->artisan('subscriptions:remind-expiring')->assertSuccessful();

    Mail::assertNothingSent();
    Mail::assertNothingQueued();
});

it('el simulacro dice que se renueva sola, y ni manda ni marca', function (): void {
    $this->subscription->update(['current_period_end' => now()->addDays(3)]);

    $this->artisan('subscriptions:remind-expiring', ['--simular' => true])
        ->expectsOutputToContain('se renueva sola')
        ->assertSuccessful();

    Mail::assertNothingSent();
    expect($this->subscription->fresh()->renewal_reminded_at)->toBeNull();
});

it('si el aviso de renovación no sale, no se marca como enviado y se reintenta', function (): void {
    $this->subscription->update(['current_period_end' => now()->addDays(3)]);
    Mail::shouldReceive('to')->andThrow(new RuntimeException('SMTP caído'));

    $this->artisan('subscriptions:remind-expiring')->assertSuccessful();

    expect($this->subscription->fresh()->renewal_reminded_at)->toBeNull();
});

it('quien ya pidió la baja sigue con el aviso de vencimiento: su acceso sí termina', function (): void {
    $this->subscription->update(['current_period_end' => now()->addDays(3), 'cancelled_at' => now()]);

    $this->artisan('subscriptions:remind-expiring')->assertSuccessful();

    Mail::assertQueued(SubscriptionExpiringMail::class, 1);
    Mail::assertNotSent(SubscriptionRenewalNoticeMail::class);
});

it('la suscripción asignada a mano, sin Polar, sigue con el aviso de vencimiento', function (): void {
    $this->subscription->update([
        'current_period_end' => now()->addDays(3), 'polar_subscription_id' => null, 'polar_customer_id' => null,
    ]);

    $this->artisan('subscriptions:remind-expiring')->assertSuccessful();

    Mail::assertQueued(SubscriptionExpiringMail::class, 1);
    Mail::assertNotSent(SubscriptionRenewalNoticeMail::class);
});

it('el correo de aviso de renovación dice cuándo, cuánto y cómo evitarlo, en español', function (): void {
    $correo = new SubscriptionRenewalNoticeMail(
        ownerName: 'Yael Berroa Pérez', companyName: 'Heladería', planName: 'Pro', planPrice: '40',
        billingCycleLabel: 'Mensual', renewsAt: Carbon::parse('2026-10-20'), daysLeft: 3,
        accountUrl: 'https://bmos.test/panel/cuenta', updateCardUrl: 'https://bmos.test/panel/cuenta/tarjeta',
        supportWhatsapp: '18095551234', supportEmail: 'soporte@bm.test',
    );

    $correo->assertHasSubject('Tu suscripción se renovará el 20/10/2026 · BM Business OS');
    $correo->assertHasReplyTo('soporte@bm.test');

    $correo->assertSeeInHtml('Hola, Yael')
        ->assertDontSeeInHtml('Berroa')
        ->assertSeeInHtml('se renovará automáticamente')
        ->assertSeeInHtml('No tienes que hacer nada')
        ->assertSeeInHtml('20/10/2026')
        ->assertSeeInHtml('en 3 días')
        ->assertSeeInHtml('RD$ 40.00')
        ->assertSeeInHtml('Cancela la suscripción desde tu panel')
        ->assertSeeInHtml('https://bmos.test/panel/cuenta', false)
        ->assertSeeInHtml('https://bmos.test/panel/cuenta/tarjeta', false)
        // Lo contrario de lo que decía el aviso que se retira.
        ->assertDontSeeInHtml('Renueva');

    $correo->assertSeeInText('se renovará automáticamente')
        ->assertSeeInText('20/10/2026 · en 3 días')
        ->assertSeeInText('https://bmos.test/panel/cuenta/tarjeta');
});

it('el aviso de renovación habla de mañana y de hoy, sin contar días', function (int $dias, string $dice, string $noDice): void {
    $correo = new SubscriptionRenewalNoticeMail(
        ownerName: 'Ana', companyName: 'Heladería', planName: 'Pro', planPrice: '1500', billingCycleLabel: 'Mensual',
        renewsAt: Carbon::parse('2026-10-20'), daysLeft: $dias, accountUrl: 'https://bmos.test/panel/cuenta',
        updateCardUrl: 'https://bmos.test/panel/cuenta/tarjeta', supportWhatsapp: '18095551234', supportEmail: 'soporte@bm.test',
    );

    $correo->assertSeeInHtml($dice)->assertDontSeeInHtml($noDice);
})->with([
    'mañana' => [1, 'mañana', 'en 1 días'],
    'hoy' => [0, 'hoy', 'en 0 días'],
]);

// ------------------------------------------------- El banner y el monitoreo dejan de mentir

it('a quien se renueva solo el panel no le dice «Renueva para no perder el acceso»', function (): void {
    $this->subscription->update(['current_period_end' => now()->addDays(2)]);

    expect(SubscriptionNotice::for($this->subscription->fresh()))->toBeNull();

    $this->actingAs($this->owner)->get(route('panel.account'))
        ->assertOk()
        ->assertDontSee('Renueva para no perder el acceso')
        ->assertDontSee('subscriptionNotice', false);
});

it('quien pidió la baja ve que termina y que puede reactivarla, no que tiene que renovar', function (): void {
    $this->subscription->update(['current_period_end' => now()->addDays(3), 'cancelled_at' => now()]);

    $aviso = SubscriptionNotice::for($this->subscription->fresh());

    expect($aviso)->not->toBeNull()
        ->and($aviso->message)->toContain('termina en 3 días')
        ->and($aviso->message)->toContain('Reactívala')
        ->and($aviso->message)->not->toContain('Renueva');
});

it('la suscripción que vence sin renovarse sola conserva el aviso de siempre', function (): void {
    $this->subscription->update([
        'current_period_end' => now()->addDays(3), 'polar_subscription_id' => null, 'polar_customer_id' => null,
    ]);

    $aviso = SubscriptionNotice::for($this->subscription->fresh());

    expect($aviso?->message)->toContain('vence en 3 días')
        ->and($aviso?->message)->toContain('Renueva para no perder el acceso');
});

it('renewalNoticeDays solo devuelve días para la que se renueva sola y está dentro del umbral', function (): void {
    $suscripcion = $this->subscription;

    $suscripcion->update(['current_period_end' => now()->addDays(3)]);
    expect(SubscriptionNotice::renewalNoticeDays($suscripcion->fresh()))->toBe(3);

    $suscripcion->update(['current_period_end' => now()->addDays(20)]);
    expect(SubscriptionNotice::renewalNoticeDays($suscripcion->fresh()))->toBeNull();

    $suscripcion->update(['current_period_end' => now()->addDays(3), 'cancelled_at' => now()]);
    expect(SubscriptionNotice::renewalNoticeDays($suscripcion->fresh()))->toBeNull();

    $suscripcion->update(['cancelled_at' => null, 'polar_subscription_id' => null]);
    expect(SubscriptionNotice::renewalNoticeDays($suscripcion->fresh()))->toBeNull();
});

// ========================================================= El portal para cambiar la tarjeta

it('el dueño llega al portal de Polar con una sesión pedida en ese momento', function (): void {
    Http::fake(['*/v1/customer-sessions/' => Http::response([
        'customer_portal_url' => 'https://sandbox.polar.example/portal/sesion-de-prueba',
    ], 201)]);

    $this->actingAs($this->owner)->get(route('panel.account.portal'))
        ->assertRedirect('https://sandbox.polar.example/portal/sesion-de-prueba');

    Http::assertSentCount(1);
    Http::assertSent(fn ($peticion): bool => str_ends_with($peticion->url(), '/v1/customer-sessions/')
        && $peticion['customer_id'] === 'cus_de_prueba'
        && $peticion['return_url'] === route('panel.account'));
});

it('un cajero no puede abrir el portal de pagos de la empresa', function (): void {
    $cajero = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Cajero',
        'email' => 'cajero@heladeria.example.com', 'password' => 'secret-password',
    ]), 'staff');
    Http::fake();

    $this->actingAs($cajero)->get(route('panel.account.portal'))->assertForbidden();

    Http::assertNothingSent();
});

it('sin sesión iniciada no se abre el portal', function (): void {
    Http::fake();

    $this->get(route('panel.account.portal'))->assertRedirect('/login');

    Http::assertNothingSent();
});

it('sin cliente en Polar explica que no se paga con tarjeta desde aquí, sin llamar a Polar', function (): void {
    $this->subscription->update(['polar_customer_id' => null]);
    Http::fake();

    $this->actingAs($this->owner)->get(route('panel.account.portal'))->assertRedirect(route('panel.account'));

    expect(session('panel_error'))->toContain('no se paga con tarjeta desde aquí');
    Http::assertNothingSent();
});

it('si Polar no responde bien, el cliente ve un aviso y queda rastro para el operador', function (): void {
    Http::fake(['*/v1/customer-sessions/' => Http::response(['error' => 'Boom'], 500)]);

    $this->actingAs($this->owner)->get(route('panel.account.portal'))->assertRedirect(route('panel.account'));

    expect(session('panel_error'))->toContain('No pudimos abrir el portal de pagos');

    $rastro = SystemEvent::query()->withoutGlobalScopes()->where('type', 'integration.failed')->first();

    expect($rastro)->not->toBeNull()
        ->and($rastro->level)->toBe(SystemEvent::AVISO);
});

it('el portal se pide siempre para la empresa activa: no hay identificador que manipular', function (): void {
    // Otra empresa, sin suscripción propia: no puede acabar en el portal de la primera.
    $otra = app(CompanyService::class)->create(new CreateCompanyData(name: 'Ajena'));
    $ajeno = withRole(User::create([
        'company_id' => $otra->id, 'name' => 'Ajeno',
        'email' => 'ajeno@ajena.example.com', 'password' => 'secret-password',
    ]), 'owner');
    Http::fake();

    $this->actingAs($ajeno)->get(route('panel.account.portal'))->assertRedirect(route('panel.account'));

    Http::assertNothingSent();
});

it('la pantalla de cuenta ofrece cambiar la tarjeta desde el portal, en vez de pedir que escriban', function (): void {
    $this->actingAs($this->owner)->get(route('panel.account'))
        ->assertOk()
        ->assertSee(route('panel.account.portal'), false)
        ->assertSee('entra al portal de pagos')
        ->assertDontSee('Para cambiar la tarjeta, escríbenos');
});
