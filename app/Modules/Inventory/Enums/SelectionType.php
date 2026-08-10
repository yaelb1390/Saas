<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Enums;

/**
 * Cómo se elige dentro de un grupo de opciones.
 */
enum SelectionType: string
{
    /** Una y solo una: el tamaño de un helado. */
    case Single = 'single';

    /** Varias a la vez: los sabores de una copa, los extras de una hamburguesa. */
    case Multiple = 'multiple';

    public function label(): string
    {
        return match ($this) {
            self::Single => 'Elegir una',
            self::Multiple => 'Elegir varias',
        };
    }
}
