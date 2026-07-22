<?php

declare(strict_types=1);

namespace App\Modules\Core\Tenancy;

/**
 * Implementado por los modelos aislados por empresa (los que usan BelongsToCompany).
 * Permite al CompanyScope conocer, de forma tipada, la columna del tenant.
 */
interface HasCompany
{
    public function getCompanyIdColumn(): string;
}
