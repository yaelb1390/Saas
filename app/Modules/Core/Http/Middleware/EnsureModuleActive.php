<?php

declare(strict_types=1);

namespace App\Modules\Core\Http\Middleware;

use App\Models\User;
use App\Modules\Core\Tenancy\CurrentCompany;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bloquea el acceso a un módulo que la empresa activa no tiene contratado.
 *
 * Es una comprobación distinta de los permisos: el permiso dice «este usuario puede», el módulo
 * dice «esta empresa lo compró». Un dueño con todos los permisos igualmente no entra a un módulo
 * que su plan no incluye.
 *
 * El super administrador (operador de la plataforma) lo atraviesa: gestiona los planes.
 */
final class EnsureModuleActive
{
    public function __construct(private readonly CurrentCompany $currentCompany) {}

    public function handle(Request $request, Closure $next, string $module): Response
    {
        $user = $request->user();

        if ($user instanceof User && $user->isSuperAdmin()) {
            return $next($request);
        }

        // Instancia compartida de la petición: ya trae suscripción y plan cargados.
        $company = $this->currentCompany->model();

        if ($company === null || ! $company->hasModule($module)) {
            abort(403, 'Este módulo no está incluido en el plan de tu empresa.');
        }

        return $next($request);
    }
}
