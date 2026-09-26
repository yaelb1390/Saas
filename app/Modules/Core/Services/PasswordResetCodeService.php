<?php

declare(strict_types=1);

namespace App\Modules\Core\Services;

use App\Actions\Fortify\ResetUserPassword;
use App\Models\User;
use App\Modules\Core\Mail\PasswordResetCodeMail;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/**
 * Recuperar la contraseña con un código de 6 dígitos, en vez del enlace clicable que traía Fortify
 * (ver config/fortify.php: `resetPasswords()` queda apagado y este servicio ocupa su lugar).
 *
 * Misma forma que la tabla estándar de Laravel (`password_reset_tokens`): una fila por correo en
 * `password_reset_codes`, que se sobrescribe al pedir un código nuevo — un solo código vivo por
 * cuenta a la vez.
 */
final class PasswordResetCodeService
{
    private const MINUTOS_VIGENCIA = 15;

    private const SEGUNDOS_ENTRE_ENVIOS = 60;

    private const INTENTOS_MAXIMOS = 5;

    /**
     * Genera y manda el código, si procede. Nunca revela por la respuesta si el correo tiene
     * cuenta ni en qué estado está: eso lo decide todo aquí dentro, en silencio.
     */
    public function sendCode(string $email): void
    {
        $user = User::where('email', $email)->first();

        if ($user === null || ! $user->is_active) {
            return;
        }

        $fila = DB::table('password_reset_codes')->where('email', $email)->first();

        // Mismo criterio que el broker de Laravel: no generar uno nuevo si el anterior es reciente,
        // para que no se pueda bombardear el correo con reenvíos.
        if ($fila !== null && Carbon::parse($fila->created_at)->addSeconds(self::SEGUNDOS_ENTRE_ENVIOS)->isFuture()) {
            return;
        }

        $codigo = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        DB::table('password_reset_codes')->updateOrInsert(
            ['email' => $email],
            [
                'code_hash' => Hash::make($codigo),
                'attempts' => 0,
                'expires_at' => now()->addMinutes(self::MINUTOS_VIGENCIA),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        Mail::to($user->email)->send(new PasswordResetCodeMail(
            ownerName: (string) $user->name,
            code: $codigo,
            expiresInMinutes: self::MINUTOS_VIGENCIA,
            supportWhatsapp: (string) config('platform.support_whatsapp'),
            supportEmail: (string) config('platform.support_email'),
        ));
    }

    /**
     * Comprueba el código y, si cuadra, fija la contraseña nueva.
     *
     * Un solo mensaje de error para todos los motivos de fallo (código que no existe, caducado,
     * agotado a intentos, o que no coincide): distinguirlos le diría a quien prueba a ciegas cuál
     * de las cuatro cosas acertó.
     *
     * @param  array<string, string>  $input  password + password_confirmation, tal como los espera
     *                                        `ResetUserPassword::reset()`.
     *
     * @throws ValidationException
     */
    public function verifyAndReset(string $email, string $code, array $input): User
    {
        $mensajeGenerico = 'Ese código no es válido o ya caducó. Pide uno nuevo.';

        $user = User::where('email', $email)->first();
        $fila = DB::table('password_reset_codes')->where('email', $email)->first();

        if ($user === null || $fila === null) {
            throw ValidationException::withMessages(['code' => $mensajeGenerico]);
        }

        if (Carbon::parse($fila->expires_at)->isPast()) {
            DB::table('password_reset_codes')->where('email', $email)->delete();

            throw ValidationException::withMessages(['code' => $mensajeGenerico]);
        }

        if ($fila->attempts >= self::INTENTOS_MAXIMOS) {
            // Agotado: hay que pedir uno nuevo aunque el que se acaba de teclear fuera el correcto.
            DB::table('password_reset_codes')->where('email', $email)->delete();

            throw ValidationException::withMessages(['code' => $mensajeGenerico]);
        }

        if (! Hash::check($code, $fila->code_hash)) {
            DB::table('password_reset_codes')->where('email', $email)->increment('attempts');

            throw ValidationException::withMessages(['code' => $mensajeGenerico]);
        }

        // Se reutiliza tal cual la Action que ya usaba Fortify: valida las reglas de contraseña y
        // guarda con Hash::make(), sin duplicar esa lógica.
        app(ResetUserPassword::class)->reset($user, $input);

        DB::table('password_reset_codes')->where('email', $email)->delete();

        return $user;
    }
}
