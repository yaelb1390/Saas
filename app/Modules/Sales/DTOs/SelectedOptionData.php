<?php

declare(strict_types=1);

namespace App\Modules\Sales\DTOs;

/**
 * Una opción elegida en una línea, ya resuelta y verificada por el servidor.
 *
 * Lleva los nombres además del id porque es lo que se congela en el recibo: si mañana renombran la
 * opción, lo ya vendido debe seguir diciendo lo que decía.
 */
final readonly class SelectedOptionData
{
    public function __construct(
        public ?int $optionId,
        public string $groupName,
        public string $optionName,
        public string $priceDelta = '0',
    ) {}
}
