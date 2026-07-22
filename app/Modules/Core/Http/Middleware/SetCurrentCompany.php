<?php

declare(strict_types=1);

namespace App\Modules\Core\Http\Middleware;

use App\Modules\Core\Models\Company;
use App\Modules\Core\Tenancy\CurrentCompany;
use Closure;
use Illuminate\Http\Request;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;

/**
 * Inicializa la empresa activa a partir del usuario autenticado.
 *
 * Debe ejecutarse después del middleware de autenticación. Un usuario de empresa queda fijado a
 * su propia empresa. Un super administrador puede elegir la empresa activa (guardada en sesión);
 * por defecto toma la primera disponible.
 */
final class SetCurrentCompany
{
    public function __construct(private readonly CurrentCompany $currentCompany) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return $next($request);
        }

        $companyId = null;

        if (! $user->is_super_admin && $user->company_id !== null) {
            $companyId = (int) $user->company_id;
        } elseif ($user->is_super_admin) {
            // El super admin elige la empresa activa (sesión); valida que exista.
            $selected = $request->session()->get('active_company_id');
            $companyId = ($selected !== null && Company::query()->whereKey($selected)->exists())
                ? (int) $selected
                : Company::query()->orderBy('id')->value('id');
        }

        if ($companyId !== null) {
            $this->currentCompany->set((int) $companyId);

            // Alinea el contexto de roles/permisos por empresa (spatie teams).
            app(PermissionRegistrar::class)->setPermissionsTeamId((int) $companyId);
        }

        return $next($request);
    }
}
