<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Events;

use App\Modules\Inventory\Models\StockMovement;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Se dispara tras registrar un movimiento de inventario. Punto de enganche para
 * automatizaciones (alertas de stock mínimo, sincronización, etc.).
 */
final class StockMovementRegistered
{
    use Dispatchable;

    public function __construct(public readonly StockMovement $movement) {}
}
