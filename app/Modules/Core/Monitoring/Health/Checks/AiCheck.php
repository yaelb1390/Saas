<?php

declare(strict_types=1);

namespace App\Modules\Core\Monitoring\Health\Checks;

use App\Modules\AI\Models\AiSetting;
use App\Modules\Core\Monitoring\Health\HealthCheck;
use App\Modules\Core\Monitoring\Health\HealthResult;
use App\Modules\Core\Support\SecretRedactor;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * ¿Responde el proveedor de IA configurado? Con el que hay puesto en el panel (`AiSetting::actual()`),
 * no con lo que haya en el entorno: es lo que de verdad usa el asistente ahora mismo.
 *
 * LISTA MODELOS, nunca genera nada. `AiSettingsController::probar()` ya hace una llamada de verdad
 * para que el operador vea que contesta bien —y eso cuesta tokens a propósito, una vez, cuando
 * alguien lo pide—; esta sonda puede correr cada cinco minutos sola, así que gastar tokens en cada
 * pasada sería pagar por comprobar que se puede pagar.
 *
 * El proveedor `local` no habla con nadie: «no aplica», igual que Redis sin nadie que lo use.
 */
final class AiCheck implements HealthCheck
{
    public function key(): string
    {
        return 'ai';
    }

    public function label(): string
    {
        return 'Inteligencia Artificial';
    }

    public function run(): HealthResult
    {
        $ajustes = AiSetting::query()->first();

        if ($ajustes === null || ! $ajustes->configurado()) {
            return HealthResult::sinConfigurar();
        }

        $inicio = microtime(true);

        try {
            $respuesta = match ($ajustes->provider) {
                'openai' => Http::withToken((string) $ajustes->api_key)
                    ->timeout(4)
                    ->get(rtrim((string) config('ai.providers.openai.base_url'), '/').'/models'),
                'gemini' => Http::withHeaders(['x-goog-api-key' => (string) $ajustes->api_key])
                    ->timeout(4)
                    ->get('https://generativelanguage.googleapis.com/v1beta/models', ['pageSize' => 1]),
                'anthropic', 'claude' => Http::withHeaders([
                    'x-api-key' => (string) $ajustes->api_key,
                    'anthropic-version' => (string) config('ai.providers.anthropic.version'),
                ])
                    ->timeout(4)
                    ->get(rtrim((string) config('ai.providers.anthropic.base_url'), '/').'/models'),
                default => null,
            };
        } catch (Throwable $e) {
            return HealthResult::caido(SecretRedactor::redact($e->getMessage()));
        }

        if ($respuesta === null) {
            return HealthResult::sinConfigurar("Proveedor «{$ajustes->provider}» sin sonda propia");
        }

        $latencia = (int) round((microtime(true) - $inicio) * 1000);

        return $this->interpretar($respuesta, $latencia, (string) $ajustes->provider);
    }

    private function interpretar(Response $respuesta, int $latencia, string $proveedor): HealthResult
    {
        if ($respuesta->status() === 401 || $respuesta->status() === 403) {
            return HealthResult::caido('La clave de API no es válida (401/403)', $latencia);
        }

        if ($respuesta->status() === 404) {
            return HealthResult::degradado($latencia, 'La ruta de modelos respondió 404: puede haber cambiado', ['proveedor' => $proveedor]);
        }

        if ($respuesta->status() === 429) {
            return HealthResult::degradado($latencia, 'Límite de peticiones alcanzado (429)', ['proveedor' => $proveedor]);
        }

        if (! $respuesta->successful()) {
            return HealthResult::caido("Respuesta inesperada: {$respuesta->status()}", $latencia);
        }

        return HealthResult::sano($latencia, details: ['proveedor' => $proveedor]);
    }
}
