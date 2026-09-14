<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Exceptions\ProductUnitException;
use App\Modules\Inventory\Models\ProductUnit;
use Illuminate\Support\Facades\DB;

/**
 * La vida de una unidad DESPUÉS del alta: corregirla (precio, condición, color) o darla de baja. El
 * alta la hace SerialScanService y la venta UnitSaleService; esto cierra el ciclo.
 *
 * LA REGLA QUE SOSTIENE EL MÓDULO, otra vez, ahora al revés: dar de alta una unidad suma uno al stock
 * por cantidad; borrar una disponible RESTA uno, por la misma puerta con kardex (StockService), y
 * dentro de una transacción. Si no, la tabla de unidades y el contador de stock descuadran. Borrar
 * una fila a secas sería un segundo inventario que miente.
 *
 * Y no se borra lo que no está disponible: una vendida ya bajó el stock al venderse —volver a bajarlo
 * lo dejaría en negativo— y además su historia es la garantía. Ver ProductUnit.
 */
final class UnitAdjustmentService
{
    public function __construct(private readonly StockService $stock) {}

    /**
     * Da de baja una unidad disponible: baja el stock en uno y la borra (soft-delete).
     *
     * @throws ProductUnitException si la unidad no está disponible.
     */
    public function borrar(ProductUnit $unit): void
    {
        if (! $unit->estaDisponible()) {
            throw ProductUnitException::soloSeBorranDisponibles($unit->serial);
        }

        DB::transaction(function () use ($unit): void {
            /*
             * Primero el stock, por la puerta de siempre. La referencia enlaza el movimiento con la
             * unidad para que el kardex diga «salió esta», no solo «salió 1». Es un ajuste, no una
             * venta: la unidad no sale por la caja, se corrige un alta.
             */
            $this->stock->decrease($unit->product, $unit->warehouse, StockMovementType::Adjustment, '1', [
                'reference' => $unit,
                'notes' => "Baja de unidad · serie {$unit->serial}",
            ]);

            // Soft-delete: la fila se queda por si hubo auditoría o hay que entender qué pasó, pero
            // deja de contar como unidad viva y de aparecer en la pantalla.
            $unit->delete();
        });
    }

    /**
     * Corrige los datos de una unidad sin volver a darla de alta: el precio (un usado mal tasado), la
     * condición y el color. NUNCA la serie —esa es su identidad— ni el estado ni el stock: cambiar
     * precio o color no mueve la cantidad.
     */
    public function editar(ProductUnit $unit, ?string $condition, ?string $color, ?string $price): ProductUnit
    {
        $unit->update([
            'condition' => $this->limpiaTexto($condition),
            'color' => $this->limpiaTexto($color),
            'price' => $this->limpiaImporte($price),
        ]);

        return $unit;
    }

    private function limpiaTexto(?string $valor): ?string
    {
        $valor = $valor === null ? null : trim($valor);

        return $valor === '' ? null : $valor;
    }

    /** Un precio vacío es «usa el del catálogo» (null), no cero. Misma limpieza que en el alta. */
    private function limpiaImporte(?string $valor): ?string
    {
        if ($valor === null || trim($valor) === '') {
            return null;
        }

        return number_format(max(0, (float) $valor), 2, '.', '');
    }
}
