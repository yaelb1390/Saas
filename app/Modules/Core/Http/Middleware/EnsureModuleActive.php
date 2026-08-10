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

    /**
     * Admite VARIOS módulos («module:pos,quick_pos»), y basta con tener uno.
     *
     * Lo exigen los endpoints compartidos: el cobro, la apertura y el cierre de caja los usan tanto
     * el punto de venta de mostrador como el terminal táctil, que son módulos distintos. Atarlos a
     * uno solo dejaría a quien contrató el otro con una pantalla que no puede cobrar.
     */
    public function handle(Request $request, Closure $next, string ...$modules): Response
    {
        $user = $request->user();

        if ($user instanceof User && $user->isSuperAdmin()) {
            return $next($request);
        }

        // Instancia compartida de la petición: ya trae suscripción y plan cargados.
        $company = $this->currentCompany->model();

        // Bucle simple y no `array_any()`: esa función es de PHP 8.4 y el proyecto declara ^8.2
        // (producción corre 8.3), donde sería un error fatal.
        $permitido = false;

        foreach ($modules as $module) {
            if ($company !== null && $company->hasModule($module)) {
                $permitido = true;
                break;
            }
        }

        if (! $permitido) {
            abort(403, 'Este módulo no está incluido en el plan de tu empresa.');
        }

        return $next($request);
    }
}
