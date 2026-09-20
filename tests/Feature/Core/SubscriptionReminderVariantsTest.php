<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Mail\SubscriptionCardExpiringMail;
use App\Modules\Core\Mail\SubscriptionEndingSoonMail;
use App\Modules\Core\Mail\SubscriptionExpiringMail;
use App\Modules\Core\Mail\SubscriptionRenewalNoticeMail;
use App\Modules\Core\Models\Plan;
use App\Modules\Core\Models\SystemEvent;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Services\PolarSubscriptionService;
use App\Modules\Core\Services\SubscriptionService;
use App\Modules\Core\Support\CardExpiry;
use App\Modules\Core\Tenancy\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

/*
 * Las dos variantes del recordatorio diario que faltaban:
 *
 *  - «Tu tarjeta vence»: a quien Polar cobra solo se le mira la tarjeta en el momento del aviso de renovación,
 *    y si no va a servir el día del cobro (o caduca en el mes siguiente) el correo de tarjeta ocupa el lugar
 *    del aviso normal. Reemplaza el aviso de Polar, que llega en inglés.
 *  - «Tu acceso termina»: a quien YA pidió la baja se le dice que puede reactivarla, en vez de «renueva».
 *
 * Lo que más se vigila no es el texto sino que Polar se consulte lo justo: una petición por suscripción y período,
 * nunca fuera del momento del aviso, y que un fallo de Polar jamás impida el aviso normal.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutVite();
    app(CurrentCompany::class)->forget();

    // Fecha fija: los casos de tarjeta dependen de en qué mes se cobra. El cobro será el 1 de octubre a las 10:00.
    $this->travelTo(Carbon::parse('2026-09-28 10:00:00'));

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

    // Una empresa que ya pagó, con Polar cobrándole sola, y cuya renovación cae dentro del umbral (5 días).
    $this->subscription = app(SubscriptionService::class)->subscribe($this->company, $this->plan, withTrial: false);
    $this->subscription->update([
        'polar_subscription_id' => 'sub_de_prueba',
        'polar_customer_id' => 'cus_de_prueba',
        'current_period_end' => now()->addDays(3),
    ]);

    // Ninguna prueba puede salir a la red de verdad: lo que Polar contesta se fija en cada caso.
    Http::preventStrayRequests();

    // Un método de pago tal como lo devuelve Polar (`GET /v1/customers/{id}/payment-methods`).
    $this->tarjeta = fn (int $mes, int $anio, array $extra = []): array => array_merge([
        'id' => 'pm_de_prueba', 'created_at' => '2026-01-01T00:00:00Z', 'type' => 'card', 'is_default' => true,
        'method_metadata' => ['brand' => 'visa', 'last4' => '4242', 'exp_month' => $mes, 'exp_year' => $anio],
    ], $extra);

    // Lo que Polar contesta con esas tarjetas.
    $this->polarDevuelve = fn (array $tarjetas) => Http::fake([
        '*/v1/customers/*/payment-methods*' => Http::response([
            'items' => $tarjetas, 'pagination' => ['total_count' => count($tarjetas), 'max_page' => 1],
        ]),
    ]);

    Mail::fake();
});

// ================================================================ La decisión: ¿va a fallar la tarjeta?

it('una tarjeta vale hasta el último día del mes que dice, no hasta el día 1', function (string $cobro, bool $falla): void {
    $tarjeta = new CardExpiry('visa', '4242', 10, 2026);

    expect($tarjeta->failsAt(Carbon::parse($cobro)))->toBe($falla);
})->with([
    'el primer día del mes de vencimiento' => ['2026-10-01 10:00:00', false],
    'el último día del mes, a última hora' => ['2026-10-31 23:00:00', false],
    'el día siguiente al mes de vencimiento' => ['2026-11-01 00:00:00', true],
    'meses después' => ['2027-03-15 10:00:00', true],
]);

it('avisa si la tarjeta falla el día del cobro o caduca en el mes siguiente', function (int $mes, int $anio, bool $avisa): void {
    // El cobro es el 1 de octubre a las 10:00; el siguiente sería el 1 de noviembre.
    $cobro = Carbon::parse('2026-10-01 10:00:00');

    expect((new CardExpiry('visa', '4242', $mes, $anio))->atRisk($cobro))->toBe($avisa);
})->with([
    'ya vencida cuando toca cobrar' => [9, 2026, true],
    'vale para este cobro pero no para el siguiente' => [10, 2026, true],
    'vale para los dos cobros' => [11, 2026, false],
    'vence mucho después' => [4, 2031, false],
]);

it('el mes siguiente es de calendario, no «30 días»: octubre tiene 31', function (): void {
    // Con 30 días esta tarjeta (vale hasta el 31/10) no saltaría con el cobro del 1 de octubre, y el del
    // 1 de noviembre fallaría sin aviso.
    expect((new CardExpiry('visa', '4242', 10, 2026))->atRisk(Carbon::parse('2026-10-01 10:00:00')))->toBeTrue();
});

it('un cobro a fin de mes no se pasa al mes siguiente por error', function (): void {
    // Cobro el 31 de enero: el siguiente es el 28 de febrero, no el 3 de marzo. Una tarjeta que vale hasta el
    // 28/02 sirve para los dos cobros.
    expect((new CardExpiry('visa', '4242', 2, 2026))->atRisk(Carbon::parse('2026-01-31 10:00:00')))->toBeFalse();
});

it('la fecha se escribe como en la tarjeta y la marca se lee bien', function (): void {
    expect((new CardExpiry('visa', '4242', 9, 2026))->label())->toBe('09/2026')
        ->and((new CardExpiry('visa', '4242', 12, 2026))->label())->toBe('12/2026')
        ->and((new CardExpiry('visa', '4242', 9, 2026))->brandLabel())->toBe('Visa')
        ->and((new CardExpiry('amex', '4242', 9, 2026))->brandLabel())->toBe('American Express')
        ->and((new CardExpiry('mastercard', '4242', 9, 2026))->brandLabel())->toBe('Mastercard')
        ->and((new CardExpiry('cartes_bancaires', '4242', 9, 2026))->brandLabel())->toBe('Cartes Bancaires')
        ->and((new CardExpiry('', '', 9, 2026))->brandLabel())->toBe('');
});

// ------------------------------------------------- Qué tarjeta cuenta, según lo que devuelve Polar

it('con varias tarjetas cuenta la predeterminada, aunque sea la más vieja', function (): void {
    $vieja = ($this->tarjeta)(9, 2026, ['id' => 'pm_vieja', 'created_at' => '2025-01-01T00:00:00Z', 'is_default' => true]);
    $nueva = ($this->tarjeta)(4, 2031, ['id' => 'pm_nueva', 'created_at' => '2026-06-01T00:00:00Z', 'is_default' => false]);

    expect(CardExpiry::fromPaymentMethods([$nueva, $vieja])?->label())->toBe('09/2026');
});

it('sin predeterminada cuenta la añadida más recientemente', function (): void {
    $vieja = ($this->tarjeta)(9, 2026, ['created_at' => '2025-01-01T00:00:00Z', 'is_default' => false]);
    $nueva = ($this->tarjeta)(4, 2031, ['created_at' => '2026-06-01T00:00:00Z', 'is_default' => false]);

    expect(CardExpiry::fromPaymentMethods([$vieja, $nueva])?->label())->toBe('04/2031');
});

it('ignora lo que no es una tarjeta o no trae una fecha creíble, en vez de avisar a ciegas', function (array $metodos): void {
    expect(CardExpiry::fromPaymentMethods($metodos))->toBeNull();
})->with([
    'sin métodos' => [[]],
    'una tarjeta coreana, sin fecha' => [[['type' => 'kr_card', 'method_metadata' => ['brand' => 'x', 'last4' => '1']]]],
    'un método genérico' => [[['type' => 'generic']]],
    'sin metadatos' => [[['type' => 'card']]],
    'mes imposible' => [[['type' => 'card', 'method_metadata' => ['exp_month' => 13, 'exp_year' => 2030]]]],
    'año imposible' => [[['type' => 'card', 'method_metadata' => ['exp_month' => 5, 'exp_year' => 30]]]],
    'fecha como texto' => [[['type' => 'card', 'method_metadata' => ['exp_month' => '05', 'exp_year' => '2030']]]],
]);

// ================================================================ Preguntarle a Polar por la tarjeta

it('pide la tarjeta al cliente de la suscripción y la devuelve', function (): void {
    ($this->polarDevuelve)([($this->tarjeta)(9, 2026)]);

    $tarjeta = app(PolarSubscriptionService::class)->cardOnFile($this->subscription);

    expect($tarjeta?->label())->toBe('09/2026')
        ->and($tarjeta?->last4)->toBe('4242');

    Http::assertSentCount(1);
    Http::assertSent(fn ($peticion): bool => $peticion->method() === 'GET'
        && str_contains($peticion->url(), '/v1/customers/cus_de_prueba/payment-methods'));
});

it('sin cliente en Polar o sin pasarela no llama a Polar', function (): void {
    Http::fake();

    $this->subscription->update(['polar_customer_id' => null]);
    expect(app(PolarSubscriptionService::class)->cardOnFile($this->subscription->fresh()))->toBeNull();

    $this->subscription->update(['polar_customer_id' => 'cus_de_prueba']);
    config(['polar.access_token' => null]);
    expect(app(PolarSubscriptionService::class)->cardOnFile($this->subscription->fresh()))->toBeNull();

    Http::assertNothingSent();
});

it('un cliente sin tarjeta guardada no es un fallo: no queda rastro', function (): void {
    ($this->polarDevuelve)([]);

    expect(app(PolarSubscriptionService::class)->cardOnFile($this->subscription))->toBeNull()
        ->and(SystemEvent::query()->withoutGlobalScopes()->where('type', 'integration.failed')->count())->toBe(0);
});

it('si Polar responde con un error, devuelve null y deja rastro para el operador', function (): void {
    Http::fake(['*/v1/customers/*/payment-methods*' => Http::response(['error' => 'Boom'], 500)]);

    expect(app(PolarSubscriptionService::class)->cardOnFile($this->subscription))->toBeNull();

    $rastro = SystemEvent::query()->withoutGlobalScopes()->where('type', 'integration.failed')->first();

    expect($rastro)->not->toBeNull()
        ->and($rastro->level)->toBe(SystemEvent::AVISO)
        ->and($rastro->message)->toContain('tarjeta');
});

it('si Polar no contesta a tiempo, devuelve null y deja rastro, sin lanzar', function (): void {
    Http::fake(['*/v1/customers/*/payment-methods*' => fn () => throw new ConnectionException('cURL error 28: timeout')]);

    expect(app(PolarSubscriptionService::class)->cardOnFile($this->subscription))->toBeNull()
        ->and(SystemEvent::query()->withoutGlobalScopes()->where('type', 'integration.failed')->count())->toBe(1);
});

// ================================================== El recordatorio: la tarjeta sustituye al aviso normal

it('una tarjeta que no servirá el día del cobro sustituye al aviso de renovación', function (): void {
    ($this->polarDevuelve)([($this->tarjeta)(9, 2026)]);

    $this->artisan('subscriptions:remind-expiring')->assertSuccessful();

    Mail::assertSent(SubscriptionCardExpiringMail::class, 1);
    Mail::assertSent(SubscriptionCardExpiringMail::class, fn (SubscriptionCardExpiringMail $correo): bool => $correo->hasTo('duena@heladeria.example.com')
        && $correo->failsAtRenewal === true
        && $correo->cardName === 'Visa •••• 4242'
        && $correo->cardExpiry === '09/2026'
        && $correo->planName === 'Pro'
        && $correo->renewsAt->isSameDay(Carbon::parse('2026-10-01'))
        && $correo->updateCardUrl === route('panel.account.portal')
        && $correo->accountUrl === route('panel.account'));

    // Un solo correo: el de la tarjeta ocupa el sitio del de renovación, no se suma a él.
    Mail::assertNotSent(SubscriptionRenewalNoticeMail::class);
    expect($this->subscription->fresh()->renewal_reminded_at)->not->toBeNull();
});

it('una tarjeta que aún sirve pero caduca en el mes siguiente avisa con tono de previsión', function (): void {
    ($this->polarDevuelve)([($this->tarjeta)(10, 2026)]);

    $this->artisan('subscriptions:remind-expiring')->assertSuccessful();

    Mail::assertSent(SubscriptionCardExpiringMail::class, 1);
    Mail::assertSent(SubscriptionCardExpiringMail::class, fn (SubscriptionCardExpiringMail $correo): bool => $correo->failsAtRenewal === false
        && $correo->cardExpiry === '10/2026');
    Mail::assertNotSent(SubscriptionRenewalNoticeMail::class);
});

it('con la tarjeta en regla sale el aviso de renovación de siempre', function (): void {
    ($this->polarDevuelve)([($this->tarjeta)(4, 2031)]);

    $this->artisan('subscriptions:remind-expiring')->assertSuccessful();

    Mail::assertSent(SubscriptionRenewalNoticeMail::class, 1);
    Mail::assertNotSent(SubscriptionCardExpiringMail::class);
});

it('sin tarjeta guardada sale el aviso de renovación de siempre', function (): void {
    ($this->polarDevuelve)([]);

    $this->artisan('subscriptions:remind-expiring')->assertSuccessful();

    Mail::assertSent(SubscriptionRenewalNoticeMail::class, 1);
    Mail::assertNotSent(SubscriptionCardExpiringMail::class);
});

it('si Polar falla, el aviso de renovación sale igual, se marca y queda rastro', function (): void {
    // El de la tarjeta es un extra: que Polar no conteste no puede dejar al cliente sin su aviso de siempre.
    Http::fake(['*/v1/customers/*/payment-methods*' => Http::response(['error' => 'Boom'], 500)]);

    $this->artisan('subscriptions:remind-expiring')->assertSuccessful();

    Mail::assertSent(SubscriptionRenewalNoticeMail::class, 1);
    expect($this->subscription->fresh()->renewal_reminded_at)->not->toBeNull()
        ->and(SystemEvent::query()->withoutGlobalScopes()->where('type', 'integration.failed')->count())->toBe(1);
});

it('el aviso de la tarjeta sale una sola vez por período, y Polar se consulta una sola vez', function (): void {
    ($this->polarDevuelve)([($this->tarjeta)(9, 2026)]);

    $this->artisan('subscriptions:remind-expiring');
    $this->artisan('subscriptions:remind-expiring');

    Mail::assertSent(SubscriptionCardExpiringMail::class, 1);
    Http::assertSentCount(1);
});

it('si el correo de la tarjeta no sale, no se marca y se reintenta en la próxima corrida', function (): void {
    ($this->polarDevuelve)([($this->tarjeta)(9, 2026)]);
    Mail::shouldReceive('to')->andThrow(new RuntimeException('SMTP caído'));

    $this->artisan('subscriptions:remind-expiring')->assertSuccessful();

    expect($this->subscription->fresh()->renewal_reminded_at)->toBeNull();
});

// ------------------------------------------------ Polar se consulta lo justo

it('no consulta a Polar mientras la renovación queda lejos', function (): void {
    // Mensual: el umbral es de 5 días. Sin esto, cada corrida diaria sería una petición por cliente.
    $this->subscription->update(['current_period_end' => now()->addDays(20)]);
    Http::fake();

    $this->artisan('subscriptions:remind-expiring')->assertSuccessful();

    Http::assertNothingSent();
    Mail::assertNothingSent();
});

it('no consulta a Polar para quien ya pidió la baja ni para la suscripción asignada a mano', function (): void {
    // Ninguna de las dos se va a renovar sola: no hay cobro que pueda fallar por la tarjeta.
    Http::fake();

    $this->subscription->update(['cancelled_at' => now()]);
    $this->artisan('subscriptions:remind-expiring')->assertSuccessful();

    $this->subscription->update(['cancelled_at' => null, 'renewal_reminded_at' => null, 'polar_subscription_id' => null]);
    $this->artisan('subscriptions:remind-expiring')->assertSuccessful();

    Http::assertNothingSent();
});

it('el simulacro no consulta a Polar ni marca nada', function (): void {
    Http::fake();

    $this->artisan('subscriptions:remind-expiring', ['--simular' => true])
        ->expectsOutputToContain('se renueva sola')
        ->assertSuccessful();

    Http::assertNothingSent();
    Mail::assertNothingSent();
    expect($this->subscription->fresh()->renewal_reminded_at)->toBeNull();
});

// ================================================================ El correo de tarjeta por vencer

it('el correo dice qué tarjeta, cuándo vence y qué se cobra, cuando el cobro va a fallar', function (): void {
    $correo = new SubscriptionCardExpiringMail(
        ownerName: 'Yael Berroa Pérez', companyName: 'Heladería', planName: 'Pro', planPrice: '1500',
        billingCycleLabel: 'Mensual', cardBrand: 'Visa', cardLast4: '4242', cardExpiry: '09/2026',
        renewsAt: Carbon::parse('2026-10-01'), failsAtRenewal: true,
        accountUrl: 'https://bmos.test/panel/cuenta', updateCardUrl: 'https://bmos.test/panel/cuenta/tarjeta',
        supportWhatsapp: '18095551234', supportEmail: 'soporte@bm.test',
    );

    $correo->assertHasSubject('Tu tarjeta vence antes de tu próxima renovación · Actualízala · BM Business OS');
    $correo->assertHasReplyTo('soporte@bm.test');

    $correo->assertSeeInHtml('Hola, Yael')
        ->assertDontSeeInHtml('Berroa')
        ->assertSeeInHtml('antes de la próxima renovación')
        ->assertSeeInHtml('el cobro fallará')
        ->assertSeeInHtml('Visa •••• 4242')
        ->assertSeeInHtml('vence 09/2026')
        ->assertSeeInHtml('Próxima renovación: 01/10/2026')
        ->assertSeeInHtml('RD$ 1,500.00')
        ->assertSeeInHtml('Actualizar mi tarjeta')
        ->assertSeeInHtml('https://bmos.test/panel/cuenta/tarjeta', false)
        ->assertSeeInHtml('https://bmos.test/panel/cuenta', false);

    $correo->assertSeeInText('antes de la próxima renovación')
        ->assertSeeInText('Visa •••• 4242 · vence 09/2026')
        ->assertSeeInText('Próxima renovación: 01/10/2026')
        ->assertSeeInText('https://bmos.test/panel/cuenta/tarjeta');
});

it('cuando la tarjeta aún sirve, el correo lo dice y no alarma', function (): void {
    $correo = new SubscriptionCardExpiringMail(
        ownerName: 'Ana', companyName: 'Heladería', planName: 'Pro', planPrice: '1500',
        billingCycleLabel: 'Mensual', cardBrand: 'Visa', cardLast4: '4242', cardExpiry: '10/2026',
        renewsAt: Carbon::parse('2026-10-01'), failsAtRenewal: false,
        accountUrl: 'https://bmos.test/panel/cuenta', updateCardUrl: 'https://bmos.test/panel/cuenta/tarjeta',
        supportWhatsapp: '18095551234', supportEmail: 'soporte@bm.test',
    );

    $correo->assertHasSubject('Tu tarjeta vence pronto · Actualízala · BM Business OS');

    $correo->assertSeeInHtml('Todavía sirve para la próxima renovación')
        ->assertDontSeeInHtml('el cobro fallará')
        ->assertDontSeeInHtml('perderás el acceso');

    $correo->assertSeeInText('Todavía sirve para la próxima renovación')
        ->assertDontSeeInText('perderás el acceso');
});

it('si Polar no dijo cuál es la tarjeta, el correo no inventa marca ni número', function (): void {
    $correo = new SubscriptionCardExpiringMail(
        ownerName: 'Ana', companyName: 'Heladería', planName: 'Pro', planPrice: '1500',
        billingCycleLabel: 'Mensual', cardBrand: '', cardLast4: '', cardExpiry: '09/2026',
        renewsAt: Carbon::parse('2026-10-01'), failsAtRenewal: true,
        accountUrl: 'https://bmos.test/panel/cuenta', updateCardUrl: 'https://bmos.test/panel/cuenta/tarjeta',
        supportWhatsapp: '18095551234', supportEmail: 'soporte@bm.test',
    );

    expect($correo->cardName)->toBe('Tu tarjeta');
    $correo->assertSeeInHtml('Tu tarjeta')->assertDontSeeInHtml('••••');
});

// ================================================================ Quien ya pidió la baja

it('a quien pidió la baja se le recuerda que su acceso termina y que puede reactivarla', function (): void {
    $this->subscription->update(['cancelled_at' => now()]);
    Http::fake();

    $this->artisan('subscriptions:remind-expiring')->assertSuccessful();

    Mail::assertSent(SubscriptionEndingSoonMail::class, 1);
    Mail::assertSent(SubscriptionEndingSoonMail::class, fn (SubscriptionEndingSoonMail $correo): bool => $correo->hasTo('duena@heladeria.example.com')
        && $correo->planName === 'Pro'
        && $correo->daysLeft === 3
        && $correo->accessUntil->isSameDay(Carbon::parse('2026-10-01'))
        && $correo->accountUrl === route('panel.account'));

    // Ninguno de los otros: no tiene nada que renovar, ni a quién escribir para hacerlo.
    Mail::assertNotQueued(SubscriptionExpiringMail::class);
    Mail::assertNotSent(SubscriptionRenewalNoticeMail::class);
    Mail::assertNotSent(SubscriptionCardExpiringMail::class);
});

it('el aviso de fin de acceso sale una sola vez por período y no antes de tiempo', function (): void {
    $this->subscription->update(['cancelled_at' => now()]);
    Http::fake();

    $this->artisan('subscriptions:remind-expiring');
    $this->artisan('subscriptions:remind-expiring');

    Mail::assertSent(SubscriptionEndingSoonMail::class, 1);

    // Con el final aún lejos no hay nada que recordar.
    $this->subscription->update(['current_period_end' => now()->addDays(20), 'renewal_reminded_at' => null]);
    $this->artisan('subscriptions:remind-expiring');

    Mail::assertSent(SubscriptionEndingSoonMail::class, 1);
});

it('el aviso de fin de acceso dice cuándo termina y cómo reactivarla, en español', function (): void {
    $correo = new SubscriptionEndingSoonMail(
        ownerName: 'Yael Berroa Pérez', companyName: 'Heladería', planName: 'Pro',
        accessUntil: Carbon::parse('2026-10-20'), daysLeft: 3,
        accountUrl: 'https://bmos.test/panel/cuenta', supportWhatsapp: '18095551234', supportEmail: 'soporte@bm.test',
    );

    $correo->assertHasSubject('Tu acceso termina el 20/10/2026 · Aún puedes reactivarla · BM Business OS');
    $correo->assertHasReplyTo('soporte@bm.test');

    $correo->assertSeeInHtml('Hola, Yael')
        ->assertDontSeeInHtml('Berroa')
        ->assertSeeInHtml('porque pediste la baja')
        ->assertSeeInHtml('20/10/2026')
        ->assertSeeInHtml('en 3 días')
        ->assertSeeInHtml('plan Pro')
        ->assertSeeInHtml('Reactivar mi suscripción')
        ->assertSeeInHtml('https://bmos.test/panel/cuenta', false)
        ->assertSeeInHtml('Tus datos están a salvo')
        // Lo que decía el aviso viejo, y que para quien canceló no tiene sentido.
        ->assertDontSeeInHtml('Renueva')
        ->assertDontSeeInHtml('para renovar');

    $correo->assertSeeInText('porque pediste la baja')
        ->assertSeeInText('20/10/2026 · en 3 días')
        ->assertSeeInText('https://bmos.test/panel/cuenta')
        ->assertDontSeeInText('Renueva');
});

it('el aviso de fin de acceso habla de mañana y de hoy, sin contar días', function (int $dias, string $dice, string $noDice): void {
    $correo = new SubscriptionEndingSoonMail(
        ownerName: 'Ana', companyName: 'Heladería', planName: 'Pro', accessUntil: Carbon::parse('2026-10-20'),
        daysLeft: $dias, accountUrl: 'https://bmos.test/panel/cuenta',
        supportWhatsapp: '18095551234', supportEmail: 'soporte@bm.test',
    );

    $correo->assertSeeInHtml($dice)->assertDontSeeInHtml($noDice);
})->with([
    'mañana' => [1, 'mañana', 'en 1 días'],
    'hoy' => [0, 'hoy', 'en 0 días'],
]);
