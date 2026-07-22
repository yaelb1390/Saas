<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Modules\Sales\Models\Sale;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Sale
 */
final class SaleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'status' => $this->status->value,
            'customer_name' => $this->customer_name,
            'subtotal' => (string) $this->subtotal,
            'tax' => (string) $this->tax,
            'total' => (string) $this->total,
            'payment_method' => $this->payment_method,
            'completed_at' => $this->completed_at?->toIso8601String(),
            'items' => $this->whenLoaded('items', fn (): array => $this->items->map(fn ($item): array => [
                'product_id' => $item->product_id,
                'quantity' => (string) $item->quantity,
                'unit_price' => (string) $item->unit_price,
                'subtotal' => (string) $item->subtotal,
            ])->all()),
        ];
    }
}
