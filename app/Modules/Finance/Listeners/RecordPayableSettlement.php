<?php

declare(strict_types=1);

namespace App\Modules\Finance\Listeners;

use App\Modules\Cash\Enums\CashMovementType;
use App\Modules\Cash\Enums\CashSessionStatus;
use App\Modules\Cash\Models\CashSession;
use App\Modules\Cash\Services\CashService;
use App\Modules\Finance\Enums\AccountType;
use App\Modules\Finance\Enums\MovementType;
use App\Modules\Finance\Events\PayablePaymentRegistered;
use App\Modules\Finance\Models\Account;
use App\Modules\Finance\Services\FinanceService;
use Throwable;

/**
 * Automatización: al pagarle a un proveedor, toca hasta DOS sitios, igual que un Gasto
 * (`ExpenseService::create()`):
 *
 *   1. La CUENTA de la que sale (siempre).
 *   2. El CAJÓN, solo si la cuenta es de efectivo y hay un turno abierto — si no, un pago en
 *      efectivo desde el cajón dejaría el arqueo del turno sin enterarse de esa salida.
 *
 * Defensivo: un fallo contable no debe deshacer el pago ya registrado.
 */
final class RecordPayableSettlement
{
    public function __construct(
        private readonly FinanceService $finance,
        private readonly CashService $cash,
    ) {}

    public function handle(PayablePaymentRegistered $event): void
    {
        $payment = $event->payment;
        // Sin esto, `$payment->payable->code` sería lazy loading: revienta en producción.
        $payment->loadMissing('payable');

        /** @var Account|null $account */
        $account = Account::withoutCompanyScope()
            ->where('company_id', $payment->company_id)
            ->find($payment->account_id);

        if ($account === null) {
            return;
        }

        try {
            $this->finance->record(
                $account,
                MovementType::Expense,
                (string) $payment->amount,
                "Pago cuenta {$payment->payable->code}",
                ['reference' => $payment, 'occurredAt' => $payment->paid_at],
            );

            if ($account->type === AccountType::Cash) {
                $sesion = CashSession::withoutCompanyScope()
                    ->where('company_id', $payment->company_id)
                    ->where('status', CashSessionStatus::Open)
                    ->latest('opened_at')
                    ->first();

                if ($sesion !== null) {
                    $this->cash->registerMovement($sesion, CashMovementType::Expense, (string) $payment->amount, [
                        'reference' => $payment,
                        'notes' => "Pago cuenta {$payment->payable->code}",
                    ]);
                }
            }
        } catch (Throwable $e) {
            report($e);
        }
    }
}
