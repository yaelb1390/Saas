<?php

declare(strict_types=1);

namespace App\Modules\Core\Monitoring\Metrics;

/**
 * Estima P50/P95/P99 a partir de un histograma, sin haber guardado ni una sola duración individual.
 *
 * Es una ESTIMACIÓN, no el valor exacto: dentro del tramo que contiene el percentil se interpola en
 * línea recta entre sus dos extremos, como si las observaciones de ese tramo se repartieran
 * uniformemente entre ellos. Con nueve tramos y miles de observaciones por hora, el error que mete
 * eso es pequeño comparado con lo que cuesta guardar cada duración para calcularlo exacto —y es
 * exactamente el mismo trato que cualquier sistema de métricas hace con un histograma de verdad—.
 */
final class Percentiles
{
    /**
     * @param  list<int>  $tramos  las nueve cuentas (`h0`..`h8`), en orden
     * @param  float  $percentil  entre 0 y 1 (0.95 para P95)
     */
    public static function estimar(array $tramos, float $percentil): ?float
    {
        $total = array_sum($tramos);

        if ($total === 0) {
            return null;
        }

        $objetivo = $percentil * $total;
        $acumulado = 0;

        foreach ($tramos as $indice => $cuenta) {
            $desde = $acumulado;
            $acumulado += $cuenta;

            if ($acumulado < $objetivo || $cuenta === 0) {
                continue;
            }

            $inferior = $indice === 0 ? 0 : Histogram::LIMITES[$indice - 1];

            // El último tramo no tiene techo: no hay con qué interpolar, así que se informa su
            // suelo. Es deliberadamente conservador —el percentil real es AL MENOS ese—, nunca
            // inventa un número por encima de lo que se sabe.
            if ($indice === Histogram::TRAMOS - 1) {
                return (float) $inferior;
            }

            $superior = Histogram::LIMITES[$indice];
            $posicionEnElTramo = ($objetivo - $desde) / $cuenta;

            return $inferior + ($superior - $inferior) * $posicionEnElTramo;
        }

        return (float) Histogram::LIMITES[array_key_last(Histogram::LIMITES)];
    }
}
