<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Support;

/**
 * Iconos que se ofrecen al crear una categoría.
 *
 * Es una lista cerrada y no un campo libre: así la barra lateral del punto de venta mantiene un
 * aspecto homogéneo y nadie pega ahí un texto que descuadre la columna.
 */
final class CategoryIcons
{
    /** El que se usa cuando la categoría no eligió ninguno. */
    public const DEFAULT = '🏷️';

    /**
     * Agrupados por familia para que el selector sea navegable.
     *
     * @var array<string, array<int, string>>
     */
    public const GROUPS = [
        'Heladería' => ['🍦', '🍧', '🍨', '🧁', '🍰', '🍮', '🥧', '🍫'],
        'Comida' => ['🍔', '🍕', '🌭', '🌮', '🍟', '🥪', '🍗', '🥗'],
        'Bebidas' => ['🥤', '🧃', '☕', '🧋', '🍺', '🍹', '💧', '🥛'],
        'Otros' => ['🎁', '🛒', '🏷️', '⭐', '🔥', '🍿', '🥡', '📦'],
    ];

    /**
     * Todos los iconos válidos, en una sola lista.
     *
     * @return array<int, string>
     */
    public static function all(): array
    {
        return array_merge(...array_values(self::GROUPS));
    }

    public static function isValid(?string $icon): bool
    {
        return $icon !== null && in_array($icon, self::all(), true);
    }

    /** El icono de la categoría, o el genérico si no tiene o no es válido. */
    public static function resolve(?string $icon): string
    {
        return self::isValid($icon) ? (string) $icon : self::DEFAULT;
    }
}
