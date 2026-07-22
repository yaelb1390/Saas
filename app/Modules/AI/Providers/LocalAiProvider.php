<?php

declare(strict_types=1);

namespace App\Modules\AI\Providers;

use App\Modules\AI\Enums\Sentiment;
use App\Modules\AI\Providers\Contracts\AiProvider;

/**
 * Proveedor de IA local y determinista (sin red). Se usa por defecto cuando no hay API keys
 * configuradas y en los tests.
 *
 * - embed(): vector "bag-of-words" hasheado → la similitud coseno refleja solapamiento léxico.
 * - classifySentiment(): heurística por palabras clave en español.
 * - chat(): respuesta determinista que resume el contexto recibido.
 */
final class LocalAiProvider implements AiProvider
{
    /** @var array<int, string> */
    private const POSITIVE_WORDS = ['gracias', 'excelente', 'bueno', 'buena', 'genial', 'encanta', 'feliz', 'perfecto', 'rápido', 'recomiendo'];

    /** @var array<int, string> */
    private const NEGATIVE_WORDS = ['malo', 'mala', 'pésimo', 'terrible', 'horrible', 'molesto', 'enojado', 'queja', 'problema', 'lento', 'decepcionado'];

    public function __construct(private readonly int $dimensions = 128) {}

    public function embed(string $text): array
    {
        $vector = array_fill(0, $this->dimensions, 0.0);

        foreach ($this->tokenize($text) as $token) {
            $bucket = crc32($token) % $this->dimensions;
            $vector[$bucket] += 1.0;
        }

        return $vector;
    }

    public function chat(array $messages): string
    {
        $context = '';
        $question = '';

        foreach ($messages as $message) {
            if ($message['role'] === 'system') {
                $context = $message['content'];
            } elseif ($message['role'] === 'user') {
                $question = $message['content'];
            }
        }

        return trim("Respuesta (local) a: {$question}\nBasada en el contexto proporcionado.\n{$context}");
    }

    public function classifySentiment(string $text): array
    {
        $tokens = $this->tokenize($text);
        $positive = count(array_intersect($tokens, self::POSITIVE_WORDS));
        $negative = count(array_intersect($tokens, self::NEGATIVE_WORDS));

        $sentiment = match (true) {
            $positive > $negative => Sentiment::Positive,
            $negative > $positive => Sentiment::Negative,
            default => Sentiment::Neutral,
        };

        $total = $positive + $negative;
        $score = $total === 0 ? 0.0 : round(($positive - $negative) / $total, 4);

        return ['sentiment' => $sentiment->value, 'score' => $score];
    }

    /**
     * @return array<int, string>
     */
    private function tokenize(string $text): array
    {
        return preg_split('/\W+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }
}
