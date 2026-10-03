<?php

declare(strict_types=1);

namespace App\Modules\ElectronicInvoicing\Xml;

/**
 * Un elemento del esquema oficial, en el orden en que el XSD lo declara.
 *
 * `isAny` marca el `xs:any` del final del e-CF, que es el lugar de la firma.
 */
final class XsdNode
{
    /**
     * @param  list<XsdNode>  $children
     */
    public function __construct(
        public readonly string $name,
        public readonly int $minOccurs,
        public readonly ?int $maxOccurs,
        public readonly array $children = [],
        public readonly bool $isAny = false,
    ) {}

    public function isRepeatable(): bool
    {
        return $this->maxOccurs === null || $this->maxOccurs > 1;
    }

    public function child(string $name): ?self
    {
        foreach ($this->children as $hijo) {
            if ($hijo->name === $name) {
                return $hijo;
            }
        }

        return null;
    }
}
