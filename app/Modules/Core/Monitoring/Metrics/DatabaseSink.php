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

        $clave = [
            'kind' => $observacion->kind,
            'bucket_start' => $bucketStart,
            'name' => mb_substr($observacion->name, 0, 150),
            // NULL no vale en la clave única (ver la migración): '' representa «sin método».
            'method' => $observacion->method ?? '',
            'company_id' => $observacion->companyId ?? 0,
        ];

        $suma = [
            'total' => DB::raw('total + 1'),
            'warnings' => $observacion->isWarning ? DB::raw('warnings + 1') : DB::raw('warnings'),
            'errors' => $observacion->isError ? DB::raw('errors + 1') : DB::raw('errors'),
            'sum_ms' => DB::raw("sum_ms + {$duracion}"),
            'max_ms' => DB::raw("CASE WHEN max_ms > {$duracion} THEN max_ms ELSE {$duracion} END"),
            $columnaTramo => DB::raw("{$columnaTramo} + 1"),
            'updated_at' => now(),
        ];

        $sumadas = DB::table('metric_buckets')->where($clave)->update($suma);

        if ($sumadas > 0) {
            return;
        }

        try {
            DB::table('metric_buckets')->insert($clave + [
                'module' => $observacion->module,
                'total' => 1,
                'warnings' => $observacion->isWarning ? 1 : 0,
                'errors' => $observacion->isError ? 1 : 0,
                'sum_ms' => $duracion,
                'max_ms' => $duracion,
                $columnaTramo => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (Throwable) {
            // Otro proceso creó el mismo bucket entre el UPDATE y el INSERT: se suma a la suya.
            DB::table('metric_buckets')->where($clave)->update($suma);
        }
    }
}
