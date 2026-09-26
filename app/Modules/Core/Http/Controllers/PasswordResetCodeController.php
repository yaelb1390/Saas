<?php

declare(strict_types=1);

namespace App\Modules\Core\Http\Controllers;

use App\Actions\Fortify\PasswordValidationRules;
use App\Modules\Core\Services\PasswordResetCodeService;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;

/**
 * Recuperar la contraseña con un código de 6 dígitos, en vez del enlace clicable que Fortify
 * registraba (ver config/fortify.php, `resetPasswords()` está apagado; estas rutas ocupan los
 * mismos cuatro nombres —`password.request`, `password.email`, `password.reset`,
 * `password.update`— para que nada que ya use `route(...)` con esos nombres se entere del cambio).
 */
final class PasswordResetCodeController extends Controller
{
    use PasswordValidationRules;

    public function create(): View
    {
        return view('auth.forgot-password');
    }

    public function store(Request $request, PasswordResetCodeService $codes): RedirectResponse
    {
        $data = $request->validate(['email' => ['required', 'email']]);

        $codes->sendCode($data['email']);

        // Mismo mensaje pase lo que pase: que el correo no exista, esté desactivado, o ya tenga un
        // código reciente no puede notarse desde aquí.
        return redirect()->route('password.reset', ['email' => $data['email']])
            ->with('status', 'Si ese correo tiene una cuenta, te llegará un código en un momento.');
    }

    public function edit(Request $request): View
    {
        return view('auth.reset-password', ['request' => $request]);
    }

    public function update(Request $request, PasswordResetCodeService $codes): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'code' => ['required', 'digits:6'],
            'password' => $this->passwordRules(),
        ]);

        try {
            $user = $codes->verifyAndReset($data['email'], $data['code'], [
                'password' => $data['password'],
                'password_confirmation' => (string) $request->input('password_confirmation'),
            ]);
        } catch (ValidationException $e) {
            return back()->withInput(['email' => $data['email']])->withErrors($e->errors());
        }

        Auth::login($user);
        Event::dispatch(new PasswordReset($user));

        return redirect()->route('dashboard')->with('status', 'Tu contraseña se cambió correctamente.');
    }
}
