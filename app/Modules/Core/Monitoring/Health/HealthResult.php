<?php

declare(strict_types=1);

namespace App\Modules\Core\Monitoring\Health;

/**
 * Lo que devuelve UNA sonda al comprobar UN servicio, una sola vez.
 *
 * Es un objeto de valor: lo construye la sonda, lo lee `HealthStore` para guardarlo y `HealthAggregator`
 * para decidir el estado general. Ninguno de los dos vuelve a preguntarle nada al servicio externo.
 */
final class HealthResult
{
    /**
     * @param  array<string, mixed>  $details  Lo propio de esta sonda (p. ej. si el webhook de Polar
     *                                          está configurado). Ya saneado: nunca una clave ni un token.
     */
    private function __construct(
        public readonly string $status,
        public readonly bool $configured,
        public readonly bool $available,
        public readonly ?int $latencyMs,
        public readonly ?string $message,
        public readonly ?string $lastError,
        public readonly array $details = [],
    ) {}

    /** Sin credencial: no se intentó nada. */
    public static function sinConfigurar(string $mensaje = 'Sin configurar'): self
    {
        return new self(HealthStatus::UNKNOWN, configured: false, available: false, latencyMs: null, message: $mensaje, lastError: null);
    }

    /**
     * @param  array<string, mixed>  $details
     */
    public static function sano(int $latencyMs, ?string $mensaje = null, array $details = []): self
    {
        return new self(HealthStatus::HEALTHY, configured: true, available: true, latencyMs: $latencyMs, message: $mensaje, lastError: null, details: $details);
    }

    /**
     * @param  array<string, mixed>  $details
     */
    public static function degradado(?int $latencyMs, string $mensaje, array $details = []): self
    {
        return new self(HealthStatus::DEGRADED, configured: true, available: true, latencyMs: $latencyMs, message: $mensaje, lastError: null, details: $details);
    }

    /** Configurado pero no responde, o responde mal. `$error` YA saneado por quien lo construye. */
    public static function caido(string $error, ?int $latencyMs = null): self
    {
        return new self(HealthStatus::UNHEALTHY, configured: true, available: false, latencyMs: $latencyMs, message: null, lastError: $error);
    }
}
