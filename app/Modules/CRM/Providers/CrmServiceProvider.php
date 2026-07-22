<?php

declare(strict_types=1);

namespace App\Modules\CRM\Providers;

use App\Modules\Core\Events\CompanyCreated;
use App\Modules\CRM\Listeners\ProvisionCompanyCrm;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

final class CrmServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Event::listen(CompanyCreated::class, ProvisionCompanyCrm::class);
    }
}
