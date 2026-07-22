<?php

declare(strict_types=1);

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Enums\CancellationReason;
use App\Modules\Billing\Enums\InvoiceStatus;
use App\Modules\Billing\Enums\NcfType;
use App\Modules\Billing\Events\InvoiceCancelled;
use App\Modules\Billing\Events\InvoiceIssued;
use App\Modules\Billing\Exceptions\InvoiceException;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Support\TaxId;
use App\Modules\Sales\Enums\SaleStatus;
use App\Modules\Sales\Models\Sale;
use Illuminate\Support\Facades\DB;

/**
 * Emite y anula facturas fiscales. A partir de una venta completada, reserva un NCF de la
 * secuencia correspondiente y genera la factura con sus líneas (snapshot). Billing depende de
 * Sales y de FiscalSequenceService; Sales no conoce a Billing.
 */
final class InvoiceService
{
    public function __construct(private readonly FiscalSequenceService $sequences) {}

    public function issueForSale(
        Sale $sale,
        NcfType $type = NcfType::Consumo,
        ?string $customerTaxId = null,
    ): Invoice {
        return DB::transaction(function () use ($sale, $type, $customerTaxId): Invoice {
            if ($sale->status !== SaleStatus::Completed) {
                throw InvoiceException::saleNotCompleted($sale->id);
            }

            $alreadyInvoiced = Invoice::withoutCompanyScope()
                ->where('company_id', $sale->company_id)
                ->where('sale_id', $sale->id)
                ->exists();

            if ($alreadyInvoiced) {
                throw InvoiceException::alreadyInvoiced($sale->id);
            }

            $taxId = $this->validateTaxId($type, $customerTaxId);

            // El NCF se reserva al final: si algo falla antes, no se quema un número de la
            // secuencia autorizada (la DGII no permite huecos sin justificar).
            [$sequence, $ncf] = $this->sequences->allocate((int) $sale->company_id, $type);

            $invoice = Invoice::create([
                'company_id' => $sale->company_id,
                // La factura hereda el cliente de la venta: es el mismo negocio jurídico.
                'customer_id' => $sale->customer_id,
                'sale_id' => $sale->id,
                'fiscal_sequence_id' => $sequence->id,
                'ncf' => $ncf,
                'type' => $type,
                'customer_name' => $sale->customer_name,
                'customer_tax_id' => $taxId?->value,
                'subtotal' => $sale->subtotal,
                'tax' => $sale->tax,
                'total' => $sale->total,
                'status' => InvoiceStatus::Issued,
                'issued_at' => now(),
                'user_id' => auth()->id(),
            ]);

            $sale->load(['items.product' => fn ($query) => $query->withTrashed()]);

            foreach ($sale->items as $item) {
                $invoice->items()->create([
                    'company_id' => $sale->company_id,
                    'product_id' => $item->product_id,
                    'description' => $item->product->name,
                    'quantity' => $item->quantity,
                    'unit_price' => $item->unit_price,
                    'subtotal' => $item->subtotal,
                ]);
            }

            InvoiceIssued::dispatch($invoice);

            return $invoice->load('items');
        });
    }

    /**
     * Anula un comprobante. El NCF NO se libera ni se reutiliza: queda inutilizado y se reporta
     * en el formato 608 con su código de anulación. Por eso la factura se conserva.
     */
    public function cancel(Invoice $invoice, CancellationReason $reason, ?string $note = null): Invoice
    {
        if ($invoice->isCancelled()) {
            throw InvoiceException::alreadyCancelled((string) $invoice->ncf);
        }

        $invoice->update([
            'status' => InvoiceStatus::Cancelled,
            'cancellation_code' => $reason,
            'cancellation_note' => $note,
            'cancelled_at' => now(),
            'cancelled_by' => auth()->id(),
        ]);

        InvoiceCancelled::dispatch($invoice);

        return $invoice->refresh();
    }

    /**
     * Reglas fiscales del documento del cliente:
     *   - Crédito Fiscal y Gubernamental sustentan gasto: el RNC es obligatorio.
     *   - Consumo: es opcional, pero si se informa debe ser válido.
     */
    private function validateTaxId(NcfType $type, ?string $raw): ?TaxId
    {
        $raw = $raw === null || trim($raw) === '' ? null : trim($raw);

        if ($raw === null) {
            if ($type->requiresTaxId()) {
                throw InvoiceException::taxIdRequired($type);
            }

            return null;
        }

        return TaxId::tryParse($raw) ?? throw InvoiceException::invalidTaxId($raw);
    }
}
