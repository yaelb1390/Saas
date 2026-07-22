<?php

declare(strict_types=1);

namespace App\Modules\AI\Providers;

use App\Modules\AI\Providers\Contracts\AiProvider;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Proveedor Claude (Anthropic) para chat y clasificación. Anthropic no ofrece embeddings, por
 * lo que embed() no está soportado: para RAG usa OpenAI o el proveedor local.
 */
final class AnthropicProvider implements AiProvider
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(private readonly array $config) {}

    public function embed(string $text): array
    {
        throw new RuntimeException('Anthropic no provee embeddings; configura OpenAI o el proveedor local para RAG.');
    }

    public function chat(array $messages): string
    {
        $system = '';
        $chat = [];

        foreach ($messages as $message) {
            if ($message['role'] === 'system') {
                $system = $message['content'];

                continue;
            }
            $chat[] = ['role' => $message['role'], 'content' => $message['content']];
        }

        $response = Http::withHeaders([
            'x-api-key' => (string) $this->config['api_key'],
            'anthropic-version' => (string) $this->config['version'],
        ])
            ->acceptJson()
            ->baseUrl(rtrim((string) $this->config['base_url'], '/'))
            ->post('/messages', array_filter([
                'model' => (string) $this->config['chat_model'],
                'max_tokens' => 1024,
                'system' => $system !== '' ? $system : null,
                'messages' => $chat,
            ]))
            ->throw();

        return (string) $response->json('content.0.text', '');
    }

    public function classifySentiment(string $text): array
    {
        $answer = $this->chat([
            ['role' => 'system', 'content' => 'Clasifica el sentimiento. Responde SOLO: positive, neutral o negative.'],
            ['role' => 'user', 'content' => $text],
        ]);

        $sentiment = strtolower(trim($answer));
        $sentiment = in_array($sentiment, ['positive', 'neutral', 'negative'], true) ? $sentiment : 'neutral';

        return ['sentiment' => $sentiment, 'score' => 0.0];
    }
}
