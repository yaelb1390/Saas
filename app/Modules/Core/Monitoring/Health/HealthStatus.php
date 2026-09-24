<?php

declare(strict_types=1);

namespace App\Modules\Core\Monitoring\Health;

/**
 * Los cuatro estados de un servicio. Texto corto, como el resto del monitoreo (`ErrorEvent::ACTIVO`,
 * `Incident::OPEN`…) y no un enum nativo: es la convención ya sentada en todo este módulo.
 */
final class HealthStatus
{
    /** Respondió bien, dentro de lo esperado. */
    public const HEALTHY = 'healthy';

    /** Respondió, pero con avisos: lento, o un código que no es un fallo pero tampoco es normal. */
    public const DEGRADED = 'degraded';

    /** No respondió, o respondió con un fallo. */
    public const UNHEALTHY = 'unhealthy';

    /** Sin credencial puesta: no se puede saber. «No se sabe» no es lo mismo que «mal». */
    public const UNKNOWN = 'unknown';

    public const TODOS = [self::HEALTHY, self::DEGRADED, self::UNHEALTHY, self::UNKNOWN];
}
