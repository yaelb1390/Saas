<?php

declare(strict_types=1);

namespace App\Modules\Core\Monitoring\Search;

use App\Modules\Core\Models\Audit;
use App\Modules\Core\Models\ErrorEvent;
use App\Modules\Core\Models\SystemEvent;
use App\Modules\Core\Monitoring\MonitoringSchema;
use App\Modules\Core\Support\BusquedaTexto;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as ConsultaBase;
use Illuminate\Pagination\CursorPaginator as PaginadorVacio;
use Illuminate\Support\Facades\DB;

/**
 * Las tres listas de la pantalla de monitoreo, con su búsqueda y sus filtros.
 *
 * Antes la búsqueda era `lower(message) like` y solo en el registro: para saber «¿qué le pasó a esta
 * empresa?» o «¿qué ve este usuario?» había que pasear por las pestañas leyendo. Aquí se busca por
 * lo que la gente pregunta de verdad —una persona, un correo, una IP, una empresa, un texto del
 * error— en una sola caja.
 *
 * TODAS LAS CONSULTAS VAN SIN EL ÁMBITO DE EMPRESA, como en el resto del monitoreo: quien las ve es el
 * operador de la plataforma y un super administrador SIEMPRE tiene una empresa activa; con el ámbito
 * puesto vería solo la suya haciéndola pasar por todas, sin dar error. El aislamiento entre clientes
 * no está aquí sino en la ruta (`can:platform.manage`): un usuario de empresa no llega a esta clase.
 *
 * Cómo se busca, por decisiones que no son obvias:
 *
 *  · El texto NUNCA se concatena en el SQL: va como parámetro, con sus comodines neutralizados
 *    ({@see BusquedaTexto}). Buscar «100%» busca «100%», no «cualquier cosa».
 *  · Las columnas que se recorren están escritas aquí, en constantes. Ninguna sale de la dirección.
 *  · A una persona o una empresa se llega con una SUBCONSULTA sobre `users`/`companies`, no con un
 *    `join`: un `join` multiplicaría las filas cuando el error afecta a muchas empresas.
 *  · El `context` de un suceso (el JSON con los detalles) solo se recorre si se pide (`en_detalle=1`):
 *    es la columna más ancha y un `like '%…%'` sobre ella lee la tabla entera.
 *  · Cada lista pagina por CURSOR con desempate por `id`: la tabla crece cada día, `paginate()` haría
 *    un `count(*)` completo en cada carga, y ordenar solo por fecha repetiría o se saltaría filas
 *    cuando varias comparten el mismo segundo.
 *
 * Todo lo que falta por migrar se comprueba antes de tocarlo ({@see MonitoringSchema}): el código sale
 * antes que las tablas y esta pantalla es la última que puede caerse.
 */
final class MonitoringSearch
{
    public const POR_PAGINA_REGISTRO = 30;

    public const POR_PAGINA_ERRORES = 25;

    public const POR_PAGINA_ACTIVIDAD = 25;

    /** Dónde se busca el texto en un suceso del registro. `service` se añade si la columna existe. */
    private const COLUMNAS_DE_SUCESO = ['message', 'type', 'ip', 'user_agent'];

    /** Dónde se busca el texto en un grupo de errores. */
    private const COLUMNAS_DE_ERROR = ['message', 'class', 'origin', 'url'];

    /** Lo que se busca en la auditoría. `ip_address` es `inet` en PostgreSQL y lleva su propio `cast`. */
    private const COLUMNAS_DE_AUDITORIA = ['event', 'auditable_type'];

    /**
     * El registro del sistema: quién entró, quién lo intentó, qué servicio falló.
     *
     * @return CursorPaginator<int, SystemEvent>
     */
    public function registro(MonitoringFilters $f): CursorPaginator
    {
        /*
         * Sin la tabla se devuelve un listado vacío y la pantalla se pinta. Se construye a mano y NO con
         * `->whereRaw('1 = 0')->cursorPaginate()`: eso también consulta, y con la tabla ausente daba 500.
         */
        if (! MonitoringSchema::haySucesos()) {
            return $this->vacio(self::POR_PAGINA_REGISTRO, 'reg');
        }

        $conServicio = MonitoringSchema::sucesosConServicio();

        $consulta = SystemEvent::query()->with(['company', 'user']);

        if ($f->empresa !== null) {
            $consulta->where('company_id', $f->empresa);
        }

        if ($f->familia !== null) {
            // Por prefijo: `auth` trae `auth.login`, `auth.failed` y los que se añadan mañana sin tocar el
            // filtro. La familia salió de una lista cerrada, no contiene comodines.
            $consulta->where('type', 'like', $f->familia.'.%');
        }

        if ($f->nivel !== null) {
            $consulta->where('level', $f->nivel);
        }

        if ($f->servicio !== null && $conServicio) {
            $consulta->where('service', $f->servicio);
        }

        $this->dentroDeLaVentana($consulta, 'created_at', $f);

        if ($f->busca !== null) {
            $patron = BusquedaTexto::patron($f->busca);
            $columnas = $conServicio ? [...self::COLUMNAS_DE_SUCESO, 'service'] : self::COLUMNAS_DE_SUCESO;

            $consulta->where(function (Builder $q) use ($patron, $columnas, $f): void {
                foreach ($columnas as $columna) {
                    $q->orWhereRaw($this->comoTexto($columna), [$patron]);
                }

                $q->orWhereIn('user_id', $this->usuariosQue($patron))
                    ->orWhereIn('company_id', $this->empresasQue($patron));

                if ($f->enDetalle) {
                    $q->orWhereRaw($this->comoTexto('cast(context as text)'), [$patron]);
                }
            });
        }

        return $consulta
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->cursorPaginate(self::POR_PAGINA_REGISTRO, ['*'], 'reg')
            ->withQueryString();
    }

    /**
     * Los errores agrupados.
     *
     * Con la migración de los grupos multiempresa aplicada se filtra por estado, servicio y empresa
     * usando el desglose; sin ella, con lo que hubiera: la «empresa» de un grupo es la última que lo
     * sufrió, y no hay estado ni servicio que filtrar.
     *
     * @return CursorPaginator<int, ErrorEvent>
     */
    public function errores(MonitoringFilters $f): CursorPaginator
    {
        if (! MonitoringSchema::hayErrores()) {
            return $this->vacio(self::POR_PAGINA_ERRORES, 'err');
        }

        $conDesglose = MonitoringSchema::erroresConDesglose();

        $consulta = ErrorEvent::query()->with('company');

        if ($conDesglose && $f->estado !== 'todos') {
            $consulta->where('status', $f->estado);
        }

        if ($conDesglose && $f->servicio !== null) {
            $consulta->where('service', $f->servicio);
        }

        if ($f->empresa !== null) {
            // El grupo afecta a la empresa si tiene fila en el desglose. `company_id` del grupo es solo la
            // última que lo sufrió: filtrar por él escondería los errores que además le pasaron a otras.
            $conDesglose
                ? $consulta->whereIn('id', DB::table('error_event_companies')->select('error_event_id')
                    ->where('company_id', $f->empresa))
                : $consulta->where('company_id', $f->empresa);
        }

        $this->dentroDeLaVentana($consulta, 'last_seen_at', $f);

        if ($f->busca !== null) {
            $patron = BusquedaTexto::patron($f->busca);
            $columnas = $conDesglose ? [...self::COLUMNAS_DE_ERROR, 'route_name', 'service'] : self::COLUMNAS_DE_ERROR;

            $consulta->where(function (Builder $q) use ($patron, $columnas, $conDesglose): void {
                foreach ($columnas as $columna) {
                    $q->orWhereRaw($this->comoTexto($columna), [$patron]);
                }

                if ($conDesglose) {
                    // A quién afectó: por su desglose, no por la última empresa o usuario que lo sufrió.
                    $q->orWhereIn('id', DB::table('error_event_companies')->select('error_event_id')
                        ->whereIn('company_id', $this->empresasQue($patron)))
                        ->orWhereIn('id', DB::table('error_event_users')->select('error_event_id')
                            ->whereIn('user_id', $this->usuariosQue($patron)));

                    return;
                }

                $q->orWhereIn('company_id', $this->empresasQue($patron))
                    ->orWhereIn('user_id', $this->usuariosQue($patron));
            });
        }

        match (true) {
            $f->orden === 'frecuentes' => $consulta->orderByDesc('hits'),
            $f->orden === 'empresas' && $conDesglose => $consulta->orderByDesc('companies_count'),
            default => null,
        };

        return $consulta
            ->orderByDesc('last_seen_at')
            ->orderByDesc('id')
            ->cursorPaginate(self::POR_PAGINA_ERRORES, ['*'], 'err')
            ->withQueryString();
    }

    /**
     * La auditoría: quién creó, cambió o borró qué.
     *
     * @return CursorPaginator<int, Audit>
     */
    public function actividad(MonitoringFilters $f): CursorPaginator
    {
        // Con `user` cargado de golpe: en la vista se pinta el nombre de quien actuó en cada fila, y sin
        // esto eran veinticinco consultas más por cada carga de la pestaña.
        $consulta = Audit::query()->with(['company', 'user']);

        if ($f->empresa !== null) {
            $consulta->where('company_id', $f->empresa);
        }

        if ($f->accion !== null) {
            $consulta->where('event', $f->accion);
        }

        $this->dentroDeLaVentana($consulta, 'created_at', $f);

        if ($f->busca !== null) {
            $patron = BusquedaTexto::patron($f->busca);

            $consulta->where(function (Builder $q) use ($patron, $f): void {
                foreach (self::COLUMNAS_DE_AUDITORIA as $columna) {
                    $q->orWhereRaw($this->comoTexto($columna), [$patron]);
                }

                // `ip_address` es `inet` en PostgreSQL y `lower(inet)` no existe: se pasa a texto antes.
                $q->orWhereRaw($this->comoTexto('cast(ip_address as text)'), [$patron])
                    ->orWhereIn('user_id', $this->usuariosQue($patron))
                    ->orWhereIn('company_id', $this->empresasQue($patron));

                // «Venta #123»: un número suelto también puede ser el id de lo que se tocó.
                if (ctype_digit($f->busca ?? '') && strlen((string) $f->busca) <= 15) {
                    $q->orWhere('auditable_id', (int) $f->busca);
                }
            });
        }

        return $consulta
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->cursorPaginate(self::POR_PAGINA_ACTIVIDAD, ['*'], 'act')
            ->withQueryString();
    }

    /**
     * «La columna, en minúsculas, contiene el patrón». Sirve igual para un nombre de columna que para
     * una expresión ya escrita (`cast(context as text)`): `lower()` envuelve cualquiera de las dos.
     *
     * Con `lower()` y no con `ilike`: `ilike` solo existe en PostgreSQL y los tests corren en SQLite, y una
     * consulta que solo se puede probar en producción no está probada. El `escape '!'` es el que hace
     * que el comodín escrito por el usuario sea texto ({@see BusquedaTexto::ESCAPE}).
     */
    private function comoTexto(string $columna): string
    {
        return 'lower('.$columna.') like ?'.BusquedaTexto::ESCAPE;
    }

    /** Los usuarios cuyo nombre o correo contiene el texto. Va como subconsulta, no como `join`. */
    private function usuariosQue(string $patron): ConsultaBase
    {
        return DB::table('users')->select('id')->where(function (ConsultaBase $q) use ($patron): void {
            $q->orWhereRaw($this->comoTexto('name'), [$patron])
                ->orWhereRaw($this->comoTexto('email'), [$patron]);
        });
    }

    /** Las empresas cuyo nombre contiene el texto. */
    private function empresasQue(string $patron): ConsultaBase
    {
        return DB::table('companies')->select('id')->whereRaw($this->comoTexto('name'), [$patron]);
    }

    /**
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $consulta
     */
    private function dentroDeLaVentana(Builder $consulta, string $columna, MonitoringFilters $f): void
    {
        if ($f->ventana !== null) {
            $consulta->where($columna, '>=', now()->subDays($f->ventana));
        }
    }

    /**
     * Un listado vacío sin tocar la base de datos.
     *
     * @return CursorPaginator<int, never>
     */
    private function vacio(int $porPagina, string $nombreDelCursor): CursorPaginator
    {
        return new PaginadorVacio(collect(), $porPagina, null, ['cursorName' => $nombreDelCursor]);
    }
}
