<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Modules\Core\Models\Warehouse;
use App\Modules\Inventory\DTOs\ScanUnitData;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Exceptions\SerialScanException;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductUnit;
use Illuminate\Support\Facades\DB;

/**
 * Alta masiva de unidades serializadas por escaneo: elige el producto, dispara los seriales, cada
 * uno entra como una unidad.
 *
 * LA REGLA QUE SOSTIENE TODO EL MÓDULO ESTÁ AQUÍ. Crear una unidad NO es solo insertar una fila: es
 * insertar la fila Y sumar uno al stock por cantidad, por la misma puerta de siempre —`StockService`,
 * con su kardex—. Así el `stock` sigue siendo la verdad de cuántas hay, el POS y los informes lo leen
 * igual que ayer, y las unidades no son un segundo inventario que pueda descuadrar con el primero.
 * Todo dentro de una transacción: o entran las N unidades y sube el stock en N, o no entra nada.
 *
 * EL SERIAL DUPLICADO SE RECHAZA, no se ignora. Un IMEI repetido casi siempre es un escaneo doble del
 * mismo aparato; dejarlo pasar crearía una unidad fantasma y descuadraría el stock justo en el
 * producto donde más caro es equivocarse. Se avisa y se sigue con los demás.
 */
final class SerialScanService
{
    public function __construct(private readonly StockService $stock) {}

    /**
     * Da de alta una tanda de unidades del mismo producto en un almacén.
     *
     * @param  array<int, ScanUnitData>  $seriales
     * @return array{creadas: int, unidades: array<int, ProductUnit>, rechazados: array<int, array{serial: string, motivo: string}>}
     */
    public function alta(Product $product, Warehouse $warehouse, array $seriales): array
    {
        if ($seriales === []) {
            throw SerialScanException::nadaQueDarDeAlta();
        }

        return DB::transaction(function () use ($product, $warehouse, $seriales): array {
            /*
             * SE SERIALIZA AL VUELO. Si el producto todavía no lleva series y le estás escaneando
             * unidades, es que quieres llevarlo por series — así que se marca aquí en vez de exigir
             * que fueras antes a activarle un interruptor. Cualquier producto entra por esta puerta,
             * sin paso previo.
             *
             * Un producto que ya traía stock por cantidad queda con esas unidades identificadas más
             * el remanente sin identificar; se resuelve dándole el resto de series o inventariando.
             * No es peligroso: el contador cuadra hacia arriba, solo hay stock aún sin nombre.
             */
            if (! $product->esSerializado()) {
                $product->update(['tracks_serials' => true]);
            }

            $creadas = [];
            $rechazados = [];
            // Los que ya vienen en esta misma tanda: dos disparos del mismo IMEI se cazan sin ir a la
            // base a preguntar por cada uno.
            $enEstaTanda = [];

            foreach ($seriales as $dato) {
                $serial = trim($dato->serial);

                if ($serial === '') {
                    continue;
                }

                $motivo = $this->porQueNoEntra($product, $serial, $enEstaTanda);

                if ($motivo !== null) {
                    $rechazados[] = ['serial' => $serial, 'motivo' => $motivo];

                    continue;
                }

                $enEstaTanda[mb_strtolower($serial)] = true;

                $unidad = ProductUnit::create([
                    'company_id' => $product->company_id,
                    'product_id' => $product->id,
                    'warehouse_id' => $warehouse->id,
                    'serial' => $serial,
                    'status' => ProductUnit::DISPONIBLE,
                    'condition' => $dato->condition,
                    'color' => $dato->color,
                    'cost' => $this->limpiaImporte($dato->cost),
                    'price' => $this->limpiaImporte($dato->price),
                    'notes' => $dato->notes,
                    'received_at' => now(),
                ]);

                /*
                 * Y AQUÍ SE SUMA AL STOCK. Una unidad = una unidad de cantidad, por la puerta con
                 * kardex. La referencia enlaza el movimiento con la unidad, para que el kardex diga
                 * no solo «entró 1» sino «entró esta».
                 */
                $this->stock->increase($product, $warehouse, StockMovementType::Purchase, '1', [
                    'reference' => $unidad,
                    'notes' => "Alta por escaneo · serie {$serial}",
                ]);

                $creadas[] = $unidad;
            }

            return ['creadas' => count($creadas), 'unidades' => $creadas, 'rechazados' => $rechazados];
        });
    }

    /**
     * Por qué un serial no puede entrar, o null si puede.
     *
     * @param  array<string, bool>  $enEstaTanda
     */
    private function porQueNoEntra(Product $product, string $serial, array $enEstaTanda): ?string
    {
        if (isset($enEstaTanda[mb_strtolower($serial)])) {
            return 'Repetido en esta misma tanda: se escaneó dos veces.';
        }

        // Único por empresa, incluso contra las ya vendidas o las borradas: reutilizar un serial
        // rompería la garantía, que se busca justamente por serie.
        $existe = ProductUnit::withoutCompanyScope()
            ->withTrashed()
            ->where('company_id', $product->company_id)
            ->where('serial', $serial)
            ->exists();

        return $existe ? 'Ya existe una unidad con esta serie.' : null;
    }

    /** Un importe vacío es «usa el del producto» (null), no cero. */
    private function limpiaImporte(?string $valor): ?string
    {
        if ($valor === null || trim($valor) === '') {
            return null;
        }

        return number_format(max(0, (float) $valor), 2, '.', '');
    }
}
