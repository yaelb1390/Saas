<?php

declare(strict_types=1);

namespace App\Modules\Core\Monitoring\Metrics;

/**
 * A dónde va una observación. Hoy solo hay una implementación (`DatabaseSink`, no hay Redis en
 * producción); la interfaz existe para que el día que lo haya —`RedisSink`, más barato de escribir
 * en cada petición— nada de lo que llama a esto tenga que cambiar.
 */
interface MetricsSink
{
    public function record(Observation $observacion): void;
}
