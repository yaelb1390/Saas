<?php

declare(strict_types=1);

namespace App\Modules\AI\Providers;

use App\Modules\AI\Providers\Contracts\AiProvider;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Proveedor OpenAI (embeddings + chat). Se usa cuando OPENAI_API_KEY está configurada.
 */
final class OpenAiProvider implements AiProvider
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(private readonly array $config) {}

    public function embed(string $text): array
    {
        $response = $this->client()
            ->post('/embeddings', [
                'model' => (string) $this->config['embedding_model'],
                'input' => $text,
            ])
            ->throw();

        return array_map('floatval', $response->json('data.0.embedding', []));
    }

    public function chat(array $messages): string
    {
        $response = $this->client()
            ->post('/chat/completions', [
                'model' => (string) $this->config['chat_model'],
                'messages' => $messages,
            ])
            ->throw();

        return (string) $response->json('choices.0.message.content', '');
    }

    public function classifySentiment(string $text): array
    {
        $answer = $this->chat([
            ['role' => 'system', 'content' => 'Clasifica el sentimiento del mensaje. Responde SOLO una palabra: positive, neutral o negative.'],
            ['role' => 'user', 'content' => $text],
        ]);

        $sentiment = strtolower(trim($answer));
        $sentiment = in_array($sentiment, ['positive', 'neutral', 'negative'], true) ? $sentiment : 'neutral';

        return ['sentiment' => $sentiment, 'score' => 0.0];
    }

    private function client(): PendingRequest
    {
        return Http::withToken((string) $this->config['api_key'])
            ->acceptJson()
            ->baseUrl(rtrim((string) $this->config['base_url'], '/'));
    }
}
