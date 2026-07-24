<?php

declare(strict_types=1);

namespace App\Modules\Loans\Providers;

use App\Modules\Loans\Events\LoanDisbursed;
use App\Modules\Loans\Events\LoanPaymentRegistered;
use App\Modules\Loans\Listeners\RecordLoanDisbursement;
use App\Modules\Loans\Listeners\RecordLoanPayment;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

final class LoansServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // El préstamo mueve la caja igual que una venta: el desembolso es un egreso y cada cobro un
        // ingreso. Se contabiliza vía eventos para no acoplar el dominio a Finanzas.
        Event::listen(LoanDisbursed::class, RecordLoanDisbursement::class);
        Event::listen(LoanPaymentRegistered::class, RecordLoanPayment::class);
    }
}
