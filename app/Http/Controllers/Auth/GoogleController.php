<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Models\User;
use App\Modules\Core\Models\SystemEvent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

/**
 * Inicio de sesión con Google (Laravel Socialite).
 *
 * Política: SOLO cuentas que ya existen. Google entrega un correo ya verificado; se busca al usuario
 * por ese correo y, si existe y está activo, se inicia su sesión. Un correo desconocido NO crea
 * nada: se devuelve al login con un aviso claro. La primera vez se guarda el `google_id` para
 * reconocer al usuario aunque más adelante cambiara de correo.
 */
final class GoogleController extends Controller
{
    /** Envía al usuario a la pantalla de consentimiento de Google. */
    public function redirect(): \Symfony\Component\HttpFoundation\RedirectResponse
    {
        if (blank(config('services.google.client_id'))) {
            return redirect()->route('login')->withErrors([
                'email' => 'El acceso con Google aún no está configurado. Contacta al administrador.',
            ]);
        }

        return Socialite::driver('google')->redirect();
    }

    /** Recibe el retorno de Google, valida y arranca la sesión si la cuenta existe. */
    public function callback(Request $request): RedirectResponse
    {
        // Todo el retorno va protegido: un callback de autenticación nunca debe acabar en una
        // pantalla de 500. Cualquier fallo (Google, esquema de la base, almacén de sesión) se
        // registra y devuelve al login con un aviso, en vez de escupir el error al usuario.
        try {
            $googleUser = Socialite::driver('google')->user();

            $email = $googleUser->getEmail();

            if (blank($email)) {
                return redirect()->route('login')->withErrors([
                    'email' => 'Tu cuenta de Google no tiene un correo disponible.',
                ]);
            }

            // Si ya se vinculó antes, se reconoce por google_id; si no, se empareja por el correo.
            $user = User::where('google_id', $googleUser->getId())->first()
                ?? User::whereRaw('lower(email) = ?', [mb_strtolower($email)])->first();

            if ($user === null) {
                return redirect()->route('login')->withErrors([
                    'email' => "No hay una cuenta con el correo {$email}. Pide a tu administrador que te dé de alta.",
                ]);
            }

            if (! $user->is_active) {
                return redirect()->route('login')->withErrors([
                    'email' => 'Tu cuenta está desactivada. Contacta al administrador.',
                ]);
            }

            // Vincula el id estable de Google la primera vez.
            if (blank($user->google_id)) {
                $user->forceFill(['google_id' => $googleUser->getId()])->save();
            }

            Auth::login($user, remember: true);
            $request->session()->regenerate();
        } catch (Throwable $e) {
            return $this->rechazar($e, 'No se pudo completar el acceso con Google. Inténtalo de nuevo.');
        }

        return redirect()->intended(route('dashboard'));
    }

    /**
     * Registra el motivo real y devuelve al login con un aviso comprensible.
     *
     * El log se mantiene corto a propósito (clase, mensaje y origen, sin rastro de pila): en
     * serverless los bloques largos se truncan por el principio y la cabecera —lo único que
     * identifica el fallo— se pierde.
     */
    private function rechazar(Throwable $e, string $aviso): RedirectResponse
    {
        SystemEvent::registrar(
            type: 'auth.failed',
            message: 'Falló el acceso con Google',
            level: SystemEvent::AVISO,
        );

        Log::warning('Fallo el acceso con Google.', [
            'exception' => $e::class,
            'message' => mb_substr($e->getMessage(), 0, 300),
            'origen' => basename($e->getFile()).':'.$e->getLine(),
        ]);

        return redirect()->route('login')->withErrors(['email' => $aviso]);
    }
}
