<?php

declare(strict_types=1);

namespace App\Modules\POS\Http\Middleware;

use App\Models\User;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\POS\Support\KioskMode;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Encierra al cajero en el terminal de venta.
 *
 * Mismo patrón que `EnsureSubscriptionActive`: redirige a una pantalla que está deliberadamente
 * DENTRO de la lista blanca, porque si el destino también se bloqueara el usuario entraría en un
 * bucle de redirecciones infinito.
 *
 * Con esto no hace falta tocar el inicio de sesión: quien se autentica va a `/dashboard` y aquí se
 * le desvía. Personalizar además el `LoginResponse` de Fortify obligaría a duplicar la regla en el
 * acceso con Google (Socialite no pasa por Fortify) y en la redirección de la raíz; un solo punto es
 * un solo sitio que mantener.
 */
final class ForceKioskMode
{
    public function __construct(private readonly CurrentCompany $currentCompany) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! KioskMode::applies($user, $this->currentCompany->model())) {
            return $next($request);
        }

        if (KioskMode::allows($request->route()?->getName())) {
            return $next($request);
        }

        // Una petición de datos no debe recibir el HTML de otra pantalla: se le dice sin rodeos que
        // no tiene acceso, y el terminal puede mostrarlo en vez de pintar una redirección.
        if ($request->expectsJson()) {
            return response()->json(['message' => 'Tu sesión solo permite operar el punto de venta.'], 403);
        }

        return redirect()->route('panel.quick-pos.index');
    }
}
