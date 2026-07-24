<?php

declare(strict_types=1);

namespace App\Modules\Loans\Events;

use App\Modules\Loans\Models\LoanPayment;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Se dispara al registrar un abono/cobro de un préstamo. Punto de enganche para registrar el ingreso
 * en Finanzas y automatizaciones (n8n).
 */
final class LoanPaymentRegistered
{
    use Dispatchable;

    public function __construct(public readonly LoanPayment $payment) {}
}
