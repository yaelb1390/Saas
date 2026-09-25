<?php

declare(strict_types=1);

namespace App\Modules\SocialCommerce\Enums;

/**
 * Qué precio del producto usa la regla al rellenar la plantilla.
 *
 * `Promotional` está declarado pero SIN dato que lo respalde: `products` no tiene una columna de
 * precio promocional (ver auditoría, sección 7) y añadirla tocaría el módulo Inventory, fuera del
 * alcance de esta entrega. Se deja el caso para no tener que renombrar nada cuando se decida
 * incorporarlo; mientras tanto, `Rule::precio()` lo trata igual que `Normal`.
 */
enum PriceMode: string
{
    case Normal = 'normal';
    case Promotional = 'promotional';

    public function label(): string
    {
        return match ($this) {
            self::Normal => 'Precio normal',
            self::Promotional => 'Precio promocional (pendiente: products no tiene ese campo todavía)',
        };
    }
}
