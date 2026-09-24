<?php

declare(strict_types=1);

namespace App\Modules\Core\Monitoring\Metrics;

use App\Modules\Core\Support\DbTable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Guarda una observación en `metric_buckets`, agregada por hora.
 *
 * UN SOLO `upsert` por observación: primero intenta SUMAR sobre la fila del bucket (hora, nombre,
 * método, empresa) que ya exista; si no existía, la crea. Es el mismo patrón de todo el monitoreo
 * (`ErrorRecorder`, `IncidentService`) y por el mismo motivo: dos observaciones casi a la vez no
 * pueden crear dos filas para el mismo bucket, una round-trip de SELECT-antes-de-decidir sí podría.
 *
 * NUNCA lanza: una métrica de más no puede tumbar la petición, el trabajo o la consulta que mide.
 */
final class DatabaseSink implements MetricsSink
{
    public function record(Observation $observacion): void
    {
        if (! DbTable::existe('metric_buckets')) {
            return;
        }

        try {
            $this->guardar($observacion);
        } catch (Throwable) {
            // Ver cabecera: de más, no crítico.
        }
    }

    private function guardar(Observation $observacion): void
    {
        $bucketStart = now()->startOfHour();
        $columnaTramo = Histogram::columna($observacion->durationMs);
        $duracion = (int) round($observacion->durationMs);
        // Nunca menor que 1: una observación siempre representa, como mínimo, a sí misma.
        $peso = max(1, $observacion->weight);

        $clave = [
            'kind' => $observacion->kind,
            'bucket_start' => $bucketStart,
            'name' => mb_substr($observacion->name, 0, 150),
            // NULL no vale en la clave única (ver la migración): '' representa «sin método».
            'method' => $observacion->method ?? '',
            'company_id' => $observacion->companyId ?? 0,
        ];

        $suma = [
            'total' => DB::raw("total + {$peso}"),
            'warnings' => $observacion->isWarning ? DB::raw("warnings + {$peso}") : DB::raw('warnings'),
            'errors' => $observacion->isError ? DB::raw("errors + {$peso}") : DB::raw('errors'),
            'sum_ms' => DB::raw('sum_ms + '.($duracion * $peso)),
            // El máximo NUNCA se multiplica por el peso: es la duración más larga vista, no una suma.
            'max_ms' => DB::raw("CASE WHEN max_ms > {$duracion} THEN max_ms ELSE {$duracion} END"),
            $columnaTramo => DB::raw("{$columnaTramo} + {$peso}"),
            'updated_at' => now(),
        ];

        $sumadas = DB::table('metric_buckets')->where($clave)->update($suma);

        if ($sumadas > 0) {
            return;
        }

        try {
            DB::table('metric_buckets')->insert($clave + [
                'module' => $observacion->module,
                'total' => $peso,
                'warnings' => $observacion->isWarning ? $peso : 0,
                'errors' => $observacion->isError ? $peso : 0,
                'sum_ms' => $duracion * $peso,
                'max_ms' => $duracion,
                $columnaTramo => $peso,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (Throwable) {
            // Otro proceso creó el mismo bucket entre el UPDATE y el INSERT: se suma a la suya.
            DB::table('metric_buckets')->where($clave)->update($suma);
        }
    }
}
