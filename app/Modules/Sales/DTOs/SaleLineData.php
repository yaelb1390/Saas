<?php

declare(strict_types=1);

namespace App\Modules\Sales\DTOs;

/**
 * Una línea de venta (producto, cantidad y precio unitario con ITBIS incluido).
 *
 * Los campos del POS configurable son opcionales: descuento (monto ya resuelto que se resta del
 * importe), nota libre, número de serie/IMEI y el empleado que atendió/vendió la línea.
 */
final readonly class SaleLineData
{
    /**
     * @param  array<int, SelectedOptionData>  $options  Tamaños, sabores y extras ya verificados por
     *                                                   el servidor. Su recargo YA está incluido en
     *                                                   `$unitPrice`: aquí viajan para congelarlos en
     *                                                   el recibo, no para recalcular el importe.
     */
    public function __construct(
        public int $productId,
        public string $quantity,
        public string $unitPrice,
        public string $discount = '0',
        public ?string $note = null,
        public ?string $serial = null,
        public ?int $employeeId = null,
        public array $options = [],
    ) {}

    /**
     * Importe de la línea tal como lo paga el cliente (impuesto incluido), ya con el descuento
     * aplicado. Nunca es negativo: un descuento mayor que el bruto deja la línea en 0.
     */
    public function amount(): string
    {
        $net = bcsub(bcmul($this->quantity, $this->unitPrice, 2), $this->discount, 2);

        return bccomp($net, '0', 2) < 0 ? '0.00' : $net;
    }
}
