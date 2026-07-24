<?php

declare(strict_types=1);

namespace App\Modules\Loans\Http\Controllers;

use App\Modules\Loans\DTOs\CreateLoanData;
use App\Modules\Loans\Http\Requests\RegisterLoanPaymentRequest;
use App\Modules\Loans\Http\Requests\SetLateFeeRequest;
use App\Modules\Loans\Http\Requests\StoreLoanRequest;
use App\Modules\Loans\Models\Loan;
use App\Modules\Loans\Models\LoanInstallment;
use App\Modules\Loans\Services\LoanService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;

/**
 * Acciones de escritura de préstamos desde el panel. Delgado: valida (Form Request), delega en el
 * LoanService y traduce las reglas de dominio a mensajes HTTP. El route model binding resuelve
 * `{loan}` ya aislado por la empresa activa (un préstamo de otra empresa devuelve 404).
 */
final class LoanController extends Controller
{
    public function store(StoreLoanRequest $request, LoanService $loans): RedirectResponse
    {
        try {
            $loan = $loans->create(CreateLoanData::fromArray($request->validated()));
        } catch (DomainException $e) {
            return back()->with('panel_error', $e->getMessage());
        }

        return redirect()
            ->route('panel.loans.show', $loan)
            ->with('panel_ok', "Préstamo {$loan->code} desembolsado.");
    }

    public function payment(RegisterLoanPaymentRequest $request, Loan $loan, LoanService $loans): RedirectResponse
    {
        $data = $request->validated();

        try {
            $loans->registerPayment($loan, (string) $data['amount'], [
                'method' => $data['method'] ?? null,
                'note' => $data['note'] ?? null,
            ]);
        } catch (DomainException $e) {
            return back()->with('panel_error', $e->getMessage());
        }

        return back()->with('panel_ok', 'Abono registrado.');
    }

    public function setFee(SetLateFeeRequest $request, Loan $loan, LoanInstallment $installment, LoanService $loans): RedirectResponse
    {
        // El binding aísla por empresa; falta atar la cuota a ESTE préstamo.
        abort_if($installment->loan_id !== $loan->id, 404);

        try {
            $loans->setInstallmentLateFee($loan, $installment, (string) $request->validated()['amount']);
        } catch (DomainException $e) {
            return back()->with('panel_error', $e->getMessage());
        }

        return back()->with('panel_ok', "Mora de la cuota {$installment->number} actualizada.");
    }

    public function cancel(Loan $loan, LoanService $loans): RedirectResponse
    {
        try {
            $loans->cancel($loan);
        } catch (DomainException $e) {
            return back()->with('panel_error', $e->getMessage());
        }

        return redirect()
            ->route('panel.loans')
            ->with('panel_ok', "Préstamo {$loan->code} anulado.");
    }
}
