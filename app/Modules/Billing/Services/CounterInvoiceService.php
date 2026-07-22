<?php

declare(strict_types=1);

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Enums\NcfType;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Cash\Enums\CashSessionStatus;
use App\Modules\Cash\Models\CashSession;
use App\Modules\POS\Services\CheckoutService;
use App\Modules\Sales\DTOs\CreateSaleData;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Services\SaleService;
use Illuminate\Support\Facades\DB;

/**
 * Mostrador de repuestos: facturación directa. En un único paso registra la venta (descontando
 * stock) y emite el comprobante fiscal (NCF). Orquesta módulos ya existentes sin reescribirlos:
 *   - Si hay una caja abierta, cobra por ella (CheckoutService → movimiento de caja).
 *   - Si no, completa la venta sin caja (SaleService directo).
 *   - Emite el NCF con InvoiceService.
 *
 * Todo en una transacción externa: si la emisión del NCF falla (secuencia agotada, RNC inválido),
 * se revierte también la venta. En un mostrador de facturación no queremos stock descontado sin
 * comprobante.
 */
final class CounterInvoiceService
{
    public function __construct(
        private readonly CheckoutService $checkout,
        private readonly SaleService $sales,
        private readonly InvoiceService $invoices,
    ) {}

    /**
     * @return array{sale: Sale, invoice: Invoice}
     */
    public function invoice(CreateSaleData $data, NcfType $type, ?string $customerTaxId = null): array
    {
        return DB::transaction(function () use ($data, $type, $customerTaxId): array {
            $session = CashSession::query()
                ->where('status', CashSessionStatus::Open)
                ->latest('opened_at')
                ->first();

            $sale = $session !== null
                ? $this->checkout->checkout($session, $data)
                : $this->sales->complete($data);

            $invoice = $this->invoices->issueForSale($sale, $type, $customerTaxId);

            return ['sale' => $sale, 'invoice' => $invoice];
        });
    }
}
