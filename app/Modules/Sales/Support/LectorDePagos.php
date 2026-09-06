<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support;

use App\Modules\Core\Support\DbTable;
use App\Modules\Sales\Enums\PaymentMethod;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Models\SalePayment;

/**
 * Cómo se pagó una venta, venga de donde venga.
 *
 * ES EL ÚNICO SITIO POR EL QUE SE LEE EL DESGLOSE, y esa regla no es estética. Las ventas anteriores a
 * que existiera `sale_payments` no tienen filas, así que un informe que haga `JOIN sale_payments`
 * haría desaparecer TODAS de sus totales, sin error y sin aviso. Aquí se sintetizan desde la cabecera
 * y todo el mundo ve lo mismo.
 *
 * Y hay un segundo motivo: en producción las migraciones se aplican a mano. Entre que sale este
 * código y alguien migra, la tabla no existe. Sin esta clase, cada consulta a `sale_payments` sería
 * una pantalla caída; con ella, el sistema se comporta exactamente como antes.
 */
final class LectorDePagos
{
    /**
     * El desglose de una venta.
     *
     * Con filas, se usan las filas. Sin ellas —venta antigua, o migración todavía sin aplicar— se
     * reconstruye una sola vía desde `payment_method` y `paid`, que es justo lo que la lógica vieja
     * daba por hecho. El resultado es idéntico al de antes para cualquier venta ya existente.
     */
    public function de(Sale $venta): DesgloseDePago
    {
        $filas = $this->filasDe($venta);

        if ($filas !== []) {
            return new DesgloseDePago($filas);
        }

        return new DesgloseDePago([$this->desdeLaCabecera($venta)]);
    }

    /**
     * @return array<int, PagoDeVenta>
     */
    private function filasDe(Sale $venta): array
    {
        if (! DbTable::existe('sale_payments')) {
            return [];
        }

        // `relationLoaded` para no disparar una consulta por venta al recorrer un listado: quien
        // pinta muchas ventas hace `->with('payments')` y aquí se aprovecha.
        $pagos = $venta->relationLoaded('payments') ? $venta->getRelation('payments') : $venta->payments;

        return $pagos
            ->map(fn (SalePayment $pago): PagoDeVenta => new PagoDeVenta(
                method: $pago->method,
                amount: (string) $pago->amount,
                tendered: (string) $pago->tendered,
                reference: $pago->reference,
            ))
            ->all();
    }

    /**
     * La venta de siempre, con su única forma de pago.
     *
     * A crédito no se recibió nada, aunque el importe se impute igual: es lo que distingue «cobrado»
     * de «pendiente de cobro» en la contabilidad.
     */
    private function desdeLaCabecera(Sale $venta): PagoDeVenta
    {
        $metodo = $venta->payment_method instanceof PaymentMethod
            ? $venta->payment_method
            : (PaymentMethod::tryFrom((string) $venta->payment_method) ?? PaymentMethod::Cash);

        $total = bcadd((string) $venta->total, '0', 2);

        return new PagoDeVenta(
            method: $metodo,
            amount: $total,
            tendered: $metodo === PaymentMethod::Credit ? '0.00' : bcadd((string) $venta->paid, '0', 2),
        );
    }
}
