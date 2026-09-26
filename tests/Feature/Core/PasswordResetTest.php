<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Mail\PasswordResetCodeMail;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Tenancy\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

/*
 * Recuperación de contraseña con un código de 6 dígitos por correo, en vez del enlace clicable que
 * traía Fortify. Lo que puede salir caro es lo mismo de siempre: filtrar qué correos tienen cuenta,
 * dejar que una cuenta desactivada se ponga contraseña nueva, y que el código se pueda adivinar a
 * fuerza bruta.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(CurrentCompany::class)->forget();
    Mail::fake();
    // El limitador de las rutas throttled vive en la caché `array`, que persiste entre pruebas
    // dentro del mismo proceso: sin esto, las peticiones de pruebas anteriores a /reset-password
    // cuentan para el límite de esta y la hacen fallar por un 429, no por la lógica de intentos.
    Cache::flush();

    $this->company = app(CompanyService::class)->create(new CreateCompanyData(name: 'Heladería'));

    $this->user = User::create([
        'company_id' => $this->company->id, 'name' => 'Dueña',
        'email' => 'duena@heladeria.test', 'password' => 'clave-vieja-123', 'is_active' => true,
    ]);

    app(CurrentCompany::class)->forget();
});

/** El código de prueba mandado por el último correo capturado por Mail::fake(). */
function ultimoCodigoEnviado(): string
{
    $codigo = null;

    Mail::assertSent(PasswordResetCodeMail::class, function (PasswordResetCodeMail $mail) use (&$codigo): bool {
        $codigo = $mail->code;

        return true;
    });

    return (string) $codigo;
}

// ---------------------------------------------------------------- Pantallas

it('la pantalla de recuperación existe y no revienta', function (): void {
    $this->get('/forgot-password')
        ->assertOk()
        ->assertSee('¿Olvidaste tu contraseña?');
});

it('el login enseña el enlace para recuperarla', function (): void {
    $this->get('/login')
        ->assertOk()
        ->assertSee('¿Olvidaste tu contraseña?');
});

it('la pantalla de nueva contraseña existe', function (): void {
    $this->get('/reset-password?email='.urlencode($this->user->email))
        ->assertOk()
        ->assertSee('Crea tu nueva contraseña')
        // Sin estos dos campos la validación nunca podría pasar.
        ->assertSee('name="code"', false)
        ->assertSee('password_confirmation', false);
});

// ---------------------------------------------------------------- Envío del código

it('envía el código a una cuenta activa, sin encolar', function (): void {
    $this->post('/forgot-password', ['email' => 'duena@heladeria.test'])
        ->assertSessionHasNoErrors();

    Mail::assertSent(PasswordResetCodeMail::class, fn (PasswordResetCodeMail $mail): bool => $mail->hasTo('duena@heladeria.test'));
    // El correo no se pidió en el momento de escribir esto, pero un enlace/código que llega diez
    // minutos tarde ya no sirve, y en producción no hay worker que vacíe una cola.
    Mail::assertNotQueued(PasswordResetCodeMail::class);
});

it('con un correo que no existe responde igual y no manda nada', function (): void {
    $conCuenta = $this->post('/forgot-password', ['email' => 'duena@heladeria.test']);
    $sinCuenta = $this->post('/forgot-password', ['email' => 'nadie@ninguna.test']);

    expect($sinCuenta->getStatusCode())->toBe($conCuenta->getStatusCode());

    Mail::assertSent(PasswordResetCodeMail::class, 1); // solo el de la cuenta real
});

it('una cuenta desactivada no recibe el código, y no se nota', function (): void {
    $this->user->update(['is_active' => false]);

    $this->post('/forgot-password', ['email' => 'duena@heladeria.test'])
        ->assertSessionHasNoErrors();

    Mail::assertNothingSent();
});

it('pedir el código dos veces seguidas no genera uno nuevo', function (): void {
    $this->post('/forgot-password', ['email' => 'duena@heladeria.test']);
    $primerCodigo = ultimoCodigoEnviado();

    $this->post('/forgot-password', ['email' => 'duena@heladeria.test']);

    Mail::assertSent(PasswordResetCodeMail::class, 1); // el segundo intento no mandó nada más
    expect(DB::table('password_reset_codes')->where('email', 'duena@heladeria.test')->count())->toBe(1);
    expect(Hash::check($primerCodigo, DB::table('password_reset_codes')->where('email', 'duena@heladeria.test')->value('code_hash')))->toBeTrue();
});

// ---------------------------------------------------------------- Cambio de contraseña

it('el flujo completo cambia la contraseña, autentica y borra el código', function (): void {
    $this->post('/forgot-password', ['email' => 'duena@heladeria.test']);
    $codigo = ultimoCodigoEnviado();

    $this->post('/reset-password', [
        'email' => 'duena@heladeria.test',
        'code' => $codigo,
        'password' => 'ClaveNueva123!',
        'password_confirmation' => 'ClaveNueva123!',
    ])->assertSessionHasNoErrors()->assertRedirect(route('dashboard'));

    expect(Hash::check('ClaveNueva123!', $this->user->fresh()->password))->toBeTrue();
    $this->assertAuthenticatedAs($this->user->fresh());

    // De un solo uso: no queda fila para volver a intentarlo con el mismo código.
    expect(DB::table('password_reset_codes')->where('email', 'duena@heladeria.test')->exists())->toBeFalse();
});

it('la contraseña vieja deja de servir', function (): void {
    $this->post('/forgot-password', ['email' => 'duena@heladeria.test']);
    $codigo = ultimoCodigoEnviado();

    $this->post('/reset-password', [
        'email' => 'duena@heladeria.test', 'code' => $codigo,
        'password' => 'ClaveNueva123!', 'password_confirmation' => 'ClaveNueva123!',
    ]);

    auth()->logout();

    $this->post('/login', ['email' => 'duena@heladeria.test', 'password' => 'clave-vieja-123'])
        ->assertSessionHasErrors();

    $this->assertGuest();
});

it('un código inventado no cambia nada', function (): void {
    $this->post('/forgot-password', ['email' => 'duena@heladeria.test']);

    $this->post('/reset-password', [
        'email' => 'duena@heladeria.test',
        'code' => '000000',
        'password' => 'ClaveNueva123!',
        'password_confirmation' => 'ClaveNueva123!',
    ])->assertSessionHasErrors('code');

    expect(Hash::check('clave-vieja-123', $this->user->fresh()->password))->toBeTrue();
    $this->assertGuest();
});

it('el código de una cuenta no sirve para otra', function (): void {
    $otro = User::create([
        'company_id' => $this->company->id, 'name' => 'Otro',
        'email' => 'otro@heladeria.test', 'password' => 'su-clave-123', 'is_active' => true,
    ]);

    $this->post('/forgot-password', ['email' => 'duena@heladeria.test']);
    $codigo = ultimoCodigoEnviado();

    $this->post('/reset-password', [
        'email' => 'otro@heladeria.test', // código de la dueña, correo de otro
        'code' => $codigo,
        'password' => 'ClaveNueva123!',
        'password_confirmation' => 'ClaveNueva123!',
    ])->assertSessionHasErrors('code');

    expect(Hash::check('su-clave-123', $otro->fresh()->password))->toBeTrue();
});

it('exige repetir la contraseña', function (): void {
    $this->post('/forgot-password', ['email' => 'duena@heladeria.test']);
    $codigo = ultimoCodigoEnviado();

    $this->post('/reset-password', [
        'email' => 'duena@heladeria.test', 'code' => $codigo,
        'password' => 'ClaveNueva123!', 'password_confirmation' => 'otra-distinta',
    ])->assertSessionHasErrors('password');

    expect(Hash::check('clave-vieja-123', $this->user->fresh()->password))->toBeTrue();
});

it('un código caducado se rechaza', function (): void {
    $this->post('/forgot-password', ['email' => 'duena@heladeria.test']);
    $codigo = ultimoCodigoEnviado();

    DB::table('password_reset_codes')->where('email', 'duena@heladeria.test')
        ->update(['expires_at' => now()->subMinute()]);

    $this->post('/reset-password', [
        'email' => 'duena@heladeria.test', 'code' => $codigo,
        'password' => 'ClaveNueva123!', 'password_confirmation' => 'ClaveNueva123!',
    ])->assertSessionHasErrors('code');

    expect(Hash::check('clave-vieja-123', $this->user->fresh()->password))->toBeTrue();
});

it('al quinto intento fallido el código queda invalidado, aunque el sexto sea el correcto', function (): void {
    $this->post('/forgot-password', ['email' => 'duena@heladeria.test']);
    $codigo = ultimoCodigoEnviado();

    for ($i = 0; $i < 5; $i++) {
        $this->post('/reset-password', [
            'email' => 'duena@heladeria.test', 'code' => '111111',
            'password' => 'ClaveNueva123!', 'password_confirmation' => 'ClaveNueva123!',
        ])->assertSessionHasErrors('code');
    }

    // El código de verdad, después de agotar los intentos: ya no sirve, hay que pedir uno nuevo.
    $this->post('/reset-password', [
        'email' => 'duena@heladeria.test', 'code' => $codigo,
        'password' => 'ClaveNueva123!', 'password_confirmation' => 'ClaveNueva123!',
    ])->assertSessionHasErrors('code');

    expect(Hash::check('clave-vieja-123', $this->user->fresh()->password))->toBeTrue();
});
