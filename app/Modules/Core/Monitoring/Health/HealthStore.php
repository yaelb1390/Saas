<?php

declare(strict_types=1);

namespace App\Modules\Core\Monitoring\Health;

use Illuminate\Support\Facades\DB;

/**
 * Guarda el resultado de una sonda: «cómo está ahora» en `health_checks`, y una fila más del
 * histórico en `health_check_results`.
 *
 * Los contadores de racha (`consecutive_failures`/`consecutive_successes`) solo se mueven con
 * UNHEALTHY/HEALTHY. Un `degraded` no rompe una racha de fallos —sigue sin ir bien— pero tampoco
 * cuenta como uno nuevo; y `unknown` (sin configurar) no es ni una cosa ni la otra, así que ambos se
 * quedan como estaban: no hay servicio que esté fallando si nunca se le ha preguntado nada.
 */
final class HealthStore
{
    public const TRIGGER_CRON = 'cron';

    public const TRIGGER_MANUAL = 'manual';

    public const TRIGGER_PANEL = 'panel';

    /**
     * @return array{consecutive_failures: int, consecutive_successes: int}
     */
    public function guardar(string $servicio, HealthResult $resultado, string $trigger): array
    {
        $ahora = now();
        $anterior = DB::table('health_checks')->where('service', $servicio)->first();

        [$fallos, $exitos] = match ($resultado->status) {
            HealthStatus::UNHEALTHY => [(int) ($anterior->consecutive_failures ?? 0) + 1, 0],
            HealthStatus::HEALTHY => [0, (int) ($anterior->consecutive_successes ?? 0) + 1],
            default => [(int) ($anterior->consecutive_failures ?? 0), (int) ($anterior->consecutive_successes ?? 0)],
        };

        DB::table('health_checks')->updateOrInsert(
            ['service' => $servicio],
            [
                'status' => $resultado->status,
                'configured' => $resultado->configured,
                'available' => $resultado->available,
                'latency_ms' => $resultado->latencyMs,
                'message' => $resultado->message,
                'last_error' => $resultado->lastError,
                'last_checked_at' => $ahora,
                'last_success_at' => $resultado->status === HealthStatus::HEALTHY ? $ahora : ($anterior->last_success_at ?? null),
                'last_failure_at' => $resultado->status === HealthStatus::UNHEALTHY ? $ahora : ($anterior->last_failure_at ?? null),
                'consecutive_failures' => $fallos,
                'consecutive_successes' => $exitos,
                'details' => $resultado->details === [] ? null : json_encode($resultado->details),
                'updated_at' => $ahora,
                'created_at' => $anterior->created_at ?? $ahora,
            ],
        );

        DB::table('health_check_results')->insert([
            'service' => $servicio,
            'status' => $resultado->status,
            'latency_ms' => $resultado->latencyMs,
            'message' => $resultado->message,
            'trigger' => $trigger,
            'created_at' => $ahora,
        ]);

        return ['consecutive_failures' => $fallos, 'consecutive_successes' => $exitos];
    }
}
