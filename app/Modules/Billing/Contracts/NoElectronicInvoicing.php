<?php

declare(strict_types=1);

namespace App\Modules\Billing\Contracts;

use App\Modules\Billing\Enums\CancellationReason;
use App\Modules\Billing\Enums\NcfType;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Support\TaxId;
use App\Modules\Sales\Models\Sale;

/** Sin facturación electrónica: solo serie B, exactamente como siempre. */
final class NoElectronicInvoicing implements ElectronicInvoicingHook
{
    public function replaceNcf(Sale $sale, NcfType $type, ?TaxId $taxId): ?array
    {
        return null;
    }

    public function afterInvoiceCreated(Invoice $invoice, Sale $sale, ?TaxId $taxId): void {}

    public function beforeCancel(Invoice $invoice, CancellationReason $reason, ?string $note): void {}
}
