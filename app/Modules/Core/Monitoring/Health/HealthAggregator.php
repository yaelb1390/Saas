<?php

declare(strict_types=1);

namespace App\Modules\Core\Monitoring\Health;

use App\Modules\Core\Models\Incident;
use App\Modules\Core\Support\DbTable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * El estado general de la plataforma, en una sola palabra: reutiliza el mismo vocabulario que un
 * servicio (`HealthStatus`) — `unhealthy` es «todo caído» (DOWN), `degraded` es «algo no va bien»,
 * `healthy` es «todo bien», `unknown` es «todavía no se ha comprobado nada»—.
 *
 * Solo la base de datos es CRÍTICA para este cálculo: sin ella no hay plataforma. Que Evolution, la
 * IA, Polar o el correo estén caídos es un problema real, pero no significa que BMIA esté caída — un
 * dueño de colmado sigue pudiendo cobrar en el mostrador aunque el bot de WhatsApp no conteste.
 *
 * UN DATO VIEJO NUNCA MARCA `unhealthy` POR SÍ SOLO: si el cron dejó de correr, lo último que se supo
 * de la base podía ser «bien» hace tres días, y decir DOWN con eso sería mentir con datos viejos. Sí
 * cuenta para `degraded`: no se sabe si está bien, y eso ya es peor que saber que sí lo está.
 */
final class HealthAggregator
{
    private const CRITICOS = ['database'];

    private const DATO_RECIENTE_MIN = 15;

    public function estado(): string
    {
        if (! DbTable::existe('health_checks')) {
            return HealthStatus::UNKNOWN;
        }

        $filas = DB::table('health_checks')->get();

        if ($filas->isEmpty()) {
            return HealthStatus::UNKNOWN;
        }

        $corte = now()->subMinutes(self::DATO_RECIENTE_MIN);

        $reciente = fn (object $fila): bool => $fila->last_checked_at !== null
            && Carbon::parse($fila->last_checked_at)->gte($corte);

        $critica = fn (object $fila): bool => in_array($fila->service, self::CRITICOS, true);

        if ($filas->contains(fn (object $f): bool => $critica($f) && $f->status === HealthStatus::UNHEALTHY && $reciente($f))
            || $this->hayIncidenteCriticoAbierto()) {
            return HealthStatus::UNHEALTHY;
        }

        $hayDegradado = $filas->contains(fn (object $f): bool => in_array($f->status, [HealthStatus::UNHEALTHY, HealthStatus::DEGRADED], true));
        $datoCriticoViejo = $filas->contains(fn (object $f): bool => $critica($f) && ! $reciente($f));

        if ($hayDegradado || $datoCriticoViejo || $this->hayIncidenteAbierto()) {
            return HealthStatus::DEGRADED;
        }

        return HealthStatus::HEALTHY;
    }

    private function hayIncidenteAbierto(): bool
    {
        return DbTable::existe('incidents') && Incident::query()->activos()->exists();
    }

    private function hayIncidenteCriticoAbierto(): bool
    {
        return DbTable::existe('incidents') && Incident::query()->activos()->where('severity', Incident::CRITICAL)->exists();
    }
}
