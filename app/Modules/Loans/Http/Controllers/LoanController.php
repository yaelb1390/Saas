<?php

declare(strict_types=1);

namespace App\Modules\Loans\Http\Controllers;

use App\Modules\CRM\DTOs\CreateCustomerData;
use App\Modules\CRM\Services\CrmService;
use App\Modules\Loans\DTOs\CreateLoanData;
use App\Modules\Loans\Http\Requests\RegisterLoanPaymentRequest;
use App\Modules\Loans\Http\Requests\SetLateFeeRequest;
use App\Modules\Loans\Http\Requests\StoreLoanRequest;
use App\Modules\Loans\Models\Loan;
use App\Modules\Loans\Models\LoanInstallment;
use App\Modules\Loans\Models\LoanPayment;
use App\Modules\Loans\Services\LoanService;
use Barryvdh\DomPDF\Facade\Pdf;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\Response;

/**
 * Acciones de escritura de préstamos desde el panel. Delgado: valida (Form Request), delega en el
 * LoanService y traduce las reglas de dominio a mensajes HTTP. El route model binding resuelve
 * `{loan}` ya aislado por la empresa activa (un préstamo de otra empresa devuelve 404).
 */
final class LoanController extends Controller
{
    public function store(StoreLoanRequest $request, LoanService $loans, CrmService $crm): RedirectResponse
    {
        $data = $request->validated();

        // Cliente nuevo escrito a mano: se registra al vuelo y se usa su id para el préstamo.
        if (empty($data['customer_id']) && ! empty($data['new_customer_name'])) {
            $customer = $crm->createCustomer(new CreateCustomerData(
                name: (string) $data['new_customer_name'],
                phone: $data['new_customer_phone'] ?? null,
                cedula: $data['new_customer_cedula'] ?? null,
            ));
            $data['customer_id'] = $customer->id;
        }

        try {
            $loan = $loans->create(CreateLoanData::fromArray($data));
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
            $payment = $loans->registerPayment($loan, (string) $data['amount'], [
                'method' => $data['method'] ?? null,
                'note' => $data['note'] ?? null,
            ]);
        } catch (DomainException $e) {
            return back()->with('panel_error', $e->getMessage());
        }

        // El id del cobro deja que el detalle ofrezca "Imprimir recibo" del abono recién hecho.
        return back()
            ->with('panel_ok', 'Abono registrado.')
            ->with('loan_receipt_payment_id', $payment->id);
    }

    public function receipt(Loan $loan, LoanPayment $payment): View
    {
        return view('loans.receipt', $this->receiptData($loan, $payment));
    }

    /**
     * Recibo del cobro en PDF de 80mm (rollo térmico): para imprimir, enviar o archivar.
     */
    public function receiptPdf(Loan $loan, LoanPayment $payment, ?string $mode = null): Response
    {
        $data = $this->receiptData($loan, $payment);

        // 80mm ≈ 226.77 pt. Alto fijo: el recibo del cobro no tiene líneas variables.
        $pdf = Pdf::loadView('loans.receipt-pdf', $data)->setPaper([0, 0, 226.77, 420]);
        $filename = 'recibo-cobro-'.$loan->code.'.pdf';

        return $mode === 'descargar' ? $pdf->download($filename) : $pdf->stream($filename);
    }

    /**
     * Datos compartidos por el recibo HTML y el PDF. La cuota pagada a la fecha se deriva en la vista
     * de $loan->total − $payment->balance_after.
     *
     * @return array{loan: Loan, payment: LoanPayment, company: mixed}
     */
    private function receiptData(Loan $loan, LoanPayment $payment): array
    {
        // El binding aísla por empresa; falta atar el cobro a ESTE préstamo.
        abort_if($payment->loan_id !== $loan->id, 404);

        $loan->load(['customer', 'company']);

        return [
            'loan' => $loan,
            'payment' => $payment,
            'company' => $loan->company,
        ];
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
