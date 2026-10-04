<?php

declare(strict_types=1);

namespace App\Modules\Billing\Http\Controllers;

use App\Modules\Billing\Contracts\ElectronicInvoicingHook;
use App\Modules\Billing\Enums\CancellationReason;
use App\Modules\Billing\Enums\NcfType;
use App\Modules\Billing\Exceptions\FiscalSequenceException;
use App\Modules\Billing\Exceptions\InvoiceException;
use App\Modules\Billing\Http\Requests\CancelInvoiceRequest;
use App\Modules\Billing\Http\Requests\IssueInvoiceRequest;
use App\Modules\Billing\Http\Requests\StoreFiscalSequenceRequest;
use App\Modules\Billing\Models\FiscalSequence;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Services\InvoiceService;
use App\Modules\Core\Support\CompanyLogoStore;
use App\Modules\Sales\Models\Sale;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

/**
 * Facturación fiscal desde el panel: emitir el comprobante de una venta, anularlo y registrar
 * las secuencias de NCF autorizadas por la DGII. La lógica vive en InvoiceService.
 */
final class InvoiceController extends Controller
{
    public function issue(IssueInvoiceRequest $request, InvoiceService $invoices): RedirectResponse
    {
        $data = $request->validated();

        // El scope de empresa ya aísla la venta: un id ajeno simplemente no existe.
        $sale = Sale::query()->findOrFail($data['sale_id']);

        try {
            $invoice = $invoices->issueForSale(
                sale: $sale,
                type: NcfType::from($data['type']),
                customerTaxId: $data['customer_tax_id'] ?? null,
            );
        } catch (InvoiceException|FiscalSequenceException $e) {
            // Errores de dominio: el usuario puede corregirlos (RNC inválido, secuencia agotada...).
            return back()->withInput()->with('panel_error', $e->getMessage());
        }

        return back()->with('panel_ok', "Comprobante {$invoice->ncf} emitido correctamente.");
    }

    public function cancel(CancelInvoiceRequest $request, Invoice $invoice, InvoiceService $invoices): RedirectResponse
    {
        $data = $request->validated();

        try {
            $invoices->cancel($invoice, CancellationReason::from($data['reason']), $data['note'] ?? null);
        } catch (InvoiceException $e) {
            return back()->with('panel_error', $e->getMessage());
        }

        // Un e-CF no se anula ante la DGII: se revierte con una nota de crédito electrónica.
        return back()->with('panel_ok', str_starts_with((string) $invoice->ncf, 'E')
            ? "Comprobante {$invoice->ncf} anulado con su nota de crédito electrónica."
            : "Comprobante {$invoice->ncf} anulado. Se reportará en el 608.");
    }

    /**
     * El PDF del comprobante ya emitido, en A4, con el mismo layout compartido que la cotización.
     */
    public function pdf(Invoice $invoice, ElectronicInvoicingHook $electronic, ?string $mode = null): Response
    {
        $invoice->loadMissing('items', 'customer', 'sale', 'user');

        $pdf = Pdf::loadView('invoices.pdf', [
            'invoice' => $invoice,
            // Representación impresa del e-CF (timbre con QR) si el comprobante es electrónico.
            'timbre' => $electronic->printedRepresentation($invoice),
            'company' => $invoice->company,
            'logo' => $invoice->company?->hasLogo() ? CompanyLogoStore::dataUri($invoice->company) : null,
        ])->setPaper('a4');

        $nombre = 'factura-'.$invoice->numeroInterno().'.pdf';

        return $mode === 'descargar' ? $pdf->download($nombre) : $pdf->stream($nombre);
    }

    public function storeSequence(StoreFiscalSequenceRequest $request): RedirectResponse
    {
        $data = $request->validated();

        // company_id lo asigna el trait BelongsToCompany; la numeración arranca en el inicio del rango.
        FiscalSequence::create([
            ...$data,
            'next_number' => $data['range_from'],
            'is_active' => true,
        ]);

        return back()->with('panel_ok', 'Secuencia de NCF registrada.');
    }
}
