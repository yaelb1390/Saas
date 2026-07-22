<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\DTOs;

/**
 * Una línea de una orden de compra (producto, cantidad y costo unitario).
 */
final readonly class PurchaseOrderLineData
{
    public function __construct(
        public int $productId,
        public string $quantity,
        public string $unitCost,
    ) {}

    /**
     * @param  array<string, mixed>  $line
     */
    public static function fromArray(array $line): self
    {
        return new self(
            productId: (int) $line['product_id'],
            quantity: (string) $line['quantity'],
            unitCost: (string) $line['unit_cost'],
        );
    }

    /**
     * Importe de la línea (cantidad x costo unitario) con 2 decimales.
     */
    public function subtotal(): string
    {
        return bcmul($this->quantity, $this->unitCost, 2);
    }
}
