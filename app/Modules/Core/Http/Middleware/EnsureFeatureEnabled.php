<?php

declare(strict_types=1);

namespace App\Modules\Core\Http\Middleware;

use App\Models\User;
use App\Modules\Core\Tenancy\CurrentCompany;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bloquea una función que la empresa ha decidido no usar.
 *
 * Hermano de `EnsureModuleActive`, y la diferencia importa:
 *
 *  - El MÓDULO dice qué compró la empresa. Lo decide el plan y no lo cambia el cliente.
 *  - La FUNCIÓN dice qué usa de lo que ya tiene. Lo enciende y lo apaga el propio cliente.
 *
 * Una heladería y una ferretería pueden tener las dos el terminal táctil contratado, y solo a una le
 * sirven los tamaños y sabores. Ocultarlo del menú no basta: sin esta puerta, la dirección seguiría
 * abierta a quien la teclee o la tenga guardada de antes.
 *
 * El super administrador la atraviesa, igual que con los módulos: administra las empresas.
 */
final class EnsureFeatureEnabled
{
    public function __construct(private readonly CurrentCompany $currentCompany) {}

    public function handle(Request $request, Closure $next, string $feature): Response
    {
        $user = $request->user();

        if ($user instanceof User && $user->isSuperAdmin()) {
            return $next($request);
        }

        $company = $this->currentCompany->model();

        // Sin empresa activa no se puede afirmar que la tenga apagada; deciden los demás filtros,
        // que es como se comporta el resto del sistema cuando no hay empresa.
        if ($company !== null && ! $company->usesFeature($feature)) {
            abort(403, 'Esta función está desactivada para tu negocio. Puedes encenderla en «Mi empresa».');
        }

        return $next($request);
    }
}
