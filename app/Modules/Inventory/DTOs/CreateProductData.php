<?php

declare(strict_types=1);

namespace App\Modules\Inventory\DTOs;

/**
 * DTO inmutable para crear un producto.
 */
final readonly class CreateProductData
{
    public function __construct(
        public string $sku,
        public string $name,
        public ?int $categoryId = null,
        public ?string $description = null,
        public ?string $barcode = null,
        public string $unit = 'unidad',
        public string $cost = '0',
        public string $price = '0',
        public bool $trackStock = true,
        // Datos de repuesto (opcionales).
        public ?string $partNumber = null,
        public ?string $brand = null,
        public ?string $vehicleMake = null,
        public ?string $vehicleModel = null,
        public ?int $yearFrom = null,
        public ?int $yearTo = null,
        public ?string $location = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            sku: (string) $data['sku'],
            name: (string) $data['name'],
            // Del formulario, category_id llega como texto («1») o vacío. Con tipado estricto, pasar
            // un string a ?int reventaba (TypeError → error 500) en cuanto se elegía una categoría.
            categoryId: filled($data['category_id'] ?? null) ? (int) $data['category_id'] : null,
            description: $data['description'] ?? null,
            barcode: $data['barcode'] ?? null,
            unit: $data['unit'] ?? 'unidad',
            cost: (string) ($data['cost'] ?? '0'),
            price: (string) ($data['price'] ?? '0'),
            trackStock: (bool) ($data['track_stock'] ?? true),
            partNumber: filled($data['part_number'] ?? null) ? (string) $data['part_number'] : null,
            brand: filled($data['brand'] ?? null) ? (string) $data['brand'] : null,
            vehicleMake: filled($data['vehicle_make'] ?? null) ? (string) $data['vehicle_make'] : null,
            vehicleModel: filled($data['vehicle_model'] ?? null) ? (string) $data['vehicle_model'] : null,
            yearFrom: filled($data['year_from'] ?? null) ? (int) $data['year_from'] : null,
            yearTo: filled($data['year_to'] ?? null) ? (int) $data['year_to'] : null,
            location: filled($data['location'] ?? null) ? (string) $data['location'] : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toAttributes(): array
    {
        return [
            'sku' => $this->sku,
            'name' => $this->name,
            'category_id' => $this->categoryId,
            'description' => $this->description,
            'barcode' => $this->barcode,
            'unit' => $this->unit,
            'cost' => $this->cost,
            'price' => $this->price,
            'track_stock' => $this->trackStock,
            'part_number' => $this->partNumber,
            'brand' => $this->brand,
            'vehicle_make' => $this->vehicleMake,
            'vehicle_model' => $this->vehicleModel,
            'year_from' => $this->yearFrom,
            'year_to' => $this->yearTo,
            'location' => $this->location,
        ];
    }
}
