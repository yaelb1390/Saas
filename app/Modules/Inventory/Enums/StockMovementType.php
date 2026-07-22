<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Enums;

/**
 * Tipos de movimiento de inventario. El signo de la cantidad lo define quien registra el
 * movimiento; este enum solo clasifica el origen para el kardex y los reportes.
 */
enum StockMovementType: string
{
    case Initial = 'initial';
    case Purchase = 'purchase';
    case Sale = 'sale';
    case Adjustment = 'adjustment';
    case TransferIn = 'transfer_in';
    case TransferOut = 'transfer_out';

    public function label(): string
    {
        return match ($this) {
            self::Initial => 'Inventario inicial',
            self::Purchase => 'Compra',
            self::Sale => 'Venta',
            self::Adjustment => 'Ajuste',
            self::TransferIn => 'Transferencia (entrada)',
            self::TransferOut => 'Transferencia (salida)',
        };
    }
}
