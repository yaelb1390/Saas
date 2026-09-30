<?php

declare(strict_types=1);

namespace App\Modules\Finance\Events;

use App\Modules\Finance\Models\PayablePayment;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Se dispara al registrar un abono/pago de una cuenta por pagar. Punto de enganche para anotar
 * el egreso en Finanzas (y en el cajón, si aplica).
 */
final class PayablePaymentRegistered
{
    use Dispatchable;

    public function __construct(public readonly PayablePayment $payment) {}
}
