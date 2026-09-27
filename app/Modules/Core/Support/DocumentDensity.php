<?php

declare(strict_types=1);

namespace App\Modules\Core\Support;

/**
 * Cuánto "aprieta" un documento (factura/cotización) según cuántas líneas tiene.
 *
 * NUNCA decide qué se muestra, solo qué tan compacto se pinta. Todas las líneas se renderizan
 * siempre; lo único que cambia con la densidad es tipografía/espaciado, y si aun así no cabe en
 * una página, el propio motor de PDF pagina solo (ver `documents/components/items.blade.php`).
 */
final class DocumentDensity
{
    public const NORMAL = 'normal';

    public const COMPACT = 'compact';

    public const ULTRA = 'ultra';

    /**
     * Umbrales calibrados a ojo con 1/3/5/10/20/50/100 líneas: hasta 10 líneas cabe holgado en
     * A4 con tipografía normal; entre 11 y 25 con la compacta; de ahí para arriba, la ultra
     * compacta aprovecha una sola página hasta donde es razonable y de ahí en adelante pagina.
     */
    public static function paraCantidad(int $lineas): string
    {
        return match (true) {
            $lineas <= 10 => self::NORMAL,
            $lineas <= 25 => self::COMPACT,
            default => self::ULTRA,
        };
    }
}
