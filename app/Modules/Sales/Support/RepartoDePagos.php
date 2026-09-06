<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support;

use App\Modules\Sales\DTOs\PaymentData;
use App\Modules\Sales\Enums\PaymentMethod;
use App\Modules\Sales\Exceptions\InsufficientPaymentException;
use App\Modules\Sales\Exceptions\PaymentSplitException;

/**
 * Convierte lo que el cajero dice haber recibido en un reparto válido, o revienta.
 *
 * EL NAVEGADOR DICE QUÉ RECIBIÓ; EL SERVIDOR DECIDE CÓMO SE IMPUTA. Esa separación es lo importante
 * de esta clase. Si el reparto llegara ya hecho desde el cliente, una petición a mano podría declarar
 * «efectivo 0 / tarjeta 1000» en una venta cobrada en billetes, y el arqueo saldría con un faltante
 * de mil pesos que el cajero pagaría de su bolsillo. Es la misma doctrina que ya sigue el precio, que
 * siempre se relee del catálogo.
 *
 * Sin base de datos y sin estado: se puede probar entera con aritmética.
 */
final class RepartoDePagos
{
    /**
     * Cuántas formas de pago caben en un cobro.
     *
     * Un tope acotado, y no por elegancia: sin él, una petición con mil filas hace trabajar al
     * servidor dentro de una transacción que mantiene bloqueada la fila del stock.
     */
    public const TOPE = 5;

    /**
     * El camino de siempre: una sola forma de pago.
     *
     * Reproduce EXACTAMENTE la regla que había antes de que existiera el reparto —crédito exento, y
     * si no, lo pagado tiene que cubrir el total— para que ni un mensaje de error ni un test
     * existente cambien. Es el contrato de compatibilidad de todo este trabajo.
     */
    public function deUnaSolaVia(PaymentMethod $metodo, ?string $pagado, string $total): DesgloseDePago
    {
        $entregado = $pagado ?? $total;

        if ($metodo !== PaymentMethod::Credit && bccomp($entregado, $total, 2) < 0) {
            throw InsufficientPaymentException::for($total, $entregado);
        }

        // A crédito no se recibe nada, aunque el importe de la venta se impute igual.
        if ($metodo === PaymentMethod::Credit) {
            return new DesgloseDePago([new PagoDeVenta($metodo, $total, '0.00')]);
        }

        return new DesgloseDePago([new PagoDeVenta($metodo, $total, bcadd($entregado, '0', 2))]);
    }

    /**
     * El reparto entre varias vías.
     *
     * Se imputa EN EL ORDEN RECIBIDO contra lo que queda pendiente, y al final la suma tiene que ser
     * exactamente el total. Ni un céntimo de menos: un cobro que no cuadra es una venta cobrada a
     * medias, y eso no se descubre hasta que alguien cuenta el dinero.
     *
     * @param  array<int, PaymentData>  $entregas
     */
    public function repartir(array $entregas, string $total): DesgloseDePago
    {
        if (count($entregas) > self::TOPE) {
            throw PaymentSplitException::demasiadasFormas(self::TOPE);
        }

        $pendiente = bcadd($total, '0', 2);
        $pagos = [];

        foreach ($entregas as $entrega) {
            $entregado = bcadd($entrega->amount, '0', 2);

            // Una fila de cero es ruido, y sirve para colar una forma de pago en el 607 sin dinero
            // detrás. No se ignora en silencio: se rechaza.
            if (bccomp($entregado, '0', 2) <= 0) {
                throw PaymentSplitException::importeVacio($entrega->method);
            }

            if ($entrega->method->entersCashDrawer()) {
                /*
                 * El efectivo PUEDE sobrar, y lo que sobra es vuelto. Es la única vía que lo admite:
                 * el cajón tiene billetes para devolver.
                 */
                $imputado = bccomp($entregado, $pendiente, 2) > 0 ? $pendiente : $entregado;
            } else {
                /*
                 * Una tarjeta o una transferencia NO pueden cobrar de más. Si el datáfono pasó más de
                 * lo pendiente, ese dinero está en la cuenta del negocio y hay que devolverlo por
                 * donde entró; repartirlo aquí lo taparía.
                 */
                if (bccomp($entregado, $pendiente, 2) > 0) {
                    throw PaymentSplitException::sinVueltoEn($entrega->method);
                }

                $imputado = $entregado;
            }

            $pagos[] = new PagoDeVenta($entrega->method, $imputado, $entregado, $entrega->reference);
            $pendiente = bcsub($pendiente, $imputado, 2);
        }

        $desglose = new DesgloseDePago($pagos);

        if (bccomp($desglose->total(), $total, 2) !== 0) {
            throw PaymentSplitException::noCuadra($total, $desglose->total());
        }

        return $desglose;
    }
}
