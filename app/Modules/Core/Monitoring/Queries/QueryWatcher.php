<?php

declare(strict_types=1);

namespace App\Modules\Core\Monitoring\Queries;

use App\Modules\Core\Monitoring\Metrics\Histogram;
use App\Modules\Core\Monitoring\Metrics\MetricsRecorder;
use App\Modules\Core\Monitoring\Metrics\Observation;
use Illuminate\Database\Events\QueryExecuted;
use Throwable;

/**
 * Cuánto tarda la base de datos durante ESTA petición o ESTE trabajo: un `DB::listen` único que solo
 * hace aritmética (contador, suma, máximo, tramo del histograma) por cada consulta, sin escribir nada
 * a la base todavía —una escritura por consulta sería contar las consultas escribiendo más consultas—.
 *
 * Las lentas (≥ `umbral_ms`) se apartan CRUDAS en un búfer con tope, y todo se vacía de una sola vez
 * en `vaciar()`: ahí, y solo ahí, se normaliza el SQL y se escribe —en `metric_buckets` (kind `db`,
 * mismo patrón de peso por tramo que ya usa la Fase 5 para el muestreo HTTP) y en `slow_queries`—.
 *
 * SINGLETON de la Fase 4 en adelante: en una petición normal (un contenedor por petición) esto no
 * importa, pero `queue:work` reutiliza el MISMO proceso para varios trabajos seguidos, así que
 * `vaciar()` reinicia el estado al terminar —si no, las consultas del trabajo N se sumarían a las
 * del N+1—.
 *
 * GUARDA DE REENTRADA: mientras `vaciar()` escribe, sus propias consultas (el UPDATE/INSERT de
 * `metric_buckets` y `slow_queries`) pasan por este MISMO listener. Sin la guarda, cada vaciado se
 * contaría a sí mismo y el próximo vaciado arrancaría con un residuo que no es tráfico real.
 */
final class QueryWatcher
{
    /** Cuántas consultas lentas crudas se guardan como mucho por vaciado: una petición patológica con
     *  miles de consultas lentas no puede convertirse en miles de normalizaciones. */
    private const TOPE_LENTAS = 20;

    private int $contador = 0;

    private float $sumaMs = 0.0;

    private float $maxMs = 0.0;

    /** @var array<int, int> nueve tramos, `h0`..`h8` por posición */
    private array $tramos = [0, 0, 0, 0, 0, 0, 0, 0, 0];

    /** @var list<array{sql: string, ms: float}> */
    private array $lentas = [];

    private bool $procesando = false;

    public function __construct(
        private readonly MetricsRecorder $metricas,
        private readonly SlowQueryStore $lentasStore,
    ) {}

    public function observar(QueryExecuted $evento): void
    {
        if ($this->procesando || ! (bool) config('bmos.monitoreo.consultas.activo', true)) {
            return;
        }

        $ms = (float) $evento->time;

        $this->contador++;
        $this->sumaMs += $ms;
        $this->maxMs = max($this->maxMs, $ms);
        $this->tramos[Histogram::tramo($ms)]++;

        $umbral = (int) config('bmos.monitoreo.consultas.umbral_ms', 500);

        if ($ms >= $umbral && count($this->lentas) < self::TOPE_LENTAS) {
            $this->lentas[] = ['sql' => $evento->sql, 'ms' => $ms];
        }
    }

    /**
     * Escribe lo acumulado y reinicia el estado. `$nombre` es el mismo endpoint/trabajo que ya
     * calculó quien llama (la ruta HTTP o la clase del trabajo): así una fila `kind=db` se puede leer
     * junto a su `kind=http`/`kind=job` sin volver a resolver nada.
     */
    public function vaciar(string $nombre, ?string $modulo, ?int $companyId): void
    {
        if ($this->contador === 0) {
            return;
        }

        $this->procesando = true;

        try {
            $this->escribirAgregado($nombre, $modulo, $companyId);

            foreach ($this->lentas as $lenta) {
                $this->lentasStore->anotar($lenta['sql'], $lenta['ms'], $nombre, $companyId);
            }
        } catch (Throwable) {
            // De más, no crítico: ver la cabecera.
        } finally {
            $this->contador = 0;
            $this->sumaMs = 0.0;
            $this->maxMs = 0.0;
            $this->tramos = [0, 0, 0, 0, 0, 0, 0, 0, 0];
            $this->lentas = [];
            $this->procesando = false;
        }
    }

    /**
     * Una llamada a `MetricsRecorder::anotar()` por cada tramo no vacío, con el CONTEO de ese tramo
     * como peso (el mismo muestreo por estratos de la Fase 5, reutilizado: aquí el «muestreo» es
     * perfecto, cada consulta pesa exactamente 1). El tramo que contiene la duración MÁXIMA real lleva
     * esa duración exacta —para que `max_ms` no se pierda—; el resto, cualquier punto dentro de su
     * propio tramo, porque lo único que `DatabaseSink` necesita de `durationMs` es a qué tramo
     * pertenece (`sum_ms` de un tramo que no es el más alto no se enseña en ninguna pantalla).
     */
    private function escribirAgregado(string $nombre, ?string $modulo, ?int $companyId): void
    {
        $indiceMax = Histogram::tramo($this->maxMs);

        foreach ($this->tramos as $indice => $conteo) {
            if ($conteo === 0) {
                continue;
            }

            $duracion = $indice === $indiceMax
                ? $this->maxMs
                : (float) ($indice === 0 ? 0 : Histogram::LIMITES[$indice - 1]);

            $this->metricas->anotar(
                kind: Observation::DB,
                name: $nombre,
                method: null,
                module: $modulo,
                companyId: $companyId,
                durationMs: $duracion,
                weight: $conteo,
            );
        }
    }
}
