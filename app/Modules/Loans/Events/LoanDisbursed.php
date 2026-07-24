<?php

declare(strict_types=1);

namespace App\Modules\Loans\Events;

use App\Modules\Loans\Models\Loan;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Se dispara al desembolsar un préstamo (entregar el capital). Punto de enganche para registrar el
 * egreso en Finanzas y automatizaciones (n8n).
 */
final class LoanDisbursed
{
    use Dispatchable;

    public function __construct(public readonly Loan $loan) {}
}
