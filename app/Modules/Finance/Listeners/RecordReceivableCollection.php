<?php

declare(strict_types=1);

namespace App\Modules\Finance\Listeners;

use App\Modules\Finance\Enums\MovementType;
use App\Modules\Finance\Events\ReceivablePaymentRegistered;
use App\Modules\Finance\Models\Account;
use App\Modules\Finance\Services\FinanceService;
use Throwable;

/**
 * Automatización: al registrarse un abono de una cuenta por cobrar, anota el ingreso en la cuenta
 * financiera que el cajero eligió. Defensivo: un fallo contable no debe deshacer el abono ya
 * registrado (el dinero ya entró; lo que fallaría es solo el apunte).
 */
final class RecordReceivableCollection
{
    public function __construct(private readonly FinanceService $finance) {}

    public function handle(ReceivablePaymentRegistered $event): void
    {
        $payment = $event->payment;
        // Sin esto, `$payment->receivable->code` sería lazy loading: revienta en producción, donde
        // está prohibido a propósito (ver el mismo cuidado en QuoteController::construirPdf()).
        $payment->loadMissing('receivable');

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
                MovementType::Income,
                (string) $payment->amount,
                "Cobro cuenta {$payment->receivable->code}",
                ['reference' => $payment, 'occurredAt' => $payment->paid_at],
            );
        } catch (Throwable $e) {
            report($e);
        }
    }
}
