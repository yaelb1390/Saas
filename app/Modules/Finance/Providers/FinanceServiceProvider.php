<?php

declare(strict_types=1);

namespace App\Modules\Finance\Providers;

use App\Modules\Core\Events\CompanyCreated;
use App\Modules\Finance\Listeners\ProvisionCompanyFinance;
use App\Modules\Finance\Listeners\RecordSaleIncome;
use App\Modules\Sales\Events\SaleCompleted;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

final class FinanceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Event::listen(CompanyCreated::class, ProvisionCompanyFinance::class);
        Event::listen(SaleCompleted::class, RecordSaleIncome::class);
    }
}
