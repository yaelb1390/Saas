<?php

declare(strict_types=1);

namespace App\Modules\Finance\Services;

use App\Modules\Core\Models\Company;
use App\Modules\Finance\Enums\AccountType;
use App\Modules\Finance\Models\Account;

/**
 * Crea la cuenta financiera por defecto al dar de alta una empresa.
 */
final class FinanceProvisioner
{
    public function provisionFor(Company $company): Account
    {
        return Account::create([
            'company_id' => $company->id,
            'name' => 'Caja General',
            'type' => AccountType::Cash,
            'balance' => '0',
            'is_default' => true,
            'is_active' => true,
        ]);
    }
}
