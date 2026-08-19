<?php

declare(strict_types=1);

namespace App\Modules\AI\Providers;

use App\Modules\AI\Listeners\AnalyzeIncomingMessageSentiment;
use App\Modules\AI\Models\AiSetting;
use App\Modules\AI\Providers\Contracts\AiProvider;
use App\Modules\WhatsApp\Events\WhatsAppMessageReceived;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Throwable;

final class AiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        /*
         * SINGLETON, y a propósito: la clave es de la PLATAFORMA, no de cada empresa, así que el
         * proveedor no depende del inquilino activo. Si algún día se guardara por empresa, esto
         * tiene que bajar a `scoped`: con un singleton, la primera empresa que resolviera el
         * proveedor se lo impondría a las siguientes dentro de la misma petición.
         */
        $this->app->singleton(AiProvider::class, fn (): AiProvider => $this->resolver());
    }

    /**
     * Elige el proveedor: primero lo que haya guardado en la base, y si no, el entorno.
     *
     * La base manda porque es lo que se puede cambiar desde el panel. El entorno sigue valiendo de
     * respaldo para no romper una instalación que ya tenga sus variables puestas.
     */
    private function resolver(): AiProvider
    {
        $ajustes = $this->ajustes();

        if ($ajustes !== null && $ajustes->configurado()) {
            return $this->desdeLaBase($ajustes);
        }

        return $this->desdeElEntorno();
    }

    /**
     * Los ajustes guardados, o null si todavía no se puede leer la tabla.
     *
     * Se traga cualquier fallo a propósito: este proveedor se registra en CADA petición, incluidas
     * las que corren antes de que exista la tabla —`migrate` sobre una base vacía, por ejemplo—. Un
     * error aquí dejaría la aplicación sin arrancar por no poder elegir un proveedor de IA.
     */
    private function ajustes(): ?AiSetting
    {
        try {
            return AiSetting::query()->first();
        } catch (Throwable) {
            return null;
        }
    }

    private function desdeLaBase(AiSetting $ajustes): AiProvider
    {
        $comun = [
            'api_key' => $ajustes->api_key,
            'chat_model' => $ajustes->chat_model,
            'embedding_model' => $ajustes->embedding_model,
            'embedding_dimensions' => $ajustes->embedding_dimensions,
        ];

        return match ($ajustes->provider) {
            'gemini' => new GeminiProvider($comun),
            'openai' => new OpenAiProvider($comun + [
                'base_url' => (string) config('ai.providers.openai.base_url'),
            ]),
            'anthropic', 'claude' => new AnthropicProvider($comun + [
                'base_url' => (string) config('ai.providers.anthropic.base_url'),
                'version' => (string) config('ai.providers.anthropic.version'),
            ]),
            default => new LocalAiProvider((int) $ajustes->embedding_dimensions),
        };
    }

    private function desdeElEntorno(): AiProvider
    {
        $default = (string) config('ai.default');
        $dimensions = (int) config('ai.embedding_dimensions', 128);

        /** @var array<string, array<string, mixed>> $providers */
        $providers = config('ai.providers');

        // «claude» es sinónimo de «anthropic»: es como se llama el modelo y es lo que documenta
        // .env.example, así que era lo que la gente escribía. Al no reconocerlo, caía al proveedor
        // local AUNQUE la clave de Anthropic estuviera puesta, y la IA se quedaba muda sin decir
        // por qué. Se acepta aquí para arreglar de paso las instalaciones que ya lo tengan así.
        return match ($default) {
            'gemini' => empty($providers['gemini']['api_key'])
                ? new LocalAiProvider($dimensions)
                : new GeminiProvider($providers['gemini']),
            'openai' => empty($providers['openai']['api_key'])
                ? new LocalAiProvider($dimensions)
                : new OpenAiProvider($providers['openai']),
            'anthropic', 'claude' => empty($providers['anthropic']['api_key'])
                ? new LocalAiProvider($dimensions)
                : new AnthropicProvider($providers['anthropic']),
            default => new LocalAiProvider($dimensions),
        };
    }

    public function boot(): void
    {
        // La IA reacciona a los mensajes entrantes de WhatsApp para clasificar su sentimiento.
        Event::listen(WhatsAppMessageReceived::class, AnalyzeIncomingMessageSentiment::class);
    }
}
