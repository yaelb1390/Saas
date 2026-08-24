<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Modules\Core\Models\Warehouse;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Stock;
use App\Modules\Inventory\Models\StockMovement;
use DomainException;

/**
 * Poner la existencia de un producto en la cantidad que se acaba de contar.
 *
 * Es lo que de verdad hace un negocio: abre la nevera, cuenta veinticuatro y quiere que el sistema
 * diga veinticuatro. Hasta ahora eso solo se podía conseguir dando una «entrada de mercancía», que
 * es otra cosa —una compra a un proveedor, con su documento y su costo— y que además no sirve para
 * corregir hacia abajo cuando lo contado es MENOS de lo que decía el sistema.
 *
 * LO QUE NO HACE, Y ES DELIBERADO: escribir el número encima. La existencia se mueve registrando la
 * DIFERENCIA como un movimiento de ajuste, igual que cualquier otro cambio. Sobrescribirla dejaría
 * el kardex diciendo una cosa y el saldo otra, y el día que no cuadre no habría forma de saber quién
 * lo cambió ni cuándo. Que el usuario teclee «24» y el sistema apunte «+3» es exactamente la
 * traducción que tiene que hacer un sistema de inventario.
 */
final class StockCountService
{
    public function __construct(private readonly StockService $stock) {}

    /**
     * @param  string  $contado  la cantidad que hay de verdad en el almacén
     * @param  string|null  $nota  por qué no cuadraba: rotura, merma, error de conteo…
     */
    public function ajustar(Product $product, string $contado, ?string $nota = null): StockMovement
    {
        if (! $product->track_stock) {
            /*
             * Un producto sin control de existencias no tiene qué contar.
             *
             * Se avisa en vez de encender el control por nuestra cuenta: que un producto lleve o no
             * inventario es una decisión del negocio —un servicio no se cuenta— y cambiarla de
             * refilón haría aparecer avisos de stock bajo de cosas que no existen en ningún estante.
             */
            throw new DomainException(
                'Este producto no lleva control de existencias. Enciéndelo primero en «Editar producto».'
            );
        }

        if (! is_numeric($contado) || bccomp($contado, '0', 3) < 0) {
            throw new DomainException('La cantidad contada no puede ser negativa.');
        }

        $almacen = Warehouse::query()->where('is_default', true)->orderBy('id')->first();

        if ($almacen === null) {
            throw new DomainException('No hay un almacén configurado.');
        }

        $actual = (string) (Stock::query()
            ->where('product_id', $product->id)
            ->where('warehouse_id', $almacen->id)
            ->value('quantity') ?? '0');

        $diferencia = bcsub($contado, $actual, 3);

        if (bccomp($diferencia, '0', 3) === 0) {
            throw new DomainException('La existencia ya era esa: no hay nada que ajustar.');
        }

        /*
         * El movimiento va con la diferencia y con SU EXPLICACIÓN.
         *
         * En la nota queda de qué a qué se pasó, y no solo el salto: dentro de seis meses, «+3» no
         * dice nada y «de 21 a 24 (conteo)» se entiende sin abrir otra pantalla.
         */
        $motivo = sprintf(
            'Conteo: de %s a %s.%s',
            $this->legible($actual),
            $this->legible($contado),
            filled($nota) ? ' '.$nota : '',
        );

        return $this->stock->register(
            $product,
            $almacen,
            StockMovementType::Adjustment,
            $diferencia,
            ['notes' => $motivo],
        );
    }

    /** «24.000» → «24». El movimiento lo lee una persona. */
    private function legible(string $numero): string
    {
        return str_contains($numero, '.') ? rtrim(rtrim($numero, '0'), '.') : $numero;
    }
}
