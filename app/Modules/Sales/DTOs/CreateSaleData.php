<?php

declare(strict_types=1);

namespace App\Modules\Sales\DTOs;

/**
 * DTO inmutable para registrar una venta con sus líneas.
 *
 * No transporta el impuesto: el ITBIS no lo decide quien llama, se deriva del importe cobrado
 * mediante TaxCalculator. Así ninguna ruta de entrada (POS, API, importación) puede registrar
 * una venta con un impuesto incoherente.
 *
 * customerId y customerName no son lo mismo y conviven a propósito: el primero enlaza la venta
 * con la ficha del CRM (para su historial y su portal); el segundo es el nombre que se imprime en
 * el recibo, que puede darse sin identificar a nadie (venta de mostrador) y que no debe cambiar
 * después. Si solo llega customerId, el servicio copia el nombre del cliente.
 */
final readonly class CreateSaleData
{
    /**
     * @param  array<int, SaleLineData>  $lines
     */
    public function __construct(
        public int $warehouseId,
        public array $lines,
        public string $paymentMethod = 'cash',
        public ?string $paid = null,
        public ?string $customerName = null,
        public ?int $branchId = null,
        public ?int $cashSessionId = null,
        public ?int $customerId = null,
        // POS configurable a nivel de ticket.
        public string $tip = '0',
        public string $discountTotal = '0',
        public ?int $employeeId = null,
    ) {}

    /**
     * Copia el DTO fijando la sesión de caja (lo que necesita el POS al cobrar).
     *
     * Existe para que nadie tenga que reconstruir el DTO campo a campo: ese copiado manual ya
     * provocó una vez que se perdiera «customerId» en silencio —el POS enviaba el cliente y la
     * venta se guardaba sin él—, y el compilador no puede avisar de un argumento que simplemente
     * no se pasa. Al clonar aquí, añadir un campo nuevo al DTO no puede volver a olvidarse.
     */
    public function withCashSession(int $cashSessionId): self
    {
        return new self(
            warehouseId: $this->warehouseId,
            lines: $this->lines,
            paymentMethod: $this->paymentMethod,
            paid: $this->paid,
            customerName: $this->customerName,
            branchId: $this->branchId,
            cashSessionId: $cashSessionId,
            customerId: $this->customerId,
            tip: $this->tip,
            discountTotal: $this->discountTotal,
            employeeId: $this->employeeId,
        );
    }

    /**
     * Base imponible cobrada al cliente (precios con ITBIS incluido), tras restar el descuento
     * global del ticket. Nunca es negativa. La propina NO entra aquí: se suma después del ITBIS.
     */
    public function gross(): string
    {
        $gross = '0';

        foreach ($this->lines as $line) {
            $gross = bcadd($gross, $line->amount(), 2);
        }

        $gross = bcsub($gross, $this->discountTotal, 2);

        return bccomp($gross, '0', 2) < 0 ? '0.00' : $gross;
    }
}
