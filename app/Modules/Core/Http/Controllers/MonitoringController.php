<?php

declare(strict_types=1);

namespace App\Modules\Core\Http\Controllers;

use App\Modules\Core\Models\Audit;
use App\Modules\Core\Models\Company;
use App\Modules\Core\Models\ErrorEvent;
use App\Modules\Core\Models\SystemEvent;
use App\Modules\Core\Monitoring\Counters\MonitoringCounters;
use App\Modules\Core\Monitoring\Errors\ServiceResolver;
use App\Modules\Core\Monitoring\MonitoringSchema;
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
    ];

    public function __invoke(
        Request $request,
        PlatformHealthService $salud,
        CompanyHealthService $empresas,
        MonitoringCounters $contadores,
        MonitoringSearch $buscador,
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
            'actividad' => $filtros->pestana === 'actividad' ? $buscador->actividad($filtros) : $this->vacio(),
            'erroresResumen' => $this->erroresParaElResumen(),
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
        ]);
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
