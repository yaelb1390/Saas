<?php

declare(strict_types=1);

namespace App\Modules\Printing\Support;

use App\Modules\Billing\Models\Invoice;
use App\Modules\Printing\DTOs\PrintableDocumentData;
use App\Modules\Sales\Models\Sale;

/**
 * Convierte una Venta al contrato genérico que entiende `DocumentRenderer`.
 *
 * ES LA INTEGRACIÓN DE REFERENCIA del Centro de Impresión: la que demuestra que el renderizador
 * genérico sirve para un documento real, no solo para la vista previa con datos de muestra. Cuando
 * Préstamos, Inventario o cualquier otro módulo se conecten, escriben un adaptador igual de chico que
 * este —nunca tocan `DocumentRenderer` ni las plantillas—.
 */
final class SaleTicketAdapter
{
    /**
     * `$invoice` llega aparte y no por una relación: `Sale` no tiene `invoice()` —el comprobante
     * fiscal se busca por `sale_id` desde `Invoice`, como ya hace `SalesController::receiptData()`—.
     */
    public static function desde(Sale $sale, ?Invoice $invoice = null): PrintableDocumentData
    {
        $meta = [
            ['label' => 'Fecha', 'value' => ($sale->completed_at ?? $sale->created_at)?->format('d/m/Y H:i') ?? ''],
            ['label' => 'Cliente', 'value' => $sale->customer_name ?? 'Consumidor final'],
            ['label' => 'Pago', 'value' => ucfirst((string) $sale->payment_method)],
        ];

        if ($invoice?->ncf) {
            $meta[] = ['label' => 'NCF', 'value' => $invoice->ncf];
        }

        $lineas = $sale->items->map(static function ($item): array {
            $detalle = collect([
                number_format((float) $item->unit_price, 2).' c/u',
                $item->options->isNotEmpty() ? $item->options->pluck('option_name')->implode(' · ') : null,
                $item->serial ? 'Serie: '.$item->serial : null,
            ])->filter()->implode(' · ');

            return [
                'qty' => rtrim(rtrim(number_format((float) $item->quantity, 3), '0'), '.'),
                'description' => $item->product?->name ?? 'Producto',
                'detail' => $detalle,
                'amount' => number_format((float) $item->subtotal, 2),
            ];
        })->all();

        $totales = [
            ['label' => 'Subtotal', 'value' => number_format((float) $sale->subtotal, 2), 'emphasis' => false],
        ];
        if ((float) $sale->discount_total > 0) {
            $totales[] = ['label' => 'Descuento', 'value' => '-'.number_format((float) $sale->discount_total, 2), 'emphasis' => false];
        }
        $totales[] = ['label' => 'ITBIS', 'value' => number_format((float) $sale->tax, 2), 'emphasis' => false];
        if ((float) $sale->tip > 0) {
            $totales[] = ['label' => 'Propina', 'value' => number_format((float) $sale->tip, 2), 'emphasis' => false];
        }
        $totales[] = ['label' => 'TOTAL', 'value' => number_format((float) $sale->total, 2), 'emphasis' => true];
        $totales[] = ['label' => 'Pagado', 'value' => number_format((float) $sale->paid, 2), 'emphasis' => false];
        $totales[] = ['label' => 'Cambio', 'value' => number_format((float) $sale->change, 2), 'emphasis' => false];

        return new PrintableDocumentData(
            title: 'Ticket de venta',
            reference: $sale->code,
            meta: $meta,
            lines: $lineas,
            totals: $totales,
            note: $sale->employee ? 'Atendió: '.$sale->employee->name : null,
        );
    }
}
