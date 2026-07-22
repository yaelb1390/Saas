<?php

declare(strict_types=1);

namespace App\Modules\AI\Models;

use App\Modules\AI\Enums\Sentiment;
use App\Modules\Core\Tenancy\BelongsToCompany;
use App\Modules\Core\Tenancy\HasCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Resultado de un análisis de sentimiento sobre una entidad (mensaje, reseña, etc.).
 *
 * @property Sentiment $sentiment
 */
class AiSentimentAnalysis extends Model implements HasCompany
{
    use BelongsToCompany;

    protected $table = 'ai_sentiment_analyses';

    protected $fillable = [
        'company_id',
        'analyzable_type',
        'analyzable_id',
        'sentiment',
        'score',
        'model',
    ];

    protected function casts(): array
    {
        return [
            'sentiment' => Sentiment::class,
            'score' => 'decimal:4',
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function analyzable(): MorphTo
    {
        return $this->morphTo();
    }
}
