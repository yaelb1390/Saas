<?php

declare(strict_types=1);

namespace App\Modules\Sales\DTOs;

use App\Modules\Sales\Enums\OrderType;
use App\Modules\Sales\Enums\PaymentMethod;

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
        // Enum y no cadena: de este valor depende si la venta engorda el arqueo de caja, y una
        // errata («efectivo» en vez de «cash») habría descuadrado el cierre en silencio.
        public PaymentMethod $paymentMethod = PaymentMethod::Cash,
        public ?string $paid = null,
        public ?string $customerName = null,
        public ?int $branchId = null,
        public ?int $cashSessionId = null,
        public ?int $customerId = null,
        // POS configurable a nivel de ticket.
        public string $tip = '0',
        public string $discountTotal = '0',
        public ?int $employeeId = null,
        // Cómo se lleva el cliente lo que compró. Null = no se preguntó (el negocio no encendió la
        // opción, o la venta viene de la API). Solo el envío genera entrega; ver OrderType.
        public ?OrderType $orderType = null,
        /*
         * La llave que puso el navegador al cobrar sin internet, si esta venta viene de ahí.
         *
         * Es una propiedad de la venta y no un parámetro de la operación: identifica ESTE cobro
         * concreto, y es lo que permite reintentar la subida sin cobrar dos veces. Null en todo lo
         * que se cobra con conexión.
         */
        public ?string $clientUuid = null,
        /*
         * EL COBRO REPARTIDO entre varias formas de pago, si lo hubo.
         *
         * Vacío quiere decir «una sola vía», y entonces mandan `paymentMethod` y `paid` como toda la
         * vida: la API, el cobro sin conexión, las cotizaciones y los dos puntos de venta siguen
         * construyendo el DTO sin esto y se comportan exactamente igual que antes.
         *
         * Con contenido, manda esta lista y los otros dos campos se derivan de ella. Lo que viaja
         * aquí es lo ENTREGADO por cada vía, no lo imputado: quien decide cuánto cubre la venta y
         * cuánto es vuelto es el servidor.
         *
         * @var array<int, PaymentData>
         */
        public array $payments = [],
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
            orderType: $this->orderType,
            clientUuid: $this->clientUuid,
            /*
             * Olvidar ESTE campo aquí sería el fallo más caro de todo el cobro repartido: el servicio
             * no vería el reparto, caería en el camino de una sola vía con efectivo por omisión, y
             * metería el TOTAL ENTERO al cajón cuando la mitad se cobró con tarjeta. Es exactamente
             * lo que ya pasó con `customerId`, y ningún tipo puede avisar de un argumento que
             * simplemente no se pasa: por eso hay un test que compara campo a campo por reflexión.
             */
            payments: $this->payments,
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
