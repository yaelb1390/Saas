<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support;

use App\Modules\Sales\Enums\PaymentMethod;

/**
 * Cómo se pagó una venta, y todas las cuentas que de eso dependen.
 *
 * ES EL ÚNICO SITIO donde se contesta «cuánto entró al cajón», «cuánto se apunta en la cuenta» y «en
 * qué columna del 607 va esto». Antes esas tres preguntas se respondían por separado —`CheckoutService`,
 * `RecordSaleIncome` y `DgiiReportService`, cada uno leyendo `sales.payment_method` a su manera—, y
 * bastaba que una cambiara para que las otras dos empezaran a discrepar sin que nada fallara.
 *
 * TODAS LAS SUMAS CON bcmath, ninguna con `SUM()` de SQL: en SQLite eso devuelve un float, y un
 * céntimo perdido por redondeo tumba el envío del 607 del mes entero.
 */
final readonly class DesgloseDePago
{
    /**
     * @param  array<int, PagoDeVenta>  $pagos
     */
    public function __construct(private array $pagos) {}

    /**
     * @return array<int, PagoDeVenta>
     */
    public function pagos(): array
    {
        return $this->pagos;
    }

    /** Lo imputado en total. Por la invariante, es el total de la venta. */
    public function total(): string
    {
        return $this->sumar(fn (PagoDeVenta $p): string => $p->amount);
    }

    /**
     * LO QUE ENTRA AL CAJÓN: solo la parte en efectivo.
     *
     * Cobrar con tarjeta o por transferencia no deja billetes en la caja; contarlos ahí haría que el
     * cierre saliera siempre con un faltante exactamente igual a lo cobrado por esas vías.
     */
    public function efectivo(): string
    {
        return $this->sumar(
            fn (PagoDeVenta $p): string => $p->method->entersCashDrawer() ? $p->amount : '0',
        );
    }

    /** Lo que el cliente entregó, sumando todas las vías. Va a `sales.paid`. */
    public function entregado(): string
    {
        return $this->sumar(fn (PagoDeVenta $p): string => $p->tendered);
    }

    /** El vuelto: lo entregado de más. Solo puede venir del efectivo. */
    public function cambio(): string
    {
        $sobra = bcsub($this->entregado(), $this->total(), 2);

        return bccomp($sobra, '0', 2) > 0 ? $sobra : '0.00';
    }

    /**
     * Lo que de verdad ha entrado ya, para la contabilidad: todo menos lo que queda a crédito.
     *
     * Una venta a medio pagar apuntaría el total entero en la cuenta y el dueño vería dinero que
     * todavía está en la calle.
     */
    public function cobradoAhora(): string
    {
        return $this->sumar(
            fn (PagoDeVenta $p): string => $p->method === PaymentMethod::Credit ? '0' : $p->amount,
        );
    }

    /**
     * La vía de mayor importe, que es la que se guarda en `sales.payment_method`.
     *
     * NO se inventa un valor «mixto», y eso importa: todos los `match` que ya existen sobre esa
     * columna tienen una rama por omisión —«Otras» en el 607—, así que un valor nuevo no fallaría,
     * declararía mal cada venta mixta en silencio.
     */
    public function metodoDominante(): PaymentMethod
    {
        $mayor = null;

        foreach ($this->pagos as $pago) {
            if ($mayor === null || bccomp($pago->amount, $mayor->amount, 2) > 0) {
                $mayor = $pago;
            }
        }

        return $mayor?->method ?? PaymentMethod::Cash;
    }

    public function esMixto(): bool
    {
        return count($this->pagos) > 1;
    }

    /**
     * Lo imputado a una vía concreta.
     */
    public function de(PaymentMethod $metodo): string
    {
        return $this->sumar(fn (PagoDeVenta $p): string => $p->method === $metodo ? $p->amount : '0');
    }

    /**
     * @param  callable(PagoDeVenta): string  $cuanto
     */
    private function sumar(callable $cuanto): string
    {
        $suma = '0';

        foreach ($this->pagos as $pago) {
            $suma = bcadd($suma, $cuanto($pago), 2);
        }

        return bcadd($suma, '0', 2);
    }
}
