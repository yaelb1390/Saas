<?php

declare(strict_types=1);

namespace App\Modules\Finance\Events;

use App\Modules\Finance\Models\ReceivablePayment;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Se dispara al registrar un abono/cobro de una cuenta por cobrar. Punto de enganche para anotar
 * el ingreso en Finanzas.
 */
final class ReceivablePaymentRegistered
{
    use Dispatchable;

    public function __construct(public readonly ReceivablePayment $payment) {}
}
