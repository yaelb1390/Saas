<?php

declare(strict_types=1);

namespace App\Modules\Printing\DTOs;

/**
 * Los datos para registrar o editar una impresora.
 *
 * `settings` agrupa lo que varía por fabricante y por tipo de conexión —márgenes, orientación,
 * calidad, copias por omisión, corte automático, color, y qué trae el ticket (logo, encabezado, pie,
 * datos de la empresa, teléfono, dirección, RNC, NCF)—. Va como array y no como una propiedad por
 * campo porque una impresora de etiquetas no tiene "calidad de impresión" y una térmica no tiene
 * "bandeja": forzar todas las columnas a existir para todas las impresoras sería mentir con el modelo.
 */
final readonly class SavePrinterData
{
    /**
     * @param  array<string, mixed>  $settings
     */
    public function __construct(
        public string $name,
        public string $connectionType,
        public string $paperSize,
        public ?string $manufacturer = null,
        public ?string $model = null,
        public ?int $customWidthMm = null,
        public ?int $customHeightMm = null,
        public ?string $btDeviceId = null,
        public ?string $btServiceUuid = null,
        public ?string $btCharacteristicUuid = null,
        public ?string $address = null,
        public array $settings = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            name: trim((string) $data['name']),
            connectionType: (string) $data['connection_type'],
            paperSize: (string) $data['paper_size'],
            manufacturer: self::opcional($data['manufacturer'] ?? null),
            model: self::opcional($data['model'] ?? null),
            customWidthMm: isset($data['custom_width_mm']) && $data['custom_width_mm'] !== '' ? (int) $data['custom_width_mm'] : null,
            customHeightMm: isset($data['custom_height_mm']) && $data['custom_height_mm'] !== '' ? (int) $data['custom_height_mm'] : null,
            btDeviceId: self::opcional($data['bt_device_id'] ?? null),
            btServiceUuid: self::opcional($data['bt_service_uuid'] ?? null),
            btCharacteristicUuid: self::opcional($data['bt_characteristic_uuid'] ?? null),
            address: self::opcional($data['address'] ?? null),
            settings: is_array($data['settings'] ?? null) ? $data['settings'] : [],
        );
    }

    private static function opcional(mixed $valor): ?string
    {
        return $valor === null || $valor === '' ? null : (string) $valor;
    }
}
