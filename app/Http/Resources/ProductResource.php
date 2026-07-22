<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Modules\Inventory\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Product
 */
final class ProductResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sku' => $this->sku,
            'name' => $this->name,
            'barcode' => $this->barcode,
            'unit' => $this->unit,
            'cost' => (string) $this->cost,
            'price' => (string) $this->price,
            'is_active' => (bool) $this->is_active,
            // El stock puede no estar cargado; se expone solo si viene con la relación.
            'stock' => $this->whenLoaded('stock', fn (): string => (string) $this->stock->sum('quantity')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
