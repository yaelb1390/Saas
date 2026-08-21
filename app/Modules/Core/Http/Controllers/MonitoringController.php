<?php

declare(strict_types=1);

namespace App\Modules\Core\Http\Controllers;

use App\Modules\Core\Models\Audit;
use App\Modules\Core\Models\Company;
use App\Modules\Core\Models\ErrorEvent;
use App\Modules\Core\Models\SystemEvent;
use App\Modules\Core\Services\PlatformHealthService;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;

/**
 * Monitoreo de la plataforma: qué está pasando, quién lo hizo y qué se está rompiendo.
 *
 * Todo lo de aquí mira a TODAS las empresas. Un super administrador siempre tiene una empresa activa
 * —`SetCurrentCompany` lo fija a la de su sesión o a la primera por id—, así que las consultas van
 * sin el ámbito de empresa a propósito. Sin eso, esta pantalla enseñaría los datos de una sola
 * empresa haciéndolos pasar por los de la plataforma, sin dar error.
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
     */
    private const FAMILIAS = [
        'auth' => 'Accesos',
        'integration' => 'Servicios externos',
        'platform' => 'Acciones de plataforma',
        'task' => 'Tareas programadas',
        'webhook' => 'Webhooks',
    ];

    public function __invoke(PlatformHealthService $salud): View
    {
        return view('panel.admin.monitoring', [
            'registro' => $this->registro(),
            'familias' => self::FAMILIAS,
            'salud' => $salud->resumen(),
            'webhooks' => $salud->webhooksSinResolver(),
            'actividad' => $this->actividad(),
            'errores' => ErrorEvent::query()->with('company')->latest('last_seen_at')->limit(15)->get(),
            'empresas' => Company::query()->orderBy('name')->get(['id', 'name']),
            'acciones' => self::ACCIONES,
            'filtros' => [
                'empresa' => request('empresa'),
                'accion' => request('accion'),
                'familia' => request('familia'),
                'nivel' => request('nivel'),
                'busca' => request('busca'),
            ],
        ]);
    }

    /**
     * La actividad reciente, filtrable.
     *
     * Paginación por CURSOR y no la normal: esta tabla crece sin parar y `paginate()` lanza un
     * `count(*)` de la tabla entera en cada carga solo para saber cuántas páginas hay.
     */
    private function actividad(): CursorPaginator
    {
        return Audit::query()
            ->with('company')
            ->when(request('empresa'), fn ($q, $id) => $q->where('company_id', $id))
            ->when(
                request('accion') && array_key_exists((string) request('accion'), self::ACCIONES),
                fn ($q) => $q->where('event', request('accion')),
            )
            ->latest('created_at')
            ->cursorPaginate(25)
            ->withQueryString();
    }

    /**
     * El registro del sistema, filtrable.
     *
     * Es lo que NO se ve en ningún otro sitio: quién entró, quién lo intentó sin conseguirlo, qué
     * servicio externo falló y qué tocó el operador. La auditoría de al lado cuenta quién cambió una
     * fila; esto cuenta todo lo demás.
     *
     * Paginación por cursor, igual que la actividad y por el mismo motivo: la tabla crece cada día y
     * `paginate()` haría un `count(*)` entero en cada carga.
     */
    private function registro(): CursorPaginator
    {
        return SystemEvent::query()
            ->with(['company', 'user'])
            ->when(request('empresa'), fn ($q, $id) => $q->where('company_id', $id))
            ->when(
                request('familia') && array_key_exists((string) request('familia'), self::FAMILIAS),
                // Por prefijo: `auth` trae `auth.login`, `auth.failed` y los que se añadan mañana sin
                // tener que tocar el filtro.
                fn ($q) => $q->where('type', 'like', request('familia').'.%'),
            )
            ->when(
                in_array(request('nivel'), [SystemEvent::INFO, SystemEvent::AVISO, SystemEvent::GRAVE], true),
                fn ($q) => $q->where('level', request('nivel')),
            )
            // Buscar por texto: sirve para «¿quién intentó entrar con este correo?», que es la
            // pregunta que se hace cuando algo huele mal.
            ->when(request('busca'), fn ($q, $texto) => $q->where('message', 'like', '%'.$texto.'%'))
            ->latest('created_at')
            ->cursorPaginate(30, ['*'], 'reg')
            ->withQueryString();
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
            + SystemEvent::query()->where('created_at', '<', $limite)->delete();

        return back()->with('panel_ok', $borradas > 0
            ? "Se borraron {$borradas} registros de más de un año."
            : 'No había nada de más de un año.');
    }
}
