<?php

declare(strict_types=1);

namespace App\Modules\Inventory\DTOs;

/**
 * DTO inmutable de una remesa que llega al almacén.
 *
 * Todo lo del proveedor es opcional: a diario se mete existencia sin rellenar nada, y obligar a
 * elegir proveedor para poder cargar dos cajas de refresco convertiría la pantalla en un trámite.
 */
final readonly class CreateGoodsReceiptData
{
    /**
     * @param  list<GoodsReceiptLineData>  $lines
     */
    public function __construct(
        public int $warehouseId,
        public array $lines,
        public string $receivedAt,
        public ?int $supplierId = null,
        public ?string $supplierName = null,
        public ?string $reference = null,
        public ?string $notes = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            warehouseId: (int) $data['warehouse_id'],
            lines: array_map(
                static fn (array $linea): GoodsReceiptLineData => GoodsReceiptLineData::fromArray($linea),
                array_values($data['lines'] ?? []),
            ),
            receivedAt: (string) ($data['received_at'] ?? now()->toDateString()),
            supplierId: isset($data['supplier_id']) && $data['supplier_id'] !== '' ? (int) $data['supplier_id'] : null,
            supplierName: self::opcional($data['supplier_name'] ?? null),
            reference: self::opcional($data['reference'] ?? null),
            notes: self::opcional($data['notes'] ?? null),
        );
    }

    private static function opcional(mixed $valor): ?string
    {
        return $valor === null || $valor === '' ? null : (string) $valor;
    }
}
