<?php

declare(strict_types=1);

namespace App\Modules\Core\Monitoring\Incidents;

use App\Modules\Core\Models\ErrorEvent;
use App\Modules\Core\Models\Incident;
use App\Modules\Core\Models\IncidentLink;
use App\Modules\Core\Support\DbTable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * ¿Este error, por sí solo, es ya un incidente?
 *
 * Dos disparadores, cada uno con su ventana de configuración (`config('bmos.monitoreo.incidentes')`):
 *
 *  · RACHA: el mismo grupo de errores se repite `racha_umbral` veces (25 de fábrica) en los últimos
 *    `racha_minutos` (15). Lo mide `error_events.recent_hits`, que `ErrorRecorder` ya mantiene al día
 *    en la misma escritura que suma el error: no hace falta una consulta aparte.
 *  · EMPRESAS: el mismo grupo afecta a `empresas_umbral` empresas (3) distintas en `empresas_minutos`
 *    (30). Se cuenta sobre `error_event_companies.last_seen_at`, así que empresas que lo sufrieron
 *    hace tiempo y no ahora no cuentan para esto (aunque sigan en el total histórico del grupo).
 *
 * Los dos apuntan a la MISMA clave de deduplicación (`error:{huella}`): da igual cuál de los dos
 * disparó, mientras el incidente siga activo el error solo suma ocurrencias sobre el mismo, no abre
 * uno nuevo por cada disparador que se cumpla.
 *
 * NUNCA lanza. Es una detección de más, no el registro del error: que falle no puede llevarse por
 * delante la escritura que sí importa (`ErrorRecorder::record`), que es quien la llama.
 */
final class IncidentDetector
{
    public function __construct(private readonly IncidentService $incidentes) {}

    public function evaluarError(ErrorEvent $grupo): void
    {
        try {
            $this->evaluar($grupo);
        } catch (Throwable) {
            // Detección de más: un fallo aquí no puede tumbar el registro de errores.
        }
    }

    private function evaluar(ErrorEvent $grupo): void
    {
        if (! DbTable::existe('incidents')) {
            return;
        }

        $config = config('bmos.monitoreo.incidentes');

        $porRacha = (int) $grupo->recent_hits >= (int) $config['racha_umbral'];
        $porEmpresas = $porRacha ? true : $this->empresasEnVentana($grupo->id, (int) $config['empresas_minutos'])
            >= (int) $config['empresas_umbral'];

        if (! $porRacha && ! $porEmpresas) {
            return;
        }

        $companyIds = DB::table('error_event_companies')
            ->where('error_event_id', $grupo->id)
            ->pluck('company_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $this->incidentes->detectarOAbrir(
            dedupeKey: "error:{$grupo->fingerprint}",
            titulo: class_basename($grupo->class).': '.Str::limit($grupo->message, 140),
            servicio: $grupo->service,
            severidad: $this->severidad($grupo, $porRacha, $porEmpresas),
            sourceType: IncidentLink::ERROR_EVENT,
            sourceId: $grupo->id,
            companyIds: $companyIds,
        );
    }

    /** Empresas DISTINTAS que sufrieron este grupo dentro de la ventana, no el total histórico. */
    private function empresasEnVentana(int $grupoId, int $minutos): int
    {
        return (int) DB::table('error_event_companies')
            ->where('error_event_id', $grupoId)
            ->where('last_seen_at', '>=', now()->subMinutes($minutos))
            ->count();
    }

    /**
     * Sin fórmula del plan: una racha o un reparto muy por encima del umbral (el doble) es `critical`;
     * cumplir el umbral sin más es `high`. Documentado aquí porque es la única decisión de este
     * archivo que no viene dada por los requisitos.
     */
    private function severidad(ErrorEvent $grupo, bool $porRacha, bool $porEmpresas): string
    {
        $config = config('bmos.monitoreo.incidentes');

        $rachaGrave = $porRacha && (int) $grupo->recent_hits >= (int) $config['racha_umbral'] * 2;
        $empresasGrave = $porEmpresas && $grupo->companies_count >= (int) $config['empresas_umbral'] * 2;

        return $rachaGrave || $empresasGrave ? Incident::CRITICAL : Incident::HIGH;
    }
}
