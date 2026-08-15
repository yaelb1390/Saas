<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Controllers;

use App\Modules\Billing\Models\Invoice;
use App\Modules\Core\Support\CompanyLogoStore;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Services\SaleVoidService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
     * Anula varias ventas a la vez.
     *
     * «Anular» y no «borrar»: además de retirarlas del historial, devuelve el stock y saca el cobro
     * de la caja (ver SaleVoidService). Las que no se pueden tocar —facturadas o con el arqueo ya
     * cerrado— se saltan y se explica por qué, en vez de fallar entera la operación por una sola.
     */
    public function bulkVoid(Request $request, SaleVoidService $voids): RedirectResponse
    {
        $datos = $request->validate([
            'ids' => ['array'],
            'ids.*' => ['integer'],
        ]);

        // El ámbito de empresa lo pone el modelo: un id de otra empresa no aparece en la consulta
        // aunque se envíe a mano.
        $ventas = Sale::query()->with('items.product')->whereIn('id', $datos['ids'] ?? [])->get();

        if ($ventas->isEmpty()) {
            return back()->with('panel_error', 'No se seleccionó ninguna venta.');
        }

        $anuladas = 0;
        $facturadas = 0;
        $cajaCerrada = 0;

        foreach ($ventas as $venta) {
            $motivo = $voids->motivoParaNoAnular($venta);

            match ($motivo) {
                SaleVoidService::MOTIVO_FACTURADA => $facturadas++,
                SaleVoidService::MOTIVO_CAJA_CERRADA => $cajaCerrada++,
                default => tap($anuladas++, fn () => $voids->void($venta)),
            };
        }

        $pendientes = array_filter([
            $facturadas > 0 ? "{$facturadas} con factura fiscal (anúlalas por la vía fiscal)" : null,
            $cajaCerrada > 0 ? "{$cajaCerrada} con el arqueo de caja ya cerrado" : null,
        ]);

        if ($anuladas === 0) {
            return back()->with('panel_error', 'No se anuló ninguna venta: '.implode(' y ', $pendientes).'.');
        }

        $mensaje = $anuladas === 1
            ? 'Venta anulada. Se devolvió el stock y se retiró el cobro de la caja.'
            : "{$anuladas} ventas anuladas. Se devolvió el stock y se retiraron los cobros de la caja.";

        return back()->with('panel_ok', $pendientes === []
            ? $mensaje
            : $mensaje.' Se saltaron '.implode(' y ', $pendientes).'.');
    }

    /**
     * Recibo en PDF de 80mm (rollo térmico). Sirve para imprimir con margen fijo, enviar por
     * WhatsApp/correo o archivar. El alto del papel se calcula según el número de líneas para no
     * dejar rollo en blanco de más (dompdf no autoajusta la altura de la página).
     */
    public function receiptPdf(Sale $sale, ?string $mode = null): Response
    {
        $data = $this->receiptData($sale);

        /*
         * 80 mm ≈ 226.77 pt de ancho. El alto se calcula: parte fija + una banda por artículo.
         *
         * La base era 360 pt y NO daba: el recibo salía en dos páginas incluso con un solo artículo,
         * así que cada venta imprimía una segunda hoja casi en blanco. Se midió por búsqueda binaria
         * cuál es el alto mínimo con el que cabe en una —519 pt— y de ahí sale esta cifra, con un
         * pequeño respiro para las direcciones largas que ocupan dos renglones.
         *
         * El logo suma aparte porque ocupa un alto que la parte fija no contempla.
         */
        $width = 226.77;
        $height = 540 + ($sale->items->count() * 30)
            + ($sale->company?->hasLogo() ? CompanyLogoStore::PDF_ESPACIO_PT : 0);

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
        // `items.options` trae el tamaño y los sabores congelados al vender: sin precargarlos, el
        // recibo dispararía una consulta por línea.
        $sale->load(['items.product', 'items.employee', 'items.options', 'employee', 'company']);

        return [
            'sale' => $sale,
            'company' => $sale->company,
            'invoice' => Invoice::query()->where('sale_id', $sale->id)->first(),
        ];
    }
}
