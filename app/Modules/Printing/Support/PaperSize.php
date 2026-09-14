<?php

declare(strict_types=1);

namespace App\Modules\Printing\Support;

/**
 * El catálogo de tamaños de papel que ofrece el Centro de Impresión.
 *
 * Tres familias, y se distinguen por si tienen alto fijo:
 *   - Rollo térmico (58mm, 80mm): ancho fijo, alto SIN límite —es un rollo continuo, se corta según
 *     lo que se imprima—. `heightMm` es null aquí a propósito.
 *   - Etiqueta (57×40, 80×50): ancho Y alto fijos, como una hoja pequeña.
 *   - Hoja (carta, media carta, legal, A4, A5): ancho y alto de una hoja de oficina normal.
 *   - `custom`: el propio usuario da ancho y alto; esos dos números NO viven aquí sino en la
 *     impresora o la plantilla que lo use (`custom_width_mm`/`custom_height_mm`).
 */
final class PaperSize
{
    /**
     * @var array<string, array{label: string, width_mm: int, height_mm: ?int}>
     */
    private const SIZES = [
        '58mm' => ['label' => '58 mm (térmico)', 'width_mm' => 58, 'height_mm' => null],
        '80mm' => ['label' => '80 mm (térmico)', 'width_mm' => 80, 'height_mm' => null],
        '57x40' => ['label' => 'Etiqueta 57 × 40 mm', 'width_mm' => 57, 'height_mm' => 40],
        '80x50' => ['label' => 'Etiqueta 80 × 50 mm', 'width_mm' => 80, 'height_mm' => 50],
        'letter' => ['label' => 'Carta (216 × 279 mm)', 'width_mm' => 216, 'height_mm' => 279],
        'half_letter' => ['label' => 'Media carta (140 × 216 mm)', 'width_mm' => 140, 'height_mm' => 216],
        'legal' => ['label' => 'Legal (216 × 356 mm)', 'width_mm' => 216, 'height_mm' => 356],
        'a4' => ['label' => 'A4 (210 × 297 mm)', 'width_mm' => 210, 'height_mm' => 297],
        'a5' => ['label' => 'A5 (148 × 210 mm)', 'width_mm' => 148, 'height_mm' => 210],
        // Sin medidas propias: las trae quien lo use (la impresora o la plantilla).
        'custom' => ['label' => 'Personalizado', 'width_mm' => 0, 'height_mm' => null],
    ];

    /**
     * @return array<string, string> clave => etiqueta, para desplegables.
     */
    public static function options(): array
    {
        return array_map(static fn (array $s): string => $s['label'], self::SIZES);
    }

    public static function label(string $key): string
    {
        return self::SIZES[$key]['label'] ?? ucfirst($key);
    }

    public static function widthMm(string $key): int
    {
        return self::SIZES[$key]['width_mm'] ?? 0;
    }

    public static function heightMm(string $key): ?int
    {
        return self::SIZES[$key]['height_mm'] ?? null;
    }

    /** Es un rollo continuo (sin alto fijo): 58mm y 80mm. Cambia cómo se calcula la vista previa. */
    public static function isContinuousRoll(string $key): bool
    {
        return array_key_exists($key, self::SIZES) && self::SIZES[$key]['height_mm'] === null && $key !== 'custom';
    }

    public static function exists(string $key): bool
    {
        return array_key_exists($key, self::SIZES);
    }

    /**
     * @return array<int, string>
     */
    public static function keys(): array
    {
        return array_keys(self::SIZES);
    }
}
