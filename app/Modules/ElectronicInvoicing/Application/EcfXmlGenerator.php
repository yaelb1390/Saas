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

    /**
     * ¿Esta factura se envía como resumen (RFCE) en vez de completa? Solo el tipo 32 con total por
     * debajo del umbral oficial [DT pp.12,15; IT §9]. El e-CF completo se genera, firma y conserva
     * igual: lo que cambia es qué se ENVÍA.
     */
    public function sendsSummary(EcfDocument $doc, ?string $total): bool
    {
        return $doc->type->value === 32
            && $total !== null
            && bccomp($total, (string) config('ecf.consumo.rfce_threshold', '250000.00'), 2) < 0;
    }

    /**
     * El RFCE de una factura de consumo YA firmada: necesita su código de seguridad.
     */
    public function generateRfce(EcfDocument $doc, string $securityCode): EcfGenerationResult
    {
        $base = $this->generate($doc);

        if (! $base->isValid() || $base->tax === null) {
            return $base;
        }

        $ruta = $this->schemas->validationPath((string) config('ecf.schemas.rfce'));
        $xml = $this->builder->buildFromSchema($ruta, $this->mapper->toRfceArray($doc, $base->tax, $securityCode));
        $errores = $this->xmlValidator->validateUnsigned($xml, $ruta);

        return new EcfGenerationResult($errores === [] ? $xml : null, $errores, $base->tax, null);
    }
}
