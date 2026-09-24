<?php

declare(strict_types=1);

namespace App\Modules\Core\Monitoring\Queries;

use App\Modules\Core\Monitoring\Errors\MessageNormalizer;
use App\Modules\Core\Support\DbTable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Guarda una consulta lenta en `slow_queries`, agrupada por su PATRÓN (el SQL sin valores), no por
 * ejecución. Mismo patrón UPDATE-primero-INSERT-si-no-existía que `ErrorRecorder`, `IncidentService`
 * y `DatabaseSink`: dos consultas lentas casi a la vez no pueden crear dos filas para el mismo
 * patrón, una vuelta de SELECT-antes-de-decidir sí podría.
 *
 * NUNCA lanza: una consulta lenta de más no puede tumbar la petición que la disparó.
 */
final class SlowQueryStore
{
    public function anotar(string $sqlCrudo, float $ms, string $ruta, ?int $companyId): void
    {
        if (! DbTable::existe('slow_queries')) {
            return;
        }

        try {
            $this->guardar($sqlCrudo, $ms, $ruta, $companyId);
        } catch (Throwable) {
            // Ver cabecera.
        }
    }

    private function guardar(string $sqlCrudo, float $ms, string $ruta, ?int $companyId): void
    {
        // La MISMA normalización que la huella de errores (Fase 1a): sin valores, con las listas
        // `in (?, ?, ?)` plegadas en una sola. Dos consultas iguales con ids distintos son un patrón.
        $muestra = MessageNormalizer::normalizeSql($sqlCrudo, 1000);
        $fingerprint = hash('sha256', $muestra);
        $duracion = (int) round($ms);
        $rutaCorta = mb_substr($ruta, 0, 150);

        $suma = [
            'hits' => DB::raw('hits + 1'),
            'total_ms' => DB::raw("total_ms + {$duracion}"),
            'max_ms' => DB::raw("CASE WHEN max_ms > {$duracion} THEN max_ms ELSE {$duracion} END"),
            'last_ms' => $duracion,
            'last_route' => $rutaCorta,
            'last_company_id' => $companyId,
            'last_seen_at' => now(),
            'updated_at' => now(),
        ];

        $actualizadas = DB::table('slow_queries')->where('fingerprint', $fingerprint)->update($suma);

        if ($actualizadas > 0) {
            return;
        }

        try {
            DB::table('slow_queries')->insert([
                'fingerprint' => $fingerprint,
                'sql_sample' => $muestra,
                'hits' => 1,
                'total_ms' => $duracion,
                'max_ms' => $duracion,
                'last_ms' => $duracion,
                'last_route' => $rutaCorta,
                'last_company_id' => $companyId,
                'first_seen_at' => now(),
                'last_seen_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (Throwable) {
            // Otro proceso creó el mismo patrón entre el UPDATE y el INSERT: se suma al suyo.
            DB::table('slow_queries')->where('fingerprint', $fingerprint)->update($suma);
        }
    }

    /**
     * Las más repetidas, para la sección BD de «Rendimiento». Por `hits` y no por `total_ms`: una
     * consulta rápida que corre diez mil veces pesa más en la base que una lenta que corre una vez.
     *
     * @return Collection<int, object>
     */
    public function masFrecuentes(int $limite = 10): Collection
    {
        if (! DbTable::existe('slow_queries')) {
            return collect();
        }

        return DB::table('slow_queries')->orderByDesc('hits')->limit($limite)->get();
    }
}
