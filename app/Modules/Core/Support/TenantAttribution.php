<?php

declare(strict_types=1);

namespace App\Modules\Core\Support;

use App\Models\User;
use App\Modules\Core\Models\Company;
use App\Modules\Core\Tenancy\CurrentCompany;
use Throwable;

/**
 * ¿A qué empresa se le atribuye lo que acaba de pasar?
 *
 * Existe por una trampa que ya mordió a esta pantalla: `CurrentCompany` NO es nulo para el operador de
 * la plataforma. `SetCurrentCompany` le fija la empresa de su sesión o, si no eligió ninguna, LA PRIMERA
 * POR ID. Así que un error, un correo de prueba o un intento de acceso del operador —que no son de
 * ninguna empresa— quedaban anotados como de la empresa 1, sin dar error y sin que nadie lo notara
 * mientras hubiera una sola empresa de prueba. Con errores compartidos entre varias empresas, esa
 * atribución falsa inflaría el «empresas afectadas» de un error que no le toca a nadie.
 *
 * La regla, por orden:
 *
 *  1. Un usuario de empresa: SU empresa. No la «activa»: son lo mismo, pero esta no depende de nada más.
 *  2. El operador de la plataforma: la empresa que la RUTA nombre (`/plataforma/empresas/{company}/…`),
 *     que es cuando de verdad está actuando sobre una; si no, ninguna. Nunca «la primera».
 *  3. Nadie con sesión (un job, un webhook, el portal del cliente): la empresa activa si hay una. Los
 *     jobs la fijan a mano con `CurrentCompany::set()`, y ahí sí es la de verdad.
 *
 * NUNCA lanza: se llama desde dentro del manejador de errores y del registro de sucesos.
 */
final class TenantAttribution
{
    public static function companyId(): ?int
    {
        try {
            $usuario = auth()->user();

            if ($usuario instanceof User) {
                if ($usuario->isSuperAdmin()) {
                    return self::deLaRuta();
                }

                return $usuario->company_id !== null ? (int) $usuario->company_id : null;
            }

            $actual = app(CurrentCompany::class);

            return $actual->has() ? $actual->id() : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** La empresa que nombra el parámetro `{company}` de la ruta, ya resuelto o sin resolver. */
    private static function deLaRuta(): ?int
    {
        $parametro = request()->route()?->parameter('company');

        if ($parametro instanceof Company) {
            return (int) $parametro->getKey();
        }

        return is_numeric($parametro) ? (int) $parametro : null;
    }
}
