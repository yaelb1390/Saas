<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Modules\Core\Models\Warehouse;
use App\Modules\Inventory\Exceptions\SerialScanException;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\Sales\Models\Sale;

/**
 * Marca la unidad concreta que sale en una venta.
 *
 * VA DE LA MANO CON EL DESCUENTO DE STOCK, no en su lugar. Al vender un producto serializado se hacen
 * las dos cosas dentro de la misma transacción: el stock por cantidad baja en uno —como en cualquier
 * venta— Y la unidad de esa serie pasa a `sold`. Así el contador y las unidades siguen cuadrando: la
 * regla que sostiene el módulo entero (ver `ProductUnit`) se cumple también al salir, no solo al
 * entrar.
 *
 * SI LA SERIE NO ESTÁ DISPONIBLE, LA VENTA SE CAE. Vender una unidad ya vendida, o una que no existe,
 * dejaría el stock descontado sin una unidad detrás —justo el descuadre que todo esto evita—. Mejor
 * abortar la venta entera que registrarla con un fantasma.
 */
final class UnitSaleService
{
    /**
     * Da por vendida la unidad de esta serie, y devuelve su precio propio si lo tiene.
     *
     * Devuelve null si el producto NO es serializado: entonces no hay unidad que marcar y la venta
     * sigue su curso normal, descontando stock por cantidad como siempre.
     */
    public function marcarVendida(Product $product, Warehouse $warehouse, ?string $serial, Sale $sale): ?ProductUnit
    {
        if (! $product->esSerializado()) {
            return null;
        }

        if ($serial === null || trim($serial) === '') {
            throw SerialScanException::serieRequerida($product->name);
        }

        $unidad = ProductUnit::query()
            ->where('product_id', $product->id)
            ->where('warehouse_id', $warehouse->id)
            ->where('serial', trim($serial))
            ->where('status', ProductUnit::DISPONIBLE)
            // Bloqueo de fila: dos cajeros no pueden vender el mismo teléfono a la vez. El segundo se
            // espera y encuentra la unidad ya vendida, en vez de venderla dos veces.
            ->lockForUpdate()
            ->first();

        if ($unidad === null) {
            throw SerialScanException::serieNoDisponible((string) $serial);
        }

        $unidad->update([
            'status' => ProductUnit::VENDIDA,
            'sold_at' => now(),
            'sale_id' => $sale->id,
        ]);

        return $unidad;
    }
}
