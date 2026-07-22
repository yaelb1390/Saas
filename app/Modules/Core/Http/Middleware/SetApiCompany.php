<?php

declare(strict_types=1);

namespace App\Modules\Core\Http\Middleware;

use App\Models\User;
use App\Modules\Core\Tenancy\CurrentCompany;
use Closure;
use Illuminate\Http\Request;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fija la empresa activa a partir del token de la API (stateless).
 *
 * A diferencia de SetCurrentCompany (web), aquí no hay sesión: el tenant se toma directamente de
 * la empresa del usuario dueño del token. Así cada token queda confinado a su empresa y el
 * aislamiento multiempresa se aplica igual que en el panel.
 */
final class SetApiCompany
{
    public function __construct(private readonly CurrentCompany $currentCompany) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && $user->company_id !== null) {
            $companyId = (int) $user->company_id;

            $this->currentCompany->set($companyId);
            app(PermissionRegistrar::class)->setPermissionsTeamId($companyId);
        }

        return $next($request);
    }
}
