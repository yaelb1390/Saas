<?php

declare(strict_types=1);

namespace App\Modules\Sales\DTOs;

use App\Modules\Sales\Enums\PaymentMethod;

/**
 * Lo que el cliente entregó por una vía.
 *
 * OJO A QUÉ ES `amount`: es lo ENTREGADO, no lo imputado. Quien decide cuánto de eso cubre la venta y
 * cuánto es vuelto es `RepartoDePagos`, en el servidor. Si este DTO trajera ya el reparto hecho, el
 * navegador estaría decidiendo cuánto entra al cajón.
 */
final readonly class PaymentData
{
    public function __construct(
        public PaymentMethod $method,
        public string $amount,
        public ?string $reference = null,
    ) {}
}
