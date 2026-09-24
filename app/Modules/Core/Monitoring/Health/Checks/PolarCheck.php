<?php

declare(strict_types=1);

namespace App\Modules\Core\Monitoring\Health\Checks;

use App\Modules\Core\Monitoring\Health\HealthCheck;
use App\Modules\Core\Monitoring\Health\HealthResult;
use App\Modules\Core\Services\PolarClient;
use App\Modules\Core\Support\PolarSignature;
use App\Modules\Core\Support\SecretRedactor;
use Throwable;

/**
 * ¿Responde Polar? Reutiliza `PolarClient`, la misma puerta que ya usan los cobros de verdad: mismo
 * token, mismo entorno (sandbox o producción), así que un «bien» aquí es un «bien» real y no de una
 * URL aparte que podría estar configurada distinto.
 *
 * `GET /v1/products/?limit=1`: el catálogo, con el mínimo posible. No crea ni cobra nada.
 */
final class PolarCheck implements HealthCheck
{
    public function __construct(private readonly PolarClient $polar) {}

    public function key(): string
    {
        return 'polar';
    }

    public function label(): string
    {
        return 'Cobros (Polar)';
    }

    public function run(): HealthResult
    {
        if (! $this->polar->isConfigured()) {
            return HealthResult::sinConfigurar();
        }

        $inicio = microtime(true);

        try {
            $respuesta = $this->polar->http()->timeout(4)->get($this->polar->url('/v1/products/?limit=1'));
        } catch (Throwable $e) {
            return HealthResult::caido(SecretRedactor::redact($e->getMessage()));
        }

        $latencia = (int) round((microtime(true) - $inicio) * 1000);
        $webhookConfigurado = PolarSignature::fromConfig()->isConfigured();

        if ($respuesta->status() === 403) {
            // El token es válido pero le falta ámbito de lectura de productos: no es lo mismo que
            // estar caído, y decir «caído» llevaría a rotar un token que en realidad funciona para
            // cobrar, solo que no para este catálogo.
            return HealthResult::degradado($latencia, 'El token no tiene ámbito de lectura de productos (403)', ['webhook_configurado' => $webhookConfigurado]);
        }

        if ($respuesta->status() === 401) {
            return HealthResult::caido('El token no es válido (401)', $latencia);
        }

        if (! $respuesta->successful()) {
            return HealthResult::caido("Respuesta inesperada: {$respuesta->status()}", $latencia);
        }

        return HealthResult::sano($latencia, details: ['webhook_configurado' => $webhookConfigurado]);
    }
}
