<?php

declare(strict_types=1);

namespace App\Modules\Core\Http\Controllers;

use App\Modules\Core\Models\Incident;
use App\Modules\Core\Monitoring\Errors\ServiceResolver;
use App\Modules\Core\Monitoring\Incidents\IncidentService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;

/**
 * El detalle de UN incidente: qué se sabe de él, a quién afectó y en qué quedó.
 *
 * Misma idea que `MonitoringErrorController` con los errores: la pestaña «Incidentes» dice «esto
 * está pasando (o pasó)»; esta pantalla dice «esto en concreto, desde cuándo, a quién y qué se hizo».
 */
final class MonitoringIncidentController extends Controller
{
    public function show(Incident $incident): View
    {
        $incident->loadMissing('links');

        return view('panel.admin.monitoring-incident', [
            'incidente' => $incident,
            'empresas' => $incident->companies()->with('company')->orderByDesc('hits')->get(),
            'servicios' => ServiceResolver::NOMBRES,
        ]);
    }

    /**
     * Cambia el estado: lo investiga, lo resuelve, lo ignora o lo reabre.
     */
    public function status(Request $request, Incident $incident, IncidentService $incidentes): RedirectResponse
    {
        $datos = $request->validate([
            'accion' => ['required', Rule::in(array_keys(IncidentService::ACCIONES))],
        ]);

        $incidentes->cambiarEstado($incident, (string) $datos['accion'], $request->user()?->id);

        return back()->with('panel_ok', match ($datos['accion']) {
            'investigate' => 'Marcado como en investigación.',
            'resolve' => 'Marcado como resuelto.',
            'ignore' => 'Marcado como ignorado.',
            default => 'Reabierto.',
        });
    }
}
