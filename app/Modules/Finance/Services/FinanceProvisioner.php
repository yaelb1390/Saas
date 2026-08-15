<?php

declare(strict_types=1);

namespace App\Modules\Finance\Services;

use App\Modules\Core\Models\Company;
use App\Modules\Finance\Enums\AccountType;
use App\Modules\Finance\Models\Account;
use App\Modules\Finance\Models\ExpenseCategory;

/**
 * Deja Finanzas lista al dar de alta una empresa: la cuenta por defecto y los conceptos de gasto.
 */
final class FinanceProvisioner
{
    public function provisionFor(Company $company): Account
    {
        $cuenta = Account::create([
            'company_id' => $company->id,
            'name' => 'Caja General',
            'type' => AccountType::Cash,
            'balance' => '0',
            'is_default' => true,
            'is_active' => true,
        ]);

        $this->conceptosDeGasto($company);

        return $cuenta;
    }

    /**
     * Conceptos con los que arranca la empresa.
     *
     * Se crean aquí y no se dejan para que el cliente los invente porque el primer gasto llega antes
     * que las ganas de configurar nada: si para anotar la factura de la luz hubiera que crear la
     * categoría «Luz», se anotaría en la primera que hubiese y el informe por concepto nacería
     * inservible.
     *
     * `firstOrCreate` para que sea idempotente: reaprovisionar una empresa no debe duplicarlos.
     */
    private function conceptosDeGasto(Company $company): void
    {
        foreach (ExpenseCategory::INICIALES as $nombre) {
            ExpenseCategory::withoutGlobalScopes()->firstOrCreate(
                ['company_id' => $company->id, 'name' => $nombre],
                ['is_active' => true],
            );
        }
    }
}
