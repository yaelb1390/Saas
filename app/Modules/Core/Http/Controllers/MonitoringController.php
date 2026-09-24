<?php

declare(strict_types=1);

namespace App\Modules\Core\Http\Controllers;

use App\Modules\Core\Models\Audit;
use App\Modules\Core\Models\Company;
use App\Modules\Core\Models\ErrorEvent;
use App\Modules\Core\Models\Incident;
use App\Modules\Core\Models\SystemEvent;
use App\Modules\Core\Monitoring\Counters\MonitoringCounters;
use App\Modules\Core\Monitoring\Errors\ServiceResolver;
use App\Modules\Core\Monitoring\Health\HealthRegistry;
use App\Modules\Core\Monitoring\Health\HealthStatus;
use App\Modules\Core\Monitoring\Incidents\IncidentService;
use App\Modules\Core\Monitoring\Metrics\Percentiles;
use App\Modules\Core\Monitoring\MonitoringSchema;
use App\Modules\Core\Monitoring\Queues\QueueMonitor;
use App\Modules\Core\Monitoring\Search\MonitoringFilters;
use App\Modules\Core\Monitoring\Search\MonitoringSearch;
use App\Modules\Core\Services\CompanyHealthService;
use App\Modules\Core\Services\PlatformHealthService;
use App\Modules\Core\Support\DbTable;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\CursorPaginator as PaginadorVacio;
use Illuminate\Routing\Controller;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Monitoreo de la plataforma: qué está pasando, quién lo hizo y qué se está rompiendo.
 *
 * Todo lo de aquí mira a TODAS las empresas. Un super administrador siempre tiene una empresa activa
 * —`SetCurrentCompany` lo fija a la de su sesión o a la primera por id—, así que las consultas van
 * sin el ámbito de empresa a propósito. Sin eso, esta pantalla enseñaría los datos de una sola
 * empresa haciéndolos pasar por los de la plataforma, sin dar error.
 *
 * ES DELGADO A PROPÓSITO: solo pide al buscador la lista de la PESTAÑA ACTIVA. Antes se cargaban las
 * cuatro listas en cada carga —el registro, los errores, la actividad y las treinta empresas— aunque
 * solo una se fuera a ver; con el registro creciendo cada día, era trabajo tirado en tres de cada
 * cuatro peticiones.
 */
final class MonitoringController extends Controller
{
    /** Las acciones que registra la auditoría, en español. */
    private const ACCIONES = [
        'created' => 'creó',
        'updated' => 'modificó',
        'deleted' => 'eliminó',
        'restored' => 'restauró',
    ];

    /**
     * Familias del registro del sistema, para el filtro.
     *
     * Se agrupa por PREFIJO y no se listan los cuarenta tipos: al operador le interesa «los accesos»
     * o «lo que se rompió por fuera», no distinguir `auth.login` de `auth.logout` en un desplegable.
     *
     * `subscription` y `mail` se añadieron en la Fase 1b: ya se escribían sucesos con esos prefijos
     * —altas, bajas, correos de prueba— y no tenían familia con la que filtrarlos.
     */
    private const FAMILIAS = [
        'auth' => 'Accesos',
        'integration' => 'Servicios externos',
        'platform' => 'Acciones de plataforma',
        'subscription' => 'Suscripciones',
        'mail' => 'Correo',
        'task' => 'Tareas programadas',
        'webhook' => 'Webhooks',
        // Fase 2: cuando se abre o cambia de estado un incidente.
        'incident' => 'Incidentes',
        // Fase 4: un trabajo que falló o que tardó de más.
        'queue' => 'Colas',
    ];

    public function __invoke(
        Request $request,
        PlatformHealthService $salud,
        CompanyHealthService $empresas,
        MonitoringCounters $contadores,
        MonitoringSearch $buscador,
        IncidentService $incidentes,
        HealthRegistry $registroDeSalud,
        QueueMonitor $colas,
    ): View {
        $filtros = MonitoringFilters::fromRequest($request, self::FAMILIAS, self::ACCIONES);

        // Las treinta empresas completas, con lo que le pasa a cada una, SOLO hacen falta si esa es la
        // pestaña abierta. El Resumen solo necesita el total y cuántas tienen algún problema, que ya
        // vienen resueltos y en caché en `$salud` y `$contadores`.
        $saludEmpresas = $filtros->pestana === 'empresas' ? $empresas->porEmpresa() : collect();

        return view('panel.admin.monitoring', [
            'f' => $filtros,
            'contadores' => $contadores->calcular(),
            'salud_empresas' => $saludEmpresas,
            'avisos' => $empresas->resumenDeAvisos(),
            'registro' => $filtros->pestana === 'registro' ? $buscador->registro($filtros) : $this->vacio(),
            'errores' => $filtros->pestana === 'errores' ? $buscador->errores($filtros) : $this->vacio(),
            'incidentes' => $filtros->pestana === 'incidentes' ? $incidentes->listar($filtros) : $this->vacio(),
            'actividad' => $filtros->pestana === 'actividad' ? $buscador->actividad($filtros) : $this->vacio(),
            'erroresResumen' => $this->erroresParaElResumen(),
            'incidentesResumen' => $this->incidentesParaElResumen(),
            'sucesosResumen' => $this->sucesosParaElResumen(),
            'familias' => self::FAMILIAS,
            'servicios' => ServiceResolver::NOMBRES,
            // Qué de lo nuevo hay ya en esta base. Sin esto la vista no puede decidir si ofrecer el
            // filtro de servicio o el estado de un error sin arriesgarse a preguntar por una columna
            // que todavía no existe (el código sale antes que las migraciones, que aquí son manuales).
            'conServicioEnRegistro' => MonitoringSchema::sucesosConServicio(),
            'conDesgloseDeErrores' => MonitoringSchema::erroresConDesglose(),
            'salud' => $salud->resumen(),
            'pulso' => $salud->pulso(),
            'webhooks' => $salud->webhooksSinResolver(),
            'empresas' => Company::query()->orderBy('name')->get(['id', 'name']),
            'acciones' => self::ACCIONES,
            'saludServicios' => $filtros->pestana === 'servicios' ? $this->saludServicios($registroDeSalud) : collect(),
            'colas' => $filtros->pestana === 'servicios' ? $this->colasDetalle($colas) : null,
        ]);
    }

    /**
     * El detalle de la cola para la pestaña «Servicios»: pendientes/reservados/retrasados, los P95
     * por cola de las últimas 24 h (de `metric_buckets`, que `QueueEventSubscriber` alimenta), y los
     * últimos fallos.
     *
     * @return array{snapshot: array<string, mixed>, p95_por_cola: Collection<int, array{cola: string, p95_ms: float|null, total: int}>, fallidos: Collection<int, object>}
     */
    private function colasDetalle(QueueMonitor $colas): array
    {
        return [
            'snapshot' => $colas->snapshot(),
            'p95_por_cola' => $this->p95PorCola(),
            'fallidos' => $colas->fallidosRecientes(10),
        ];
    }

    /**
     * @return Collection<int, array{cola: string, p95_ms: float|null, total: int}>
     */
    private function p95PorCola(): Collection
    {
        if (! DbTable::existe('metric_buckets')) {
            return collect();
        }

        return DB::table('metric_buckets')
            ->where('kind', 'job')
            ->where('bucket_start', '>=', now()->subDay())
            ->selectRaw('method as cola, sum(total) as total, sum(h0) h0, sum(h1) h1, sum(h2) h2, sum(h3) h3, sum(h4) h4, sum(h5) h5, sum(h6) h6, sum(h7) h7, sum(h8) h8')
            ->groupBy('method')
            ->get()
            ->map(fn (object $fila): array => [
                'cola' => $fila->cola !== '' ? $fila->cola : 'default',
                'total' => (int) $fila->total,
                'p95_ms' => Percentiles::estimar(
                    [(int) $fila->h0, (int) $fila->h1, (int) $fila->h2, (int) $fila->h3, (int) $fila->h4, (int) $fila->h5, (int) $fila->h6, (int) $fila->h7, (int) $fila->h8],
                    0.95,
                ),
            ]);
    }

    /**
     * Las sondas de salud (Fase 3), con lo último que se supo de cada una. Una fila por sonda del
     * registro, aunque `health_checks` todavía no tenga nada de ella —recién migrada, antes del
     * primer cron— o la tabla ni siquiera exista: en los dos casos sale «unknown», nunca un hueco.
     *
     * @return Collection<int, array{clave: string, etiqueta: string, fila: object|null}>
     */
    private function saludServicios(HealthRegistry $registro): Collection
    {
        $filas = DbTable::existe('health_checks')
            ? DB::table('health_checks')->get()->keyBy('service')
            : collect();

        return $registro->todas()->map(fn ($sonda): array => [
            'clave' => $sonda->key(),
            'etiqueta' => $sonda->label(),
            'fila' => $filas->get($sonda->key()),
        ])->values();
    }

    /**
     * Los cinco errores activos más recientes, para la tarjeta del Resumen. Es un adelanto, no la
     * lista: el listado completo, con filtros y estado, vive en la pestaña «Errores».
     *
     * @return Collection<int, ErrorEvent>
     */
    private function erroresParaElResumen(): Collection
    {
        if (! DbTable::existe('error_events')) {
            return collect();
        }

        return ErrorEvent::query()
            ->with('company')
            ->when(DbTable::tieneColumna('error_events', 'status'), fn ($q) => $q->where('status', ErrorEvent::ACTIVO))
            ->latest('last_seen_at')
            ->limit(5)
            ->get();
    }

    /**
     * Los cinco incidentes activos más recientes, para el Resumen. Misma idea que los errores.
     *
     * @return Collection<int, Incident>
     */
    private function incidentesParaElResumen(): Collection
    {
        if (! DbTable::existe('incidents')) {
            return collect();
        }

        return Incident::query()->activos()->latest('last_detected_at')->limit(5)->get();
    }

    /**
     * Los últimos sucesos, para el Resumen. Misma idea que los errores: un adelanto de ocho filas,
     * no la lista completa del registro.
     *
     * @return Collection<int, SystemEvent>
     */
    private function sucesosParaElResumen(): Collection
    {
        if (! DbTable::existe('system_events')) {
            return collect();
        }

        return SystemEvent::query()->with('company')->latest('created_at')->limit(8)->get();
    }

    /**
     * Un listado vacío, para la pestaña que NO está abierta: la vista sigue pudiendo preguntar
     * `->items()`, `->isNotEmpty()` o `->links()` sin comprobar antes si se pidió esa pestaña.
     *
     * @return CursorPaginator<int, never>
     */
    private function vacio(): CursorPaginator
    {
        return new PaginadorVacio(collect(), 1);
    }

    /**
     * Borra el rastro de más de un año.
     *
     * Va aquí y no en una tarea programada del sistema porque en producción no hay cron propio: se
     * llama desde el mismo sitio que las demás, con su clave compartida.
     */
    public function limpiar(): RedirectResponse
    {
        $limite = now()->subYear();

        $borradas = Audit::query()->where('created_at', '<', $limite)->delete()
            + (DbTable::existe('system_events')
                ? SystemEvent::query()->where('created_at', '<', $limite)->delete()
                : 0);

        return back()->with('panel_ok', $borradas > 0
            ? "Se borraron {$borradas} registros de más de un año."
            : 'No había nada de más de un año.');
    }
}
