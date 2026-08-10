<?php

declare(strict_types=1);

namespace App\Modules\POS\Support;

use App\Models\User;
use App\Modules\Core\Models\Company;
use Illuminate\Support\Str;

/**
 * Decide quién queda encerrado en el terminal de venta y qué puede seguir haciendo.
 *
 * El cajero no debe navegar por el panel: debe concentrarse en vender. Hoy el rol `staff` conserva
 * el permiso `dashboard.view` solo «para que tenga una pantalla de aterrizaje válida»
 * (`RoleProvisioner::ROLES`); el modo kiosco le da esa pantalla y le cierra el resto.
 *
 * Los roles de kiosco se guardan en `companies.settings['pos']['kiosk_roles']`, igual que hace
 * `PosProfile` con el perfil de negocio: la tabla `users` no tiene columna de preferencias y no
 * merece una migración solo por esto.
 */
final class KioskMode
{
    /**
     * El modo kiosco está APAGADO mientras la empresa no lo pida.
     *
     * Encerrar al rol `staff` por defecto parecía cómodo, pero habría dejado fuera del POS de
     * mostrador a los cajeros de TODAS las empresas de la plataforma —incluidas las que lo usan a
     * diario, como las de repuestos— sin que nadie lo hubiera pedido. Un cambio de comportamiento
     * así se activa a propósito, no por omisión.
     *
     * Para encenderlo: `settings['pos']['kiosk_roles'] = ['staff']` en la empresa.
     */
    public const DEFAULT_ROLES = [];

    /** Lo que se guarda al activarlo desde la interfaz. */
    public const TYPICAL_ROLES = ['staff'];

    /**
     * Rutas que un usuario de kiosco SÍ puede pedir.
     *
     * Es una lista BLANCA, nunca negra: si mañana se añade una pantalla al panel, queda cerrada por
     * omisión en vez de abrirse por descuido. Cada entrada admite un `*` final como comodín.
     *
     * Ojo con dos que no son evidentes:
     *   - `logout`: sin ella el cajero queda atrapado y solo sale borrando cookies.
     *   - `panel.products.image`: sin ella la rejilla se pinta sin fotos.
     *
     * @var array<int, string>
     */
    public const ALLOWED_ROUTES = [
        'panel.quick-pos.*',      // la propia pantalla y su catálogo
        'panel.pos.checkout',     // cobrar
        'panel.pos.open',         // abrir caja (sin caja no se vende)
        'panel.pos.close',        // cerrar caja con arqueo al acabar el turno
        'panel.sales.receipt',    // ver y reimprimir el recibo
        'panel.sales.receipt.pdf',
        'panel.products.image',   // las fotos de la rejilla
        'logout',
        'panel.suspended',        // destino de EnsureSubscriptionActive: nunca bloquearlo
    ];

    /** ¿Este usuario opera en modo kiosco? */
    public static function applies(?User $user, ?Company $company): bool
    {
        // El operador de la plataforma nunca se encierra: necesita el panel entero para dar soporte.
        if ($user === null || $user->isSuperAdmin()) {
            return false;
        }

        $roles = self::rolesFor($company);

        // Sin roles configurados el modo está apagado. Se corta aquí en vez de delegar en
        // `hasAnyRole([])` para no depender de cómo trate el paquete de permisos una lista vacía.
        if ($roles === []) {
            return false;
        }

        return $user->hasAnyRole($roles);
    }

    /**
     * Roles encerrados en la empresa dada.
     *
     * @return array<int, string>
     */
    public static function rolesFor(?Company $company): array
    {
        $configured = $company?->settings['pos']['kiosk_roles'] ?? null;

        if (! is_array($configured) || $configured === []) {
            return self::DEFAULT_ROLES;
        }

        return array_values(array_filter(array_map(
            static fn (mixed $role): string => (string) $role,
            $configured,
        )));
    }

    /** ¿La ruta pedida está en la lista blanca? */
    public static function allows(?string $routeName): bool
    {
        if ($routeName === null) {
            return false;
        }

        return Str::is(self::ALLOWED_ROUTES, $routeName);
    }
}
