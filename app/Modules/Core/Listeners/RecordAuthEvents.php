<?php

declare(strict_types=1);

namespace App\Modules\Core\Listeners;

use App\Models\User;
use App\Modules\Core\Models\SystemEvent;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Events\Dispatcher;

/**
 * Deja constancia de quién entra, quién sale y quién lo intenta sin conseguirlo.
 *
 * Hasta ahora NO SE REGISTRABA NINGÚN ACCESO. No había manera de responder a «¿quién entró en la
 * cuenta?» ni de ver que alguien lleva doscientos intentos contra el correo de un cliente, que es
 * justo la pregunta que se hace cuando algo raro pasa.
 *
 * Va como escuchador de los eventos que Laravel ya dispara, y no metido en el controlador de acceso:
 * así cubre TODAS las puertas —el formulario, Google, las passkeys, el segundo factor y la API— sin
 * tener que acordarse de cada una.
 */
final class RecordAuthEvents
{
    public function alEntrar(Login $evento): void
    {
        $usuario = $evento->user;

        SystemEvent::registrar(
            type: 'auth.login',
            message: $this->nombre($usuario).' entró al sistema',
            contexto: ['guard' => $evento->guard, 'recordado' => $evento->remember],
            companyId: $usuario instanceof User ? $usuario->company_id : null,
            userId: $usuario->getAuthIdentifier(),
        );
    }

    public function alSalir(Logout $evento): void
    {
        $usuario = $evento->user;

        // Cerrar sesión por caducidad no dispara este evento, así que la ausencia de una salida no
        // significa que siga dentro. Se registra igual: sirve para acotar cuándo estuvo.
        SystemEvent::registrar(
            type: 'auth.logout',
            message: $this->nombre($usuario).' cerró sesión',
            companyId: $usuario instanceof User ? $usuario->company_id : null,
            userId: $usuario?->getAuthIdentifier(),
        );
    }

    public function alFallar(Failed $evento): void
    {
        /*
         * El correo se guarda; la contraseña NO.
         *
         * `$evento->credentials` trae la contraseña tecleada en claro, y guardarla sería peor que no
         * tener registro: quien mire esta tabla vería las contraseñas de todos los que se
         * equivocaron de campo. Solo se toma el identificador.
         */
        $correo = (string) ($evento->credentials['email'] ?? 'desconocido');

        /*
         * La cuenta se busca AQUÍ, no se lee de `$evento->user`.
         *
         * Este proyecto autentica con `Fortify::authenticateUsing()`, una función propia que devuelve
         * `null` cuando algo no cuadra —contraseña mala, cuenta desactivada—. Fortify entonces
         * dispara el evento sin usuario SIEMPRE, así que fiarse de él daba «la cuenta no existe»
         * también cuando existía. Comprobado: el registro salía mal y el test lo cazó.
         *
         * Es una consulta más, y solo en los intentos fallidos, por un índice único. Lo que compra:
         * distinguir «se equivocó de contraseña» de «está probando correos al azar», y saber a QUÉ
         * EMPRESA le están intentando entrar.
         */
        $cuenta = User::query()->where('email', $correo)->first(['id', 'company_id', 'is_active']);

        SystemEvent::registrar(
            type: 'auth.failed',
            message: 'Intento de acceso fallido con '.$correo,
            contexto: [
                'correo' => $correo,
                'la_cuenta_existe' => $cuenta !== null,
                // Una cuenta desactivada con la contraseña buena falla igual, y eso no es un ataque:
                // es alguien a quien le quitaron el acceso y no lo sabe.
                'la_cuenta_esta_activa' => $cuenta?->is_active,
            ],
            level: SystemEvent::AVISO,
            companyId: $cuenta?->company_id,
        );
    }

    public function alBloquear(Lockout $evento): void
    {
        // Esto ya no es un dedazo: es el limitador de Laravel cortando una ráfaga.
        SystemEvent::registrar(
            type: 'auth.lockout',
            message: 'Demasiados intentos seguidos: acceso bloqueado temporalmente',
            contexto: ['correo' => (string) $evento->request->input('email', '')],
            level: SystemEvent::GRAVE,
        );
    }

    public function alCambiarContrasena(PasswordReset $evento): void
    {
        SystemEvent::registrar(
            type: 'auth.password_reset',
            message: $this->nombre($evento->user).' cambió su contraseña',
            level: SystemEvent::AVISO,
            companyId: $evento->user instanceof User ? $evento->user->company_id : null,
            userId: $evento->user->getAuthIdentifier(),
        );
    }

    public function subscribe(Dispatcher $eventos): array
    {
        return [
            Login::class => 'alEntrar',
            Logout::class => 'alSalir',
            Failed::class => 'alFallar',
            Lockout::class => 'alBloquear',
            PasswordReset::class => 'alCambiarContrasena',
        ];
    }

    private function nombre(mixed $usuario): string
    {
        return $usuario instanceof User ? $usuario->name : 'Alguien';
    }
}
