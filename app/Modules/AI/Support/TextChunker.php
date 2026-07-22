<?php

declare(strict_types=1);

namespace App\Modules\AI\Support;

/**
 * Trocea un texto en fragmentos de un número de palabras con solapamiento, para RAG.
 */
final class TextChunker
{
    /**
     * @return array<int, string>
     */
    public static function chunk(string $text, int $wordsPerChunk = 40, int $overlap = 10): array
    {
        $words = preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if ($words === []) {
            return [];
        }

        if (count($words) <= $wordsPerChunk) {
            return [implode(' ', $words)];
        }

        $step = max(1, $wordsPerChunk - $overlap);
        $chunks = [];

        for ($start = 0; $start < count($words); $start += $step) {
            $slice = array_slice($words, $start, $wordsPerChunk);
            $chunks[] = implode(' ', $slice);

            if ($start + $wordsPerChunk >= count($words)) {
                break;
            }
        }

        return $chunks;
    }
}
