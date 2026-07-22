<?php

declare(strict_types=1);

namespace App\Modules\Core\Http\Middleware;

use App\Models\User;
use App\Modules\Core\Tenancy\CurrentCompany;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Corta el acceso al panel cuando la empresa no puede operar, llevando al usuario a la página de
 * cuenta suspendida. Bloquea en dos casos:
 *
 *   1. La empresa fue suspendida por el operador (is_active = false).
 *   2. La empresa tiene una suscripción que no está al día (vencida, suspendida o cancelada).
 *
 * El super administrador (operador de la plataforma) lo atraviesa. Las empresas activas sin
 * suscripción (heredadas) pasan con normalidad.
 */
final class EnsureSubscriptionActive
{
    public function __construct(private readonly CurrentCompany $currentCompany) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && $user->isSuperAdmin()) {
            return $next($request);
        }

        // Instancia compartida de la petición: ya trae suscripción y plan cargados.
        $company = $this->currentCompany->model();

        if ($company === null) {
            return $next($request);
        }

        $subscription = $company->subscription;
        $blocked = ! $company->is_active
            || ($subscription !== null && ! $subscription->isUsable());

        if ($blocked) {
            return redirect()->route('panel.suspended');
        }

        return $next($request);
    }
}
