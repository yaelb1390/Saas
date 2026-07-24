<?php

declare(strict_types=1);

namespace App\Modules\Loans\Services;

use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\CRM\Models\Customer;
use App\Modules\Loans\DTOs\CreateLoanData;
use App\Modules\Loans\Enums\InstallmentStatus;
use App\Modules\Loans\Enums\LoanStatus;
use App\Modules\Loans\Events\LoanDisbursed;
use App\Modules\Loans\Events\LoanPaymentRegistered;
use App\Modules\Loans\Exceptions\LoanException;
use App\Modules\Loans\Models\Loan;
use App\Modules\Loans\Models\LoanInstallment;
use App\Modules\Loans\Models\LoanPayment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Motor del préstamo informal: crea el préstamo con su calendario de amortización, aplica abonos a
 * las cuotas más antiguas primero, gestiona la mora y anula.
 *
 * Todo el dinero se maneja como string con bcmath (SCALE = 2); nunca float. El interés lo coloca el
 * administrador (tasa o monto); el interés es simple/plano sobre el capital (típico del prestamista
 * dominicano), no amortización sobre saldo.
 */
final class LoanService
{
    private const SCALE = 2;

    public function create(CreateLoanData $data): Loan
    {
        return DB::transaction(function () use ($data): Loan {
            $companyId = app(CurrentCompany::class)->id() ?? 0;
            $customer = $this->resolveCustomer($data->customerId, $companyId);

            $principal = $this->normalize($data->principal);
            $rate = $this->normalize($data->interestRate);

            // Interés MANUAL: si el admin escribió el monto, manda; si no, se calcula de la tasa como
            // porcentaje plano sobre el capital.
            $interest = $data->interestAmount !== null
                ? $this->normalize($data->interestAmount)
                : bcdiv(bcmul($principal, $rate, self::SCALE + 2), '100', self::SCALE);

            $total = bcadd($principal, $interest, self::SCALE);
            $count = max(1, $data->installmentsCount);
            $installment = bcdiv($total, (string) $count, self::SCALE);

            $loan = new Loan([
                'company_id' => $companyId,
                'customer_id' => $customer->id,
                'code' => $this->nextCode($companyId),
                'customer_name' => $customer->name,
                'principal' => $principal,
                'interest_rate' => $rate,
                'interest_amount' => $interest,
                'total' => $total,
                'frequency' => $data->frequency,
                'installments_count' => $count,
                'installment_amount' => $installment,
                'late_fee_rate' => $data->lateFeeRate !== null ? $this->normalize($data->lateFeeRate) : null,
                'start_date' => $data->startDate,
                'status' => LoanStatus::Active,
                'balance' => $total,
                'collateral' => $data->collateral,
                'notes' => $data->notes,
                'disbursed_at' => now(),
                'user_id' => auth()->id(),
            ]);
            $loan->save();

            $this->generateSchedule($loan, $principal, $interest, $count, $installment, $data);

            LoanDisbursed::dispatch($loan);

            return $loan;
        });
    }

    /**
     * Registra un abono: baja el saldo del préstamo y lo reparte entre las cuotas más antiguas no
     * saldadas (cubriendo también su mora). Si el saldo llega a cero, el préstamo queda saldado.
     *
     * @param  array{method?: ?string, note?: ?string}  $context
     */
    public function registerPayment(Loan $loan, string $amount, array $context = []): LoanPayment
    {
        return DB::transaction(function () use ($loan, $amount, $context): LoanPayment {
            /** @var Loan $loan */
            $loan = Loan::query()->whereKey($loan->id)->lockForUpdate()->firstOrFail();
            $amount = $this->normalize($amount);

            if (bccomp($amount, '0', self::SCALE) <= 0) {
                throw LoanException::invalidAmount();
            }
            if ($loan->status !== LoanStatus::Active) {
                throw LoanException::notActive();
            }
            if (bccomp($amount, (string) $loan->balance, self::SCALE) > 0) {
                throw LoanException::paymentExceedsBalance((string) $loan->balance);
            }

            $payment = new LoanPayment([
                'company_id' => $loan->company_id,
                'loan_id' => $loan->id,
                'amount' => $amount,
                'paid_at' => now(),
                'method' => $context['method'] ?? null,
                'note' => $context['note'] ?? null,
                'user_id' => auth()->id(),
            ]);
            $payment->save();

            $remaining = $amount;
            $pending = $loan->installments()
                ->where('status', '!=', InstallmentStatus::Paid->value)
                ->orderBy('number')
                ->get();

            foreach ($pending as $installment) {
                if (bccomp($remaining, '0', self::SCALE) <= 0) {
                    break;
                }

                $outstanding = $installment->outstanding();
                $apply = bccomp($remaining, $outstanding, self::SCALE) >= 0 ? $outstanding : $remaining;

                $installment->paid_amount = bcadd((string) $installment->paid_amount, $apply, self::SCALE);
                if (bccomp($installment->outstanding(), '0', self::SCALE) <= 0) {
                    $installment->status = InstallmentStatus::Paid;
                    $installment->paid_at = now();
                } else {
                    $installment->status = InstallmentStatus::Partial;
                }
                $installment->save();

                $remaining = bcsub($remaining, $apply, self::SCALE);
            }

            $loan->balance = bcsub((string) $loan->balance, $amount, self::SCALE);
            if (bccomp((string) $loan->balance, '0', self::SCALE) <= 0) {
                $loan->balance = '0.00';
                $loan->status = LoanStatus::Paid;
            }
            $loan->save();

            // Congela el saldo de este cobro para que el recibo (aunque se reimprima más tarde)
            // muestre el total adeudado de esta fecha, no el saldo actual.
            $payment->balance_after = $loan->balance;
            $payment->save();

            LoanPaymentRegistered::dispatch($payment);

            return $payment;
        });
    }

    /**
     * Fija/ajusta la mora de una cuota (decisión del administrador). Sube o baja el saldo del
     * préstamo por la diferencia y reevalúa los estados.
     */
    public function setInstallmentLateFee(Loan $loan, LoanInstallment $installment, string $amount): void
    {
        DB::transaction(function () use ($loan, $installment, $amount): void {
            $amount = $this->normalize($amount);
            if (bccomp($amount, '0', self::SCALE) < 0) {
                throw LoanException::invalidAmount();
            }

            /** @var Loan $loan */
            $loan = Loan::query()->whereKey($loan->id)->lockForUpdate()->firstOrFail();
            $delta = bcsub($amount, (string) $installment->late_fee, self::SCALE);

            $installment->late_fee = $amount;
            $installment->status = $this->installmentStatusFor($installment);
            $installment->save();

            $loan->balance = bcadd((string) $loan->balance, $delta, self::SCALE);
            if (bccomp((string) $loan->balance, '0', self::SCALE) <= 0) {
                $loan->balance = '0.00';
                if ($loan->status === LoanStatus::Active) {
                    $loan->status = LoanStatus::Paid;
                }
            } elseif ($loan->status === LoanStatus::Paid) {
                // La mora reabrió deuda en un préstamo que estaba saldado.
                $loan->status = LoanStatus::Active;
            }
            $loan->save();
        });
    }

    /**
     * Anula un préstamo. Solo si aún no tiene cobros: no reversa el egreso ya contabilizado del
     * desembolso (eso se ajusta a mano en Finanzas si hiciera falta).
     */
    public function cancel(Loan $loan): void
    {
        DB::transaction(function () use ($loan): void {
            /** @var Loan $loan */
            $loan = Loan::query()->whereKey($loan->id)->lockForUpdate()->firstOrFail();

            if ($loan->status === LoanStatus::Cancelled) {
                throw LoanException::alreadyCancelled();
            }
            if ($loan->payments()->exists()) {
                throw LoanException::hasPayments();
            }

            $loan->status = LoanStatus::Cancelled;
            $loan->save();
        });
    }

    /**
     * Genera las N cuotas. El capital y el interés se reparten por igual; la ÚLTIMA cuota absorbe
     * los centavos del redondeo para que la suma cuadre exactamente con el total.
     */
    private function generateSchedule(
        Loan $loan,
        string $principal,
        string $interest,
        int $count,
        string $installment,
        CreateLoanData $data,
    ): void {
        $capitalEach = bcdiv($principal, (string) $count, self::SCALE);
        $interestEach = bcdiv($interest, (string) $count, self::SCALE);

        $due = Carbon::parse($data->startDate);

        for ($n = 1; $n <= $count; $n++) {
            if ($n < $count) {
                $principalPortion = $capitalEach;
                $interestPortion = $interestEach;
                $amount = $installment;
            } else {
                // Última cuota: lo que reste, para que capital e interés sumen exacto.
                $principalPortion = bcsub($principal, bcmul($capitalEach, (string) ($count - 1), self::SCALE), self::SCALE);
                $interestPortion = bcsub($interest, bcmul($interestEach, (string) ($count - 1), self::SCALE), self::SCALE);
                $amount = bcadd($principalPortion, $interestPortion, self::SCALE);
            }

            $loan->installments()->create([
                'company_id' => $loan->company_id,
                'number' => $n,
                'due_date' => $due->copy(),
                'amount' => $amount,
                'principal_portion' => $principalPortion,
                'interest_portion' => $interestPortion,
                'late_fee' => '0',
                'paid_amount' => '0',
                'status' => InstallmentStatus::Pending,
            ]);

            $due = $loan->frequency->advance($due);
        }
    }

    private function installmentStatusFor(LoanInstallment $installment): InstallmentStatus
    {
        if (bccomp($installment->outstanding(), '0', self::SCALE) <= 0) {
            return InstallmentStatus::Paid;
        }

        return bccomp((string) $installment->paid_amount, '0', self::SCALE) > 0
            ? InstallmentStatus::Partial
            : InstallmentStatus::Pending;
    }

    private function resolveCustomer(int $customerId, int $companyId): Customer
    {
        /** @var Customer|null $customer */
        $customer = Customer::withoutCompanyScope()
            ->where('company_id', $companyId)
            ->whereKey($customerId)
            ->first();

        if ($customer === null) {
            throw LoanException::customerNotInCompany($customerId, $companyId);
        }

        return $customer;
    }

    private function nextCode(int $companyId): string
    {
        $count = Loan::withoutCompanyScope()
            ->where('company_id', $companyId)
            ->count();

        return 'PR-'.str_pad((string) ($count + 1), 6, '0', STR_PAD_LEFT);
    }

    /** Normaliza un número a string con 2 decimales para bcmath. */
    private function normalize(string $value): string
    {
        return bcadd($value === '' ? '0' : $value, '0', self::SCALE);
    }
}
