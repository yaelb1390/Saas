<?php

declare(strict_types=1);

namespace App\Modules\Inventory\DTOs;

/**
 * Una línea de la remesa.
 *
 * `updateCost` es la DECISIÓN de la persona, no una comparación: el sistema avisa cuando el costo
 * escrito no coincide con el guardado, pero quien sabe si fue una oferta puntual o el precio nuevo es
 * quien está mirando la factura.
 */
final readonly class GoodsReceiptLineData
{
    public function __construct(
        public int $productId,
        public string $quantity,
        public ?string $unitCost = null,
        public bool $updateCost = false,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $costo = $data['unit_cost'] ?? null;

        return new self(
            productId: (int) $data['product_id'],
            quantity: (string) $data['quantity'],
            unitCost: $costo === null || $costo === '' ? null : (string) $costo,
            updateCost: (bool) ($data['update_cost'] ?? false),
        );
    }
}
