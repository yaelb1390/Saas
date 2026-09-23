<?php

declare(strict_types=1);

namespace App\Modules\Core\Http\Controllers;

use App\Modules\Core\Models\Company;
use App\Modules\Core\Models\ErrorEvent;
use App\Modules\Core\Monitoring\MonitoringSchema;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * El detalle de UN grupo de errores: a qué empresas y usuarios afectó, y qué hacer con él.
 *
 * Va aparte de `MonitoringController` porque responde a una pregunta distinta. Aquella pantalla dice
 * «esto se está rompiendo»; esta dice «esto en concreto, ¿a quién le pasó y ya se atendió?».
 */
final class MonitoringErrorController extends Controller
{
    /** Las acciones que se pueden hacer sobre un grupo, y a qué estado lo dejan. */
    private const ACCIONES = [
        'resolve' => ErrorEvent::RESUELTO,
        'ignore' => ErrorEvent::IGNORADO,
        'reopen' => ErrorEvent::ACTIVO,
    ];

    public function show(ErrorEvent $errorEvent): View
    {
        $errorEvent->loadMissing('company');

        $conDesglose = MonitoringSchema::erroresConDesglose();

        return view('panel.admin.monitoring-error', [
            'error' => $errorEvent,
            'conDesglose' => $conDesglose,
            /*
             * El desglose por empresa, con el nombre ya cargado en la misma consulta: sin esto, una
             * fila por empresa son N consultas más solo para saber cómo se llama cada una.
             */
            'empresas' => $conDesglose
                ? $errorEvent->companies()->with('company')->orderByDesc('hits')->get()
                : collect(),
            /*
             * Hasta diez usuarios. Un error de una campaña de bot mal configurada puede afectar a
             * cientos; una lista de cientos de nombres no informa más que las diez primeras, solo
             * pesa más.
             */
            'usuarios' => $conDesglose
                ? $errorEvent->users()->with('user')->orderByDesc('hits')->limit(10)->get()
                : collect(),
            'usuariosTotal' => $conDesglose ? $errorEvent->users()->count() : 0,
            // Lo que no se pudo atribuir a ninguna empresa: la consola, un job sin tenant, un
            // visitante sin sesión. No es un fallo del cálculo, es información real.
            'hitsSinEmpresa' => $conDesglose ? $errorEvent->hitsSinEmpresa() : null,
        ]);
    }

    /**
     * Cambia el estado del grupo: lo resuelve, lo ignora o lo reabre.
     *
     * Sin el desglose aplicado todavía no hay columna `status` que cambiar: se avisa en vez de fallar.
     */
    public function status(Request $request, ErrorEvent $errorEvent): RedirectResponse
    {
        if (! MonitoringSchema::erroresConDesglose()) {
            return back()->with('panel_error', 'Esta acción necesita la migración de errores multiempresa, todavía sin aplicar.');
        }

        $datos = $request->validate([
            'accion' => ['required', Rule::in(array_keys(self::ACCIONES))],
        ]);

        $accion = (string) $datos['accion'];
        $estado = self::ACCIONES[$accion];

        if ($accion === 'resolve' || $accion === 'ignore') {
            $errorEvent->update([
                'status' => $estado,
                'resolved_at' => now(),
                'resolved_by' => $request->user()?->id,
            ]);
        } else {
            $errorEvent->update(['status' => $estado, 'resolved_at' => null, 'resolved_by' => null]);
        }

        return back()->with('panel_ok', match ($accion) {
            'resolve' => 'Marcado como resuelto.',
            'ignore' => 'Marcado como ignorado.',
            default => 'Reabierto.',
        });
    }
}
