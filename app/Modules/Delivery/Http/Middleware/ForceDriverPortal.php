<?php

declare(strict_types=1);

namespace App\Modules\Delivery\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Encierra al repartidor en su portal.
 *
 * Mismo patrón que `ForceKioskMode` del punto de venta, con una diferencia deliberada: aquel se
 * enciende por empresa (`settings['pos']['kiosk_roles']`) porque hay negocios cuyos cajeros SÍ usan
 * el panel. Aquí no hay nada que configurar: el rol «Repartidor» existe para llevar pedidos, no
 * tiene ningún otro permiso, y sin este desvío su inicio de sesión terminaría en un 403 en el
 * dashboard —no tiene `dashboard.view`— sin ninguna pantalla a la que ir.
 *
 * La lista es BLANCA, nunca negra: una pantalla nueva del panel queda cerrada por omisión en vez de
 * abrirse por descuido.
 */
final class ForceDriverPortal
{
    /**
     * Rutas que un repartidor SÍ puede pedir.
     *
     * `logout` no es opcional: sin ella queda atrapado y solo sale borrando las cookies del móvil.
     *
     * @var array<int, string>
     */
    public const ALLOWED_ROUTES = [
        'portal.deliveries*',
        'portal.employee',   // su propia ficha y sus asistencias
        'logout',
        'panel.suspended',   // destino de EnsureSubscriptionActive: nunca bloquearlo
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || $user->isSuperAdmin() || ! $user->hasRole('driver')) {
            return $next($request);
        }

        if (Str::is(self::ALLOWED_ROUTES, $request->route()?->getName() ?? '')) {
            return $next($request);
        }

        // Una petición de datos no debe recibir el HTML de otra pantalla.
        if ($request->expectsJson()) {
            return response()->json(['message' => 'Tu sesión solo permite ver tus entregas.'], 403);
        }

        return redirect()->route('portal.deliveries');
    }
}
