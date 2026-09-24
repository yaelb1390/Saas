<?php

declare(strict_types=1);

namespace App\Modules\Core\Monitoring\Metrics;

/**
 * Los nueve tramos fijos que usan las tres métricas (HTTP, trabajos, consultas): a qué tramo
 * pertenece una duración, y nada más. No guarda estado; es aritmética pura para que `DatabaseSink`
 * y `Percentiles` compartan la MISMA regla sin poder desincronizarse.
 *
 * Los límites son en milisegundos y sirven igual de mal —a propósito— para los tres tipos: una
 * consulta de PostgreSQL rara vez pasa de 500 ms, un trabajo de WhatsApp puede tardar dos minutos.
 * Un histograma por tipo daría tramos más finos, pero son TRES tablas y tres cálculos de percentil
 * que mantener en vez de uno; con nueve tramos hasta los tres minutos, lo que interesa de cada tipo
 * —lento para lo que es— se sigue viendo.
 */
final class Histogram
{
    /**
     * Los ocho cortes que separan los nueve tramos (`h0`..`h8`), en milisegundos.
     *
     * h0 <100 · h1 100-300 · h2 300ms-1s · h3 1-3s · h4 3-10s · h5 10-30s · h6 30-60s ·
     * h7 1-3min · h8 ≥3min.
     *
     * @var list<int>
     */
    public const LIMITES = [100, 300, 1_000, 3_000, 10_000, 30_000, 60_000, 180_000];

    public const TRAMOS = 9;

    /** A qué tramo (0..8) pertenece esta duración. */
    public static function tramo(float $ms): int
    {
        foreach (self::LIMITES as $indice => $limite) {
            if ($ms < $limite) {
                return $indice;
            }
        }

        return self::TRAMOS - 1;
    }

    /** El nombre de columna (`h0`..`h8`) para esta duración. */
    public static function columna(float $ms): string
    {
        return 'h'.self::tramo($ms);
    }
}
