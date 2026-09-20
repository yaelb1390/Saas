<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Mail\SubscriptionCancelledMail;
use App\Modules\Core\Mail\SubscriptionConfirmedMail;
use App\Modules\Core\Mail\SubscriptionResumedMail;
use App\Modules\Core\Models\SystemEvent;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Tenancy\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

/*
 * La herramienta de correos de prueba del operador.
 *
 * Comprobar que un correo LLEGA no se puede hacer desde el código: Brevo lo da por «Entregado» y aun así
 * Hotmail puede descartarlo. La herramienta manda los MISMOS correos que el sistema real a un buzón de
 * verdad. Lo que se vigila aquí: que solo la use el operador, que mande lo que dice y a quien dice, y
 * que un fallo se ENSEÑE en vez de tragarse, porque para eso sirve.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutVite();
    app(CurrentCompany::class)->forget();

    config(['platform.support_email' => 'soporte@bm.test', 'platform.support_whatsapp' => '18095551234']);

    $this->company = app(CompanyService::class)->create(new CreateCompanyData(name: 'Heladería'));
    app(CurrentCompany::class)->set($this->company->id);

    $this->super = User::create([
        'company_id' => $this->company->id, 'name' => 'Operadora Ana',
        'email' => 'super@correos.test', 'password' => 'secret-password',
        'is_super_admin' => true,
    ]);

    $this->duena = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Dueña',
        'email' => 'duena@correos.test', 'password' => 'secret-password',
    ]), 'owner');

    Mail::fake();
});

// ------------------------------------------------------------------ Quién puede usarla

it('solo el operador de la plataforma ve la herramienta y puede enviar', function (): void {
    $this->actingAs($this->duena)->get(route('platform.mail-test'))->assertForbidden();
    $this->actingAs($this->duena)
        ->post(route('platform.mail-test.send'), ['plantilla' => 'baja', 'destino' => 'x@ejemplo.test'])
        ->assertForbidden();

    $this->actingAs($this->super)->get(route('platform.mail-test'))->assertOk();

    Mail::assertNothingSent();
    Mail::assertNothingQueued();
});

it('sin sesión no se puede enviar', function (): void {
    $this->post(route('platform.mail-test.send'), ['plantilla' => 'baja', 'destino' => 'x@ejemplo.test'])
        ->assertRedirect('/login');

    Mail::assertNothingSent();
});

it('el enlace del menú lo ve el operador y no el dueño de una empresa', function (): void {
    $this->actingAs($this->super)->get(route('platform.mail-test'))->assertSee('Correos de prueba');

    $this->actingAs($this->duena)->get(route('panel.account'))
        ->assertOk()
        ->assertDontSee('Correos de prueba');
});

// -------------------------------------------------------------------- La pantalla

it('la pantalla dice con qué remitente y con qué envío se manda', function (): void {
    config(['mail.from.address' => 'no-responder@bm.test', 'mail.from.name' => 'BM Business OS']);

    $this->actingAs($this->super)->get(route('platform.mail-test'))
        ->assertOk()
        ->assertSee('no-responder@bm.test')
        ->assertSee('soporte@bm.test')
        ->assertSee('Baja de suscripción')
        ->assertSee('Reactivación')
        ->assertSee('Recibo de pago');
});

it('avisa cuando el envío no sale de la aplicación, porque ninguna prueba llegaría jamás', function (): void {
    config(['mail.default' => 'log']);

    $this->actingAs($this->super)->get(route('platform.mail-test'))
        ->assertOk()
        ->assertSee('Aquí los correos no salen de la aplicación');
});

it('con SMTP enseña el servidor y no avisa de nada', function (): void {
    config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.ejemplo.test', 'mail.mailers.smtp.port' => 587]);

    $this->actingAs($this->super)->get(route('platform.mail-test'))
        ->assertOk()
        ->assertSee('smtp.ejemplo.test:587')
        ->assertDontSee('Aquí los correos no salen de la aplicación');
});

// ---------------------------------------------------------------------- Lo que manda

it('manda la baja a la dirección indicada, con el nombre del operador', function (): void {
    $this->actingAs($this->super)
        ->post(route('platform.mail-test.send'), ['plantilla' => 'baja', 'destino' => 'prueba@ejemplo.test'])
        ->assertRedirect();

    Mail::assertSent(SubscriptionCancelledMail::class, 1);
    Mail::assertSent(SubscriptionCancelledMail::class, fn (SubscriptionCancelledMail $correo): bool => $correo->hasTo('prueba@ejemplo.test')
        && $correo->ownerName === 'Operadora Ana'
        && $correo->hasReplyTo('soporte@bm.test'));

    expect(session('panel_ok'))->toContain('prueba@ejemplo.test');
});

it('manda cada uno de los correos que reciben los clientes', function (string $plantilla): void {
    $this->actingAs($this->super)
        ->post(route('platform.mail-test.send'), ['plantilla' => $plantilla, 'destino' => 'prueba@ejemplo.test'])
        ->assertRedirect();

    $clase = match ($plantilla) {
        'baja' => SubscriptionCancelledMail::class,
        'reactivacion' => SubscriptionResumedMail::class,
        'recibo' => SubscriptionConfirmedMail::class,
    };

    // «Enviado» y no «encolado», también en el recibo: aunque ese correo sea `ShouldQueue`, la herramienta
    // lo manda con `sendNow`. En producción no hay worker de colas, y uno encolado no lo recogería nadie.
    Mail::assertSent($clase, fn ($correo): bool => $correo->hasTo('prueba@ejemplo.test'));
    Mail::assertNothingQueued();
})->with(['baja', 'reactivacion', 'recibo']);

it('sin la cabecera «Responder a» la quita de la baja y de la reactivación', function (string $plantilla, string $clase): void {
    $this->actingAs($this->super)->post(route('platform.mail-test.send'), [
        'plantilla' => $plantilla, 'destino' => 'prueba@ejemplo.test', 'sin_reply_to' => '1',
    ])->assertRedirect();

    Mail::assertSent($clase, fn ($correo): bool => ! $correo->hasReplyTo('soporte@bm.test'));
})->with([
    'baja' => ['baja', SubscriptionCancelledMail::class],
    'reactivación' => ['reactivacion', SubscriptionResumedMail::class],
]);

it('por defecto conserva la cabecera «Responder a», que es como salen los correos reales', function (): void {
    $this->actingAs($this->super)
        ->post(route('platform.mail-test.send'), ['plantilla' => 'reactivacion', 'destino' => 'prueba@ejemplo.test'])
        ->assertRedirect();

    Mail::assertSent(SubscriptionResumedMail::class, fn (SubscriptionResumedMail $correo): bool => $correo->hasReplyTo('soporte@bm.test'));
});

// ------------------------------------------------------------------- Validación

it('no manda nada con una dirección que no parece un correo', function (): void {
    $this->actingAs($this->super)
        ->post(route('platform.mail-test.send'), ['plantilla' => 'baja', 'destino' => 'esto-no-es-un-correo'])
        ->assertSessionHasErrors('destino');

    Mail::assertNothingSent();
});

it('no manda nada sin dirección ni con un correo que no existe en la lista', function (): void {
    $this->actingAs($this->super)->post(route('platform.mail-test.send'), ['plantilla' => 'baja'])
        ->assertSessionHasErrors('destino');

    $this->actingAs($this->super)
        ->post(route('platform.mail-test.send'), ['plantilla' => 'inventada', 'destino' => 'x@ejemplo.test'])
        ->assertSessionHasErrors('plantilla');

    Mail::assertNothingSent();
    Mail::assertNothingQueued();
});

// ----------------------------------------------------------------- Si el envío falla

it('un fallo del envío se enseña en pantalla y no da un error 500', function (): void {
    // Es el motivo de que exista: un «no se pudo» a secas no diría si es la clave, el remitente sin
    // verificar o el servidor caído.
    Mail::shouldReceive('to')->andThrow(new RuntimeException('Connection refused por el servidor'));

    $this->actingAs($this->super)
        ->post(route('platform.mail-test.send'), ['plantilla' => 'baja', 'destino' => 'prueba@ejemplo.test'])
        ->assertRedirect();

    expect(session('panel_error'))->toContain('No se pudo enviar')
        ->and(session('panel_error'))->toContain('RuntimeException')
        ->and(session('panel_error'))->toContain('Connection refused por el servidor')
        ->and(session('panel_ok'))->toBeNull();
});

it('no escribe en pantalla el usuario con el que se intentó entrar al servidor de correo', function (): void {
    Mail::shouldReceive('to')->andThrow(new RuntimeException(
        'Failed to authenticate on SMTP server with username "abc123@smtp-brevo.com" using the following authenticators: LOGIN.'
    ));

    $this->actingAs($this->super)
        ->post(route('platform.mail-test.send'), ['plantilla' => 'baja', 'destino' => 'prueba@ejemplo.test']);

    expect(session('panel_error'))->not->toContain('abc123@smtp-brevo.com')
        ->and(session('panel_error'))->toContain('username "***"')
        ->and(session('panel_error'))->toContain('Failed to authenticate');
});

it('un fallo no deja constancia de un envío que no ocurrió', function (): void {
    Mail::shouldReceive('to')->andThrow(new RuntimeException('smtp caído'));

    $this->actingAs($this->super)
        ->post(route('platform.mail-test.send'), ['plantilla' => 'baja', 'destino' => 'prueba@ejemplo.test']);

    expect(SystemEvent::query()->where('type', 'mail.test_sent')->count())->toBe(0);
});

// --------------------------------------------------------------------- El rastro

it('deja constancia de quién probó y con qué buzón, sin guardar la dirección completa', function (): void {
    $this->actingAs($this->super)->post(route('platform.mail-test.send'), [
        'plantilla' => 'baja', 'destino' => 'alguien@ejemplo.test', 'sin_reply_to' => '1',
    ]);

    $evento = SystemEvent::query()->where('type', 'mail.test_sent')->first();

    expect($evento)->not->toBeNull()
        ->and($evento->user_id)->toBe($this->super->id)
        ->and($evento->context['plantilla'])->toBe('baja')
        ->and($evento->context['dominio_destino'])->toBe('ejemplo.test')
        ->and($evento->context['con_reply_to'])->toBeFalse()
        ->and(json_encode($evento->context))->not->toContain('alguien@');
});
