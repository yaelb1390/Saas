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

            return match ($default) {
                'openai' => empty($providers['openai']['api_key'])
                    ? new LocalAiProvider($dimensions)
                    : new OpenAiProvider($providers['openai']),
                'anthropic' => empty($providers['anthropic']['api_key'])
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
