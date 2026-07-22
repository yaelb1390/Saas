<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Controllers;

use App\Modules\Billing\Models\Invoice;
use App\Modules\Sales\Models\Sale;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Contracts\View\View;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\Response;

/**
 * Vistas de una venta. El recibo es una página imprimible (ticket) resuelta por route model
 * binding, ya aislada por la empresa activa.
 */
final class SaleController extends Controller
{
    public function receipt(Sale $sale): View
    {
        return view('sales.receipt', $this->receiptData($sale));
    }

    /**
     * Recibo en PDF de 80mm (rollo térmico). Sirve para imprimir con margen fijo, enviar por
     * WhatsApp/correo o archivar. El alto del papel se calcula según el número de líneas para no
     * dejar rollo en blanco de más (dompdf no autoajusta la altura de la página).
     */
    public function receiptPdf(Sale $sale, ?string $mode = null): Response
    {
        $data = $this->receiptData($sale);

        // 80mm ≈ 226.77 pt. Alto = cabecera/pie fijos + una banda por cada línea de artículo.
        $width = 226.77;
        $height = 360 + ($sale->items->count() * 30);

        $pdf = Pdf::loadView('sales.receipt-pdf', $data)
            ->setPaper([0, 0, $width, $height]);

        $filename = 'recibo-'.$sale->code.'.pdf';

        // ?descargar fuerza la descarga; por defecto se muestra en el navegador (para imprimir).
        return $mode === 'descargar'
            ? $pdf->download($filename)
            : $pdf->stream($filename);
    }

    /**
     * Datos compartidos por el recibo HTML y el PDF.
     *
     * @return array{sale: Sale, company: mixed, invoice: Invoice|null}
     */
    private function receiptData(Sale $sale): array
    {
        $sale->load(['items.product', 'items.employee', 'employee', 'company']);

        return [
            'sale' => $sale,
            'company' => $sale->company,
            'invoice' => Invoice::query()->where('sale_id', $sale->id)->first(),
        ];
    }
}
