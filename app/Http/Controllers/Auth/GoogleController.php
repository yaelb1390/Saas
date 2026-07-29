<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
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
    public function redirect(): RedirectResponse
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
        try {
            $googleUser = Socialite::driver('google')->user();
        } catch (Throwable) {
            return redirect()->route('login')->withErrors([
                'email' => 'No se pudo completar el acceso con Google. Inténtalo de nuevo.',
            ]);
        }

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

        return redirect()->intended(route('dashboard'));
    }
}
