<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Exceptions;

use RuntimeException;

/**
 * Se lanza cuando una salida de inventario dejaría el saldo por debajo de cero.
 */
final class InsufficientStockException extends RuntimeException
{
    public static function for(int $productId, int $warehouseId, string $available, string $requested): self
    {
        return new self(sprintf(
            'Stock insuficiente para el producto %d en el almacén %d: disponible %s, solicitado %s.',
            $productId,
            $warehouseId,
            $available,
            $requested,
        ));
    }
}
