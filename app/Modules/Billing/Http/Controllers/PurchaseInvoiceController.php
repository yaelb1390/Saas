<?php

declare(strict_types=1);

namespace App\Modules\Billing\Http\Controllers;

use App\Modules\Billing\Enums\GoodsServicesType;
use App\Modules\Billing\Http\Requests\StorePurchaseInvoiceRequest;
use App\Modules\Billing\Models\PurchaseInvoice;
use App\Modules\Billing\Services\DgiiReportService;
use App\Modules\Billing\Services\PurchaseInvoiceService;
use App\Modules\Billing\Support\TaxIdKind;
use App\Support\SimpleXlsx;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

/**
 * Comprobantes de compra (facturas recibidas) para armar el 606 de la DGII. Se sube la foto/PDF y se
 * registran los datos a mano (la extracción con IA se añade después). Todo aislado por empresa.
 */
final class PurchaseInvoiceController extends Controller
{
    public function index(): View
    {
        $period = $this->period();

        $invoices = PurchaseInvoice::query()
            ->whereBetween('invoice_date', [
                $period->copy()->startOfMonth()->toDateString(),
                $period->copy()->endOfMonth()->toDateString(),
            ])
            ->orderByDesc('invoice_date')
            ->orderByDesc('id')
            ->get();

        return view('panel.purchase-invoices', [
            'invoices' => $invoices,
            'period' => $period->format('Y-m'),
            'goodsServicesTypes' => GoodsServicesType::cases(),
            'taxIdKinds' => TaxIdKind::cases(),
            // Totales del mes para las tarjetas de resumen.
            'totalAmount' => (string) $invoices->sum('amount'),
            'totalItbis' => (string) $invoices->sum('itbis'),
        ]);
    }

    public function store(StorePurchaseInvoiceRequest $request, PurchaseInvoiceService $service): RedirectResponse
    {
        $service->create($request->validated(), $request->file('file'), auth()->id());

        return back()->with('panel_ok', 'Factura de compra registrada.');
    }

    public function update(StorePurchaseInvoiceRequest $request, PurchaseInvoice $purchaseInvoice, PurchaseInvoiceService $service): RedirectResponse
    {
        $service->update($purchaseInvoice, $request->validated(), $request->file('file'));

        return back()->with('panel_ok', 'Factura de compra actualizada.');
    }

    public function destroy(PurchaseInvoice $purchaseInvoice): RedirectResponse
    {
        $purchaseInvoice->delete();

        return back()->with('panel_ok', 'Factura de compra eliminada.');
    }

    /** Sirve el adjunto (foto/PDF) en el navegador. */
    public function showFile(PurchaseInvoice $purchaseInvoice): Response
    {
        abort_unless($purchaseInvoice->hasFile(), 404);

        return response(base64_decode((string) $purchaseInvoice->file_content, true) ?: '', 200, [
            'Content-Type' => (string) $purchaseInvoice->file_mime,
            'Content-Disposition' => 'inline; filename="'.addslashes((string) $purchaseInvoice->file_name).'"',
        ]);
    }

    /** Descarga el envío 606 en TXT oficial (Oficina Virtual de la DGII). */
    public function export606(DgiiReportService $dgii): Response
    {
        $period = $this->period();
        $content = $dgii->purchases606($period);

        return response($content, 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="606_'.$period->format('Ym').'.txt"',
        ]);
    }

    /** Descarga las compras del mes en Excel (respaldo/control). */
    public function exportExcel(DgiiReportService $dgii): Response
    {
        $period = $this->period();
        $table = $dgii->purchases606Table($period);

        $path = SimpleXlsx::write($table['headers'], $table['rows']);

        return response()->download($path, '606_'.$period->format('Ym').'.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }

    /** Período (mes) seleccionado; por defecto el mes actual. */
    private function period(): Carbon
    {
        return rescue(
            fn (): Carbon => Carbon::parse(request('period').'-01'),
            Carbon::now()->startOfMonth(),
            report: false,
        );
    }
}
