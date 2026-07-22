<?php

declare(strict_types=1);

namespace App\Modules\Finance\Listeners;

use App\Modules\Core\Events\CompanyCreated;
use App\Modules\Finance\Services\FinanceProvisioner;

/**
 * Al crear una empresa, provisiona su cuenta financiera por defecto.
 */
final class ProvisionCompanyFinance
{
    public function __construct(private readonly FinanceProvisioner $provisioner) {}

    public function handle(CompanyCreated $event): void
    {
        $this->provisioner->provisionFor($event->company);
    }
}
