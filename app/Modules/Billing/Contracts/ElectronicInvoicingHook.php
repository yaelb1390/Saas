<?php

declare(strict_types=1);

namespace App\Modules\Billing\Contracts;

use App\Modules\Billing\Enums\CancellationReason;
use App\Modules\Billing\Enums\NcfType;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\PurchaseInvoice;
use App\Modules\Billing\Support\TaxId;
use App\Modules\Sales\Models\Sale;

/**
 * Lo que la facturación necesita de la facturación electrónica, sin depender de ella.
 *
 * Billing declara el contrato y el módulo de e-CF lo implementa (inversión de dependencias): sin el
 * módulo —o con él apagado— la implementación por omisión no hace nada y la serie B sigue intacta.
 *
 * Las dos llamadas ocurren DENTRO de la transacción de la factura; cualquier envío a la DGII se hace
 * después de confirmarla.
 */
interface ElectronicInvoicingHook
{
    /**
     * Si la empresa emite e-CF EN LUGAR de la serie B para este tipo, prepara el e-CF y devuelve su
     * número y su id; si no, null (y se usa la serie B). Lanza si el documento no se puede emitir: en
     * modo real no hay comprobante alternativo.
     *
     * @return array{ncf: string, electronic_invoice_id: int}|null
     */
    public function replaceNcf(Sale $sale, NcfType $type, ?TaxId $taxId): ?array;

    /** Después de crear la factura (con su NCF definitivo). Nunca lanza. */
    public function afterInvoiceCreated(Invoice $invoice, Sale $sale, ?TaxId $taxId): void;

    /**
     * Antes de anular. Un e-CF no se «anula»: se revierte con una nota de crédito que lo referencia.
     * Si el comprobante es un e-CF (modo real) y la nota no se puede emitir, lanza y la anulación no
     * ocurre. Con la serie B no hace nada (va al 608 como siempre).
     */
    public function beforeCancel(Invoice $invoice, CancellationReason $reason, ?string $note): void;

    /**
     * Compra a un proveedor informal: la empresa emite el comprobante de compras (`compras`, e-CF 41)
     * o de gastos menores (`gastos_menores`, e-CF 43). En modo real devuelve el e-NCF que sustituye al
     * NCF en papel (y lanza si no se puede emitir); en otro caso, null. `$purchase` aún no está guardada.
     *
     * @return array{ncf: string, electronic_invoice_id: int}|null
     */
    public function replacePurchaseNcf(PurchaseInvoice $purchase, string $kind, bool $isService): ?array;

    /**
     * Lo que la representación impresa de la factura tiene que llevar si su comprobante es un e-CF
     * (tipo en palabras, vencimiento, QR, código de seguridad…); null si es de la serie B. Nunca lanza.
     *
     * @return array<string, mixed>|null
     */
    public function printedRepresentation(Invoice $invoice): ?array;

    /** Después de guardar la compra. En paralelo genera el e-CF de prueba. Nunca lanza. */
    public function afterPurchaseCreated(PurchaseInvoice $purchase, string $kind, bool $isService): void;
}
