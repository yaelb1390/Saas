<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Mail\PasswordResetMail;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Tenancy\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;

/*
 * Recuperación de contraseña por correo.
 *
 * Estas rutas llevaban tiempo publicadas y ROTAS: Fortify es headless y nadie había registrado sus
 * dos vistas, así que `/forgot-password` devolvía un 500. Pasó desapercibido porque no existía un
 * solo test del flujo; el primero de aquí es exactamente el que lo habría cazado.
 *
 * Lo demás cubre las dos cosas que pueden salir caras: filtrar qué correos tienen cuenta, y dejar
 * que una cuenta desactivada se ponga contraseña nueva.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(CurrentCompany::class)->forget();
    Mail::fake();

    $this->company = app(CompanyService::class)->create(new CreateCompanyData(name: 'Heladería'));

    $this->user = User::create([
        'company_id' => $this->company->id, 'name' => 'Dueña',
        'email' => 'duena@heladeria.test', 'password' => 'clave-vieja-123', 'is_active' => true,
    ]);

    app(CurrentCompany::class)->forget();
});

// ---------------------------------------------------------------- Pantallas

it('la pantalla de recuperación existe y no revienta', function (): void {
    // El test que faltaba: estas rutas devolvían 500 por no tener vista registrada.
    $this->get('/forgot-password')
        ->assertOk()
        ->assertSee('¿Olvidaste tu contraseña?');
});

it('el login enseña el enlace para recuperarla', function (): void {
    // Sin enlace, el flujo existe pero es inalcanzable.
    $this->get('/login')
        ->assertOk()
        ->assertSee('¿Olvidaste tu contraseña?');
});

it('la pantalla de nueva contraseña existe', function (): void {
    $this->get('/reset-password/un-token-cualquiera?email='.urlencode($this->user->email))
        ->assertOk()
        ->assertSee('Crea tu nueva contraseña')
        // Sin este campo la validación `confirmed` nunca pasaría.
        ->assertSee('password_confirmation', false);
});

// ---------------------------------------------------------------- Envío

it('envía el enlace a una cuenta activa', function (): void {
    $this->post('/forgot-password', ['email' => 'duena@heladeria.test'])
        ->assertSessionHasNoErrors();

    Mail::assertSent(PasswordResetMail::class, fn (PasswordResetMail $mail): bool => $mail->hasTo('duena@heladeria.test')
        && str_contains($mail->resetUrl, '/reset-password/'));
});

it('el correo NO se encola: el enlace tiene que salir en el momento', function (): void {
    // Encolarlo lo dejaría a merced de que exista un proceso que vacíe la cola —en producción no lo
    // hay— y quien lo pidió está mirando la pantalla, esperando.
    $this->post('/forgot-password', ['email' => 'duena@heladeria.test']);

    Mail::assertSent(PasswordResetMail::class);
    Mail::assertNotQueued(PasswordResetMail::class);
});

it('con un correo que no existe responde igual y no envía nada', function (): void {
    // Si la respuesta cambiara, cualquiera podría averiguar qué direcciones tienen cuenta probando
    // una por una.
    $conCuenta = $this->post('/forgot-password', ['email' => 'duena@heladeria.test']);
    $sinCuenta = $this->post('/forgot-password', ['email' => 'nadie@ninguna.test']);

    expect($sinCuenta->getStatusCode())->toBe($conCuenta->getStatusCode());

    Mail::assertSent(PasswordResetMail::class, 1); // solo el de la cuenta real
});

it('una cuenta desactivada no recibe el enlace, y no se nota', function (): void {
    // Hoy el login la rechaza pero el restablecimiento iba por otro camino: acababa con una clave
    // nueva que no le servía para entrar. La respuesta sigue siendo la misma para no revelar nada.
    $this->user->update(['is_active' => false]);

    $this->post('/forgot-password', ['email' => 'duena@heladeria.test'])
        ->assertSessionHasNoErrors();

    Mail::assertNothingSent();
});

// ---------------------------------------------------------------- Cambio

it('el flujo completo cambia la contraseña y permite entrar con la nueva', function (): void {
    $token = Password::broker()->createToken($this->user);

    $this->post('/reset-password', [
        'token' => $token,
        'email' => 'duena@heladeria.test',
        'password' => 'ClaveNueva123!',
        'password_confirmation' => 'ClaveNueva123!',
    ])->assertSessionHasNoErrors();

    expect(Hash::check('ClaveNueva123!', $this->user->fresh()->password))->toBeTrue();

    $this->post('/login', ['email' => 'duena@heladeria.test', 'password' => 'ClaveNueva123!']);
    $this->assertAuthenticated();
});

it('la contraseña vieja deja de servir', function (): void {
    $token = Password::broker()->createToken($this->user);

    $this->post('/reset-password', [
        'token' => $token, 'email' => 'duena@heladeria.test',
        'password' => 'ClaveNueva123!', 'password_confirmation' => 'ClaveNueva123!',
    ]);

    $this->post('/login', ['email' => 'duena@heladeria.test', 'password' => 'clave-vieja-123'])
        ->assertSessionHasErrors();

    $this->assertGuest();
});

it('un token inventado no cambia nada', function (): void {
    $this->post('/reset-password', [
        'token' => 'me-lo-invento',
        'email' => 'duena@heladeria.test',
        'password' => 'ClaveNueva123!',
        'password_confirmation' => 'ClaveNueva123!',
    ])->assertSessionHasErrors();

    expect(Hash::check('clave-vieja-123', $this->user->fresh()->password))->toBeTrue();
});

it('el token de una cuenta no sirve para otra', function (): void {
    $otro = User::create([
        'company_id' => $this->company->id, 'name' => 'Otro',
        'email' => 'otro@heladeria.test', 'password' => 'su-clave-123', 'is_active' => true,
    ]);

    $token = Password::broker()->createToken($this->user);

    $this->post('/reset-password', [
        'token' => $token,
        'email' => 'otro@heladeria.test', // token de la dueña, correo de otro
        'password' => 'ClaveNueva123!',
        'password_confirmation' => 'ClaveNueva123!',
    ])->assertSessionHasErrors();

    expect(Hash::check('su-clave-123', $otro->fresh()->password))->toBeTrue();
});

it('exige repetir la contraseña', function (): void {
    $token = Password::broker()->createToken($this->user);

    $this->post('/reset-password', [
        'token' => $token, 'email' => 'duena@heladeria.test',
        'password' => 'ClaveNueva123!', 'password_confirmation' => 'otra-distinta',
    ])->assertSessionHasErrors('password');

    expect(Hash::check('clave-vieja-123', $this->user->fresh()->password))->toBeTrue();
});
