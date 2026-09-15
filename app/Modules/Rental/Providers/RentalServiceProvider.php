<?php

declare(strict_types=1);

namespace App\Modules\Rental\Providers;

use App\Modules\Rental\Events\RentalPaymentRegistered;
use App\Modules\Rental\Listeners\RecordRentalIncome;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

final class RentalServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Event::listen(RentalPaymentRegistered::class, RecordRentalIncome::class);
    }
}
