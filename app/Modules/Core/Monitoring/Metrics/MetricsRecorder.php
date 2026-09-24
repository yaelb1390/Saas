<?php

declare(strict_types=1);

namespace App\Modules\Core\Monitoring\Metrics;

/**
 * La puerta por la que todo lo demás anota una métrica, sin saber a dónde va a parar.
 *
 * Es la única clase de `Monitoring/Metrics` que el resto del código conoce (`QueueEventSubscriber`
 * hoy; el middleware HTTP de la Fase 5 y `QueryWatcher` de la Fase 6, después): arma la `Observation`
 * y se la pasa al `MetricsSink` que el contenedor tenga resuelto. Cambiar de sitio de guardado —hoy
 * `DatabaseSink`, mañana quizás `RedisSink`— es cambiar UN binding, no cada punto que mide algo.
 */
final class MetricsRecorder
{
    public function __construct(private readonly MetricsSink $sink) {}

    public function anotar(
        string $kind,
        string $name,
        ?string $method,
        ?string $module,
        ?int $companyId,
        float $durationMs,
        bool $isWarning = false,
        bool $isError = false,
    ): void {
        $this->sink->record(new Observation(
            kind: $kind,
            name: $name,
            method: $method,
            module: $module,
            companyId: $companyId,
            durationMs: $durationMs,
            isWarning: $isWarning,
            isError: $isError,
        ));
    }
}
