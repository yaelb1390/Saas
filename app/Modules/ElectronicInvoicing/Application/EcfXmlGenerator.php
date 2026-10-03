<?php

declare(strict_types=1);

namespace App\Modules\ElectronicInvoicing\Application;

use App\Modules\ElectronicInvoicing\Domain\EcfDocument;
use App\Modules\ElectronicInvoicing\Domain\EcfLine;
use App\Modules\ElectronicInvoicing\Tax\TaxEngine;
use App\Modules\ElectronicInvoicing\Tax\TaxLine;
use App\Modules\ElectronicInvoicing\Xml\EcfDataMapper;
use App\Modules\ElectronicInvoicing\Xml\EcfDocumentValidator;
use App\Modules\ElectronicInvoicing\Xml\EcfXmlBuilder;
use App\Modules\ElectronicInvoicing\Xml\SchemaRegistry;
use App\Modules\ElectronicInvoicing\Xml\ValidationError;
use App\Modules\ElectronicInvoicing\Xml\XmlValidator;
use InvalidArgumentException;

/**
 * Del documento canónico al XML validado, sin firmar.
 *
 *   reglas de negocio → impuestos → árbol de datos → XML (orden del XSD) → validación contra el XSD.
 *
 * Si cualquier paso falla, no hay XML: se devuelven los errores para enseñárselos al usuario, y nada
 * llega a la firma ni al envío. El resultado lleva la fecha del esquema oficial usado, que se
 * guardará con el documento (la DGII cambia contenido sin subir la versión).
 */
final class EcfXmlGenerator
{
    public function __construct(
        private readonly TaxEngine $tax,
        private readonly EcfDocumentValidator $documentValidator,
        private readonly EcfDataMapper $mapper,
        private readonly EcfXmlBuilder $builder,
        private readonly XmlValidator $xmlValidator,
        private readonly SchemaRegistry $schemas,
    ) {}

    public function generate(EcfDocument $doc): EcfGenerationResult
    {
        try {
            $impuestos = $this->tax->calculate(
                array_map(fn (EcfLine $l): TaxLine => new TaxLine($l->quantity, $l->unitPrice, $l->indicator, $l->discount), $doc->lines),
                $doc->pricesIncludeTax,
            );
        } catch (InvalidArgumentException $e) {
            return new EcfGenerationResult(null, [new ValidationError($e->getMessage(), 'Item')], null, null);
        }

        $errores = $this->documentValidator->validate($doc, $impuestos);

        if ($errores !== []) {
            return new EcfGenerationResult(null, $errores, $impuestos, null);
        }

        $xml = $this->builder->build($doc->type, $this->mapper->toArray($doc, $impuestos));
        // Ruta de VALIDACIÓN: la oficial, o su copia con erratas si la DGII la publicó con defectos.
        $errores = $this->xmlValidator->validateUnsigned($xml, $this->schemas->validationPathForType($doc->type));

        return new EcfGenerationResult(
            $errores === [] ? $xml : null,
            $errores,
            $impuestos,
            $this->schemas->schemaDate($doc->type),
        );
    }
}
