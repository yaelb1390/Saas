<?php

declare(strict_types=1);

namespace App\Modules\Core\Monitoring\Health\Checks;

use App\Modules\Core\Monitoring\Health\HealthCheck;
use App\Modules\Core\Monitoring\Health\HealthResult;
use App\Modules\Core\Support\SecretRedactor;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * ¿Responde Redis? Solo si ALGO lo usa de verdad: caché, cola o sesión. En producción (Vercel, plan
 * Hobby) NADA de eso es Redis —son `database` y `sync`—, así que ahí esta sonda dice «no aplica» y no
 * finge comprobar un servicio que la instalación ni siquiera tiene contratado.
 */
final class RedisCheck implements HealthCheck
{
    private const UMBRAL_DEGRADADO_MS = 100;

    public function key(): string
    {
        return 'redis';
    }

    public function label(): string
    {
        return 'Redis';
    }

    public function run(): HealthResult
    {
        if (! $this->loUsaAlgo()) {
            return HealthResult::sinConfigurar('No hay caché, cola ni sesión configuradas con Redis');
        }

        $inicio = microtime(true);

        try {
            Redis::connection()->ping();
        } catch (Throwable $e) {
            return HealthResult::caido(SecretRedactor::redact($e->getMessage()));
        }

        $latencia = (int) round((microtime(true) - $inicio) * 1000);

        return $latencia > self::UMBRAL_DEGRADADO_MS
            ? HealthResult::degradado($latencia, "Responde lento: {$latencia} ms")
            : HealthResult::sano($latencia);
    }

    private function loUsaAlgo(): bool
    {
        return config('cache.default') === 'redis'
            || config('queue.default') === 'redis'
            || config('session.driver') === 'redis';
    }
}
