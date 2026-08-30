<?php

declare(strict_types=1);

namespace App\Modules\Dealer\Providers;

use App\Modules\Dealer\Events\VehicleExpenseRecorded;
use App\Modules\Dealer\Listeners\RecordVehicleExpense;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

final class DealerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Lo que se gasta en una unidad sale de la caja igual que cualquier otro gasto. Se contabiliza
        // por evento para no atar el patio a Finanzas: un dealer sin ese módulo registra sus gastos
        // igual, solo que nadie escucha.
        Event::listen(VehicleExpenseRecorded::class, RecordVehicleExpense::class);
    }
}
