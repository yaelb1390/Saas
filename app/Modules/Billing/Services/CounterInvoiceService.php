<?php

declare(strict_types=1);

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Enums\NcfType;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Cash\Enums\CashSessionStatus;
use App\Modules\Cash\Exceptions\CashSessionException;
use App\Modules\Cash\Models\CashSession;
use App\Modules\POS\Services\CheckoutService;
use App\Modules\Sales\DTOs\CreateSaleData;
use App\Modules\Sales\Models\Sale;
use Illuminate\Support\Facades\DB;

/**
 * Mostrador de repuestos: facturación directa. En un único paso registra la venta (descontando
 * stock) y emite el comprobante fiscal (NCF). Orquesta módulos ya existentes sin reescribirlos:
 *   - Exige una caja abierta y cobra por ella (CheckoutService → movimiento de caja).
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

            /*
             * SIN TURNO ABIERTO NO SE FACTURA.
             *
             * Antes había una alternativa: sin caja, la venta se registraba igual con `SaleService`.
             * Parecía cómodo y costaba caro — esa factura no entraba en ningún arqueo, así que el
             * dinero cobrado no aparecía en el cierre y el descuadre se descubría al contar el
             * efectivo, sin forma de saber de qué venta venía.
             *
             * Ahora se exige, como ya hacían Venta rápida y Punto de Venta. Es la misma regla en las
             * tres pantallas, y vive aquí y no en el controlador para que valga también para
             * cualquier otra vía que llegue a facturar en el futuro.
             */
            if ($session === null) {
                throw CashSessionException::notOpen();
            }

            $sale = $this->checkout->checkout($session, $data);
            $invoice = $this->invoices->issueForSale($sale, $type, $customerTaxId);

            return ['sale' => $sale, 'invoice' => $invoice];
        });
    }
}
