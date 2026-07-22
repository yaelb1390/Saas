<?php

declare(strict_types=1);

namespace App\Modules\AI\Enums;

enum Sentiment: string
{
    case Positive = 'positive';
    case Neutral = 'neutral';
    case Negative = 'negative';

    public function label(): string
    {
        return match ($this) {
            self::Positive => 'Positivo',
            self::Neutral => 'Neutral',
            self::Negative => 'Negativo',
        };
    }
}
