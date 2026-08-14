<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Modules\Core\Models\Warehouse;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Sales\Models\Sale;
use Illuminate\Support\Facades\DB;

/**
 * Anula una venta: la retira del historial y DESHACE lo que hizo.
 *
 * Borrar la venta a secas —como se archiva un producto— la quitaría de listados e informes dejando
 * el stock descontado y el dinero contado en la caja. Las existencias y el arqueo quedarían
 * mintiendo, y nadie se enteraría hasta cuadrar la caja o contar el inventario.
 *
 * Por eso anular hace tres cosas en una sola transacción: devuelve el stock, saca el cobro de la
 * caja y archiva la venta (borrado lógico: el registro sigue en la base y se puede recuperar).
 *
 * Hay dos casos que NO se anulan, y se informa de por qué:
 *
 *  - Ventas con FACTURA FISCAL. Un NCF emitido se reporta a la DGII: hacer desaparecer la venta
 *    dejaría un comprobante declarado sin nada que lo respalde. Esas van por la vía fiscal.
 *  - Ventas cuya sesión de caja YA SE CERRÓ. Sacar el cobro reescribiría un arqueo firmado, que es
 *    justamente el documento que dice cuánto dinero había al cerrar.
 */
final class SaleVoidService
{
    /** La venta ya tiene comprobante fiscal. */
    public const MOTIVO_FACTURADA = 'facturada';

    /** El arqueo de esa caja ya está cerrado. */
    public const MOTIVO_CAJA_CERRADA = 'caja_cerrada';

    public function __construct(private readonly StockService $stock) {}

    /**
     * Razón por la que NO se puede anular, o null si sí se puede.
     */
    public function motivoParaNoAnular(Sale $sale): ?string
    {
        if (DB::table('invoices')->where('sale_id', $sale->id)->exists()) {
            return self::MOTIVO_FACTURADA;
        }

        $sesionCerrada = DB::table('cash_movements')
            ->join('cash_sessions', 'cash_sessions.id', '=', 'cash_movements.cash_session_id')
            ->where('cash_movements.reference_type', Sale::class)
            ->where('cash_movements.reference_id', $sale->id)
            ->whereNotNull('cash_sessions.closed_at')
            ->exists();

        return $sesionCerrada ? self::MOTIVO_CAJA_CERRADA : null;
    }

    public function void(Sale $sale): void
    {
        DB::transaction(function () use ($sale): void {
            $warehouse = $sale->warehouse_id !== null ? Warehouse::find($sale->warehouse_id) : null;

            foreach ($sale->items as $item) {
                $product = $item->product;

                // Solo vuelve lo que salió: un servicio sin control de existencias no descontó nada.
                if ($warehouse === null || $product === null || ! $product->track_stock) {
                    continue;
                }

                $this->stock->increase(
                    $product,
                    $warehouse,
                    // No hay un tipo «devolución» en el catálogo de movimientos; se registra como
                    // ajuste y la nota dice de qué venta viene, que es lo que hace falta para
                    // entender el movimiento meses después.
                    StockMovementType::Adjustment,
                    (string) $item->quantity,
                    ['reference' => $sale, 'notes' => "Anulación de la venta {$sale->code}"],
                );
            }

            // El cobro sale de la caja. Se borra en vez de compensarlo con un movimiento contrario
            // porque la sesión sigue abierta: el arqueo aún no ha dicho nada, así que no hay nada
            // que corregir, solo algo que no debió estar.
            DB::table('cash_movements')
                ->where('reference_type', Sale::class)
                ->where('reference_id', $sale->id)
                ->delete();

            // Borrado lógico: la venta desaparece de listados e informes, pero la fila sigue ahí.
            $sale->delete();
        });
    }
}
