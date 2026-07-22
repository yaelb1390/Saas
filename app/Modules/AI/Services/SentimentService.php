<?php

declare(strict_types=1);

namespace App\Modules\AI\Services;

use App\Modules\AI\Models\AiSentimentAnalysis;
use App\Modules\AI\Providers\Contracts\AiProvider;
use Illuminate\Database\Eloquent\Model;

/**
 * Clasificador de sentimientos. Delega en el proveedor de IA y persiste el resultado asociado
 * (de forma polimórfica) a la entidad analizada.
 */
final class SentimentService
{
    public function __construct(private readonly AiProvider $provider) {}

    /**
     * @return array{sentiment: string, score: float}
     */
    public function analyze(string $text): array
    {
        return $this->provider->classifySentiment($text);
    }

    /**
     * Analiza el texto de una entidad y guarda el resultado.
     */
    public function analyzeModel(Model $analyzable, string $text): AiSentimentAnalysis
    {
        $result = $this->analyze($text);

        return AiSentimentAnalysis::create([
            'company_id' => $analyzable->getAttribute('company_id'),
            'analyzable_type' => $analyzable->getMorphClass(),
            'analyzable_id' => $analyzable->getKey(),
            'sentiment' => $result['sentiment'],
            'score' => $result['score'],
            'model' => (string) config('ai.default'),
        ]);
    }
}
