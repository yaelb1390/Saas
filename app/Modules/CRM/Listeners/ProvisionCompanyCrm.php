<?php

declare(strict_types=1);

namespace App\Modules\CRM\Listeners;

use App\Modules\Core\Events\CompanyCreated;
use App\Modules\CRM\Services\CrmProvisioner;

/**
 * Al crear una empresa, provisiona su pipeline de CRM por defecto.
 */
final class ProvisionCompanyCrm
{
    public function __construct(private readonly CrmProvisioner $provisioner) {}

    public function handle(CompanyCreated $event): void
    {
        $this->provisioner->provisionFor($event->company);
    }
}
