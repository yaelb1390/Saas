<?php

declare(strict_types=1);

namespace App\Modules\AI\Providers;

use App\Modules\AI\Listeners\AnalyzeIncomingMessageSentiment;
use App\Modules\AI\Providers\Contracts\AiProvider;
use App\Modules\WhatsApp\Events\WhatsAppMessageReceived;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

final class AiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AiProvider::class, function (): AiProvider {
            $default = (string) config('ai.default');
            $dimensions = (int) config('ai.embedding_dimensions', 128);

            /** @var array<string, array<string, mixed>> $providers */
            $providers = config('ai.providers');

            // «claude» es sinónimo de «anthropic»: es como se llama el modelo y es lo que documenta
            // .env.example, así que era lo que la gente escribía. Al no reconocerlo, caía al proveedor
            // local AUNQUE la clave de Anthropic estuviera puesta, y la IA se quedaba muda sin decir
            // por qué. Se acepta aquí para arreglar de paso las instalaciones que ya lo tengan así.
            return match ($default) {
                'openai' => empty($providers['openai']['api_key'])
                    ? new LocalAiProvider($dimensions)
                    : new OpenAiProvider($providers['openai']),
                'anthropic', 'claude' => empty($providers['anthropic']['api_key'])
                    ? new LocalAiProvider($dimensions)
                    : new AnthropicProvider($providers['anthropic']),
                default => new LocalAiProvider($dimensions),
            };
        });
    }

    public function boot(): void
    {
        // La IA reacciona a los mensajes entrantes de WhatsApp para clasificar su sentimiento.
        Event::listen(WhatsAppMessageReceived::class, AnalyzeIncomingMessageSentiment::class);
    }
}
