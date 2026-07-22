<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\DTOs;

/**
 * DTO inmutable para crear una orden de compra con sus líneas.
 */
final readonly class CreatePurchaseOrderData
{
    /**
     * @param  array<int, PurchaseOrderLineData>  $lines
     */
    public function __construct(
        public int $supplierId,
        public int $warehouseId,
        public array $lines,
        public string $tax = '0',
        public ?string $notes = null,
    ) {}

    /**
     * Construye el DTO desde los datos ya validados.
     *
     * El mapeo vive aquí y no en el controlador para que cualquier entrada (panel, API, importación)
     * arme la orden igual, sin repetir la traducción del formulario.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            supplierId: (int) $data['supplier_id'],
            warehouseId: (int) $data['warehouse_id'],
            lines: array_map(
                static fn (array $line): PurchaseOrderLineData => PurchaseOrderLineData::fromArray($line),
                array_values($data['lines']),
            ),
            tax: (string) ($data['tax'] ?? '0'),
            notes: $data['notes'] ?? null,
        );
    }

    /**
     * Suma de las líneas (subtotal de la orden) con 2 decimales.
     */
    public function subtotal(): string
    {
        $subtotal = '0';

        foreach ($this->lines as $line) {
            $subtotal = bcadd($subtotal, $line->subtotal(), 2);
        }

        return $subtotal;
    }

    public function total(): string
    {
        return bcadd($this->subtotal(), $this->tax, 2);
    }
}
