<?php

declare(strict_types=1);

namespace App\Modules\Core\Monitoring\Metrics;

/**
 * Una medición: esto tardó tanto, y así salió. Es lo que le llega a un `MetricsSink`; quien la
 * arma decide qué significa cada campo para su tipo (para un trabajo, `method` es la cola en la que
 * corrió; para una petición HTTP —Fase 5— es el verbo).
 */
final class Observation
{
    public const HTTP = 'http';

    public const JOB = 'job';

    public const DB = 'db';

    public function __construct(
        public readonly string $kind,
        public readonly string $name,
        public readonly ?string $method,
        public readonly ?string $module,
        public readonly ?int $companyId,
        public readonly float $durationMs,
        public readonly bool $isWarning = false,
        public readonly bool $isError = false,
        // Muestreo por estratos (Fase 5): una observación normal, sacada 1 de cada N, vale por las
        // N que no se guardaron. Un 5xx o una lenta siempre pesan 1 —esas se guardan TODAS—.
        public readonly int $weight = 1,
    ) {}
}
