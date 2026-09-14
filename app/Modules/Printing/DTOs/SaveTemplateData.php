<?php

declare(strict_types=1);

namespace App\Modules\Printing\DTOs;

/**
 * Los datos para guardar una plantilla de impresión.
 *
 * `layout` es el contrato entero del diseño —ver TemplateService::layoutPorDefecto() para su forma—,
 * así que el editor visual y el renderizador leen exactamente lo mismo.
 */
final readonly class SaveTemplateData
{
    /**
     * @param  array<string, mixed>  $layout
     */
    public function __construct(
        public string $documentType,
        public string $name,
        public string $paperSize,
        public array $layout,
        public bool $isDefault = false,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            documentType: (string) $data['document_type'],
            name: trim((string) $data['name']),
            paperSize: (string) $data['paper_size'],
            layout: is_array($data['layout'] ?? null) ? $data['layout'] : [],
            isDefault: (bool) ($data['is_default'] ?? false),
        );
    }
}
