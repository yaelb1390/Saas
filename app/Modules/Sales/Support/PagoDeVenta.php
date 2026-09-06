<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support;

use App\Modules\Sales\Enums\PaymentMethod;

/**
 * Una forma de pago ya imputada: cuánto de la venta se paga así y cuánto entregó el cliente.
 *
 * `amount` es lo que cuenta para el total, la contabilidad y el 607. `tendered` es lo que pasó por
 * las manos del cajero. Solo se diferencian en efectivo, que es la única vía con vuelto.
 */
final readonly class PagoDeVenta
{
    public function __construct(
        public PaymentMethod $method,
        public string $amount,
        public string $tendered,
        public ?string $reference = null,
    ) {}

    /** Lo que sobra de lo entregado: vuelto. Solo el efectivo puede tenerlo. */
    public function vuelto(): string
    {
        $sobra = bcsub($this->tendered, $this->amount, 2);

        return bccomp($sobra, '0', 2) > 0 ? $sobra : '0.00';
    }
}
