<?php

declare(strict_types=1);

namespace App\Modules\Inventory\DTOs;

/**
 * Un serial escaneado, con los datos del lote que comparte con los demás de la misma tanda.
 *
 * El serial es lo único distinto de una unidad a otra en un escaneo; condición, color, costo y precio
 * se eligen una vez para toda la tanda —«estas veinte son iguales, nuevas, negras»— y viajan aquí
 * repetidos. Si dos unidades de la misma tanda difieren en algo, son dos tandas.
 */
final readonly class ScanUnitData
{
    public function __construct(
        public string $serial,
        public ?string $condition = null,
        public ?string $color = null,
        public ?string $cost = null,
        public ?string $price = null,
        public ?string $notes = null,
    ) {}
}
