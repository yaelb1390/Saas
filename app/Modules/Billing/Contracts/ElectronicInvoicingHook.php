<?php

declare(strict_types=1);

namespace App\Modules\Billing\Contracts;

use App\Modules\Billing\Enums\NcfType;
use App\Modules\Billing\Models\Invoice;
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
}
