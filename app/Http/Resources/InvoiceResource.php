<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Modules\Billing\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Invoice
 */
final class InvoiceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'ncf' => $this->ncf,
            'type' => $this->type->value,
            'status' => $this->status->value,
            'customer_name' => $this->customer_name,
            'customer_tax_id' => $this->customer_tax_id,
            'subtotal' => (string) $this->subtotal,
            'tax' => (string) $this->tax,
            'total' => (string) $this->total,
            'sale_id' => $this->sale_id,
            'issued_at' => $this->issued_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
        ];
    }
}
