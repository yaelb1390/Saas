<?php

declare(strict_types=1);

namespace App\Modules\Core\Listeners;

use App\Modules\Core\Events\CompanyCreated;
use App\Modules\Core\Services\RoleProvisioner;

/**
 * Al crear una empresa, provisiona automáticamente sus roles y permisos por defecto.
 */
final class ProvisionCompanyRoles
{
    public function __construct(private readonly RoleProvisioner $provisioner) {}

    public function handle(CompanyCreated $event): void
    {
        $this->provisioner->provisionFor($event->company);
    }
}
