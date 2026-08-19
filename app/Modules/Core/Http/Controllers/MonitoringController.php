<?php

declare(strict_types=1);

namespace App\Modules\Core\Http\Controllers;

use App\Modules\Core\Models\Audit;
use App\Modules\Core\Models\Company;
use App\Modules\Core\Models\ErrorEvent;
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

    public function __invoke(PlatformHealthService $salud): View
    {
        return view('panel.admin.monitoring', [
            'salud' => $salud->resumen(),
            'webhooks' => $salud->webhooksSinResolver(),
            'actividad' => $this->actividad(),
            'errores' => ErrorEvent::query()->with('company')->latest('last_seen_at')->limit(15)->get(),
            'empresas' => Company::query()->orderBy('name')->get(['id', 'name']),
            'acciones' => self::ACCIONES,
            'filtros' => [
                'empresa' => request('empresa'),
                'accion' => request('accion'),
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
     * Borra el rastro de más de un año.
     *
     * Va aquí y no en una tarea programada del sistema porque en producción no hay cron propio: se
     * llama desde el mismo sitio que las demás, con su clave compartida.
     */
    public function limpiar(): RedirectResponse
    {
        $borradas = Audit::query()->where('created_at', '<', now()->subYear())->delete();

        return back()->with('panel_ok', $borradas > 0
            ? "Se borraron {$borradas} registros de más de un año."
            : 'No había nada de más de un año.');
    }
}
