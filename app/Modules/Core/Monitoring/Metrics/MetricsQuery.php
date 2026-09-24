<?php

declare(strict_types=1);

namespace App\Modules\Core\Monitoring\Metrics;

use App\Modules\Core\Support\DbTable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Lo que la pestaña «Rendimiento» necesita leer de `metric_buckets`, ya resuelto: percentiles,
 * desglose por módulo y los endpoints más lentos. Nada de esto escribe; `MetricsRecorder` es quien
 * anota, esta clase solo agrega lo que ya está guardado.
 *
 * Siempre sobre `kind = 'http'`: las consultas de trabajos (Fase 4) y de PostgreSQL (Fase 6) leen la
 * misma tabla, pero por su propio camino —no le compete a esta clase mezclarlas—.
 */
final class MetricsQuery
{
    private const COLUMNAS_TRAMO = ['h0', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'h7', 'h8'];

    /**
     * El resumen de TODA la aplicación: requests, tasa de error y P50/P95/P99.
     *
     * @return array{requests: int, errores: int, tasa_error: float, p50: float|null, p95: float|null, p99: float|null}
     */
    public function resumenApp(int $dias, ?int $companyId = null): array
    {
        $vacio = ['requests' => 0, 'errores' => 0, 'tasa_error' => 0.0, 'p50' => null, 'p95' => null, 'p99' => null];

        if (! DbTable::existe('metric_buckets')) {
            return $vacio;
        }

        $fila = $this->baseQuery($dias, $companyId)
            ->selectRaw($this->seleccionAgregada())
            ->first();

        if ($fila === null || (int) $fila->total === 0) {
            return $vacio;
        }

        $tramos = $this->tramosDe($fila);
        $requests = (int) $fila->total;
        $errores = (int) $fila->errores;

        return [
            'requests' => $requests,
            'errores' => $errores,
            'tasa_error' => $requests > 0 ? round(($errores / $requests) * 100, 2) : 0.0,
            'p50' => Percentiles::estimar($tramos, 0.50),
            'p95' => Percentiles::estimar($tramos, 0.95),
            'p99' => Percentiles::estimar($tramos, 0.99),
        ];
    }

    /**
     * El mismo resumen, desglosado por módulo. De más a menos tráfico, que es lo que se quiere
     * mirar primero.
     *
     * @return Collection<int, array{modulo: string, requests: int, errores: int, p95: float|null}>
     */
    public function porModulo(int $dias, ?int $companyId = null): Collection
    {
        if (! DbTable::existe('metric_buckets')) {
            return collect();
        }

        return $this->baseQuery($dias, $companyId)
            ->selectRaw('coalesce(module, \'app\') as modulo, '.$this->seleccionAgregada())
            ->groupBy('modulo')
            ->orderByDesc('total')
            ->get()
            ->map(fn (object $fila): array => [
                'modulo' => $fila->modulo,
                'requests' => (int) $fila->total,
                'errores' => (int) $fila->errores,
                'p95' => Percentiles::estimar($this->tramosDe($fila), 0.95),
            ]);
    }

    /**
     * Los endpoints más lentos, con un mínimo de muestras: un P95 sobre tres peticiones no dice
     * nada, y sin este filtro un endpoint casi nunca visitado con una petición lenta de casualidad
     * encabezaría la lista todos los días.
     *
     * @return Collection<int, array{endpoint: string, metodo: string, requests: int, p95: float|null}>
     */
    public function endpointsLentos(int $dias, int $limite = 10, ?int $companyId = null): Collection
    {
        if (! DbTable::existe('metric_buckets')) {
            return collect();
        }

        $minimo = max(1, (int) config('bmos.monitoreo.metricas_http.muestras_minimas', 20));

        $filas = $this->baseQuery($dias, $companyId)
            ->selectRaw('name as endpoint, method as metodo, '.$this->seleccionAgregada())
            ->groupBy('name', 'method')
            ->havingRaw('sum(total) >= ?', [$minimo])
            ->get()
            ->map(fn (object $fila): array => [
                'endpoint' => $fila->endpoint,
                'metodo' => $fila->metodo !== '' ? $fila->metodo : null,
                'requests' => (int) $fila->total,
                'p95' => Percentiles::estimar($this->tramosDe($fila), 0.95),
            ]);

        return $filas
            ->sortByDesc(fn (array $f): float => $f['p95'] ?? 0.0)
            ->take($limite)
            ->values();
    }

    private function baseQuery(int $dias, ?int $companyId)
    {
        return DB::table('metric_buckets')
            ->where('kind', 'http')
            ->where('bucket_start', '>=', now()->subDays($dias))
            ->when($companyId !== null, fn ($q) => $q->where('company_id', $companyId));
    }

    private function seleccionAgregada(): string
    {
        $tramos = implode(', ', array_map(fn (string $c): string => "sum({$c}) as {$c}", self::COLUMNAS_TRAMO));

        return "sum(total) as total, sum(errors) as errores, {$tramos}";
    }

    /** @return list<int> */
    private function tramosDe(object $fila): array
    {
        return array_map(fn (string $c): int => (int) $fila->{$c}, self::COLUMNAS_TRAMO);
    }
}
