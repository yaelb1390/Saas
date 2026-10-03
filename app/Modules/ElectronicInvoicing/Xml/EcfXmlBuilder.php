<?php

declare(strict_types=1);

namespace App\Modules\ElectronicInvoicing\Xml;

use App\Modules\ElectronicInvoicing\Domain\EcfType;
use DOMDocument;
use DOMElement;

/**
 * Construye el XML de un e-CF recorriendo el XSD oficial de su tipo.
 *
 * No hay un `build31()`, `build32()`… escrito a mano: los diez tipos comparten este código y lo que
 * cambia entre ellos —qué elementos existen, en qué orden, cuáles se repiten— lo dice el esquema de la
 * DGII. Los datos llegan como un árbol de arrays con los nombres oficiales de los elementos.
 *
 * Reglas:
 *   · un valor null o '' no se escribe (el elemento es opcional o condicional);
 *   · un nombre que el esquema no tiene es un ERROR, nunca se descarta en silencio: un campo mal
 *     escrito o inventado no puede acabar fuera del XML sin que nadie lo sepa;
 *   · que falte un elemento obligatorio no se comprueba aquí sino en `XmlValidator`, contra el XSD;
 *   · el XML se construye con DOM, nunca concatenando texto: un «<» en el nombre de un producto no
 *     puede inyectar etiquetas.
 *
 * La firma (el `xs:any` final) y `FechaHoraFirma` son cosa de la firma (fase 3): aquí solo se escribe
 * `FechaHoraFirma` si viene en los datos.
 */
final class EcfXmlBuilder
{
    public function __construct(
        private readonly SchemaRegistry $schemas,
        private readonly XsdTree $xsd,
    ) {}

    /**
     * @param  array<string, mixed>  $data  árbol con los nombres de los elementos del XSD
     */
    public function build(EcfType $type, array $data): DOMDocument
    {
        return $this->buildFromSchema($this->schemas->pathForType($type), $data);
    }

    /**
     * Para los esquemas que no son un tipo de e-CF (RFCE, ANECF, ACECF…).
     *
     * @param  array<string, mixed>  $data
     */
    public function buildFromSchema(string $xsdPath, array $data): DOMDocument
    {
        $raiz = $this->xsd->root($xsdPath);

        $doc = new DOMDocument('1.0', 'utf-8');
        // Sin espacios ni saltos de línea añadidos: el documento que se firma es exactamente este.
        $doc->preserveWhiteSpace = false;
        $doc->formatOutput = false;

        $elemento = $doc->createElement($raiz->name);
        $doc->appendChild($elemento);

        $this->fill($doc, $elemento, $raiz, $data, $raiz->name);

        return $doc;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function fill(DOMDocument $doc, DOMElement $padre, XsdNode $nodo, array $data, string $ruta): void
    {
        // Solo los que traen dato: un null significa «este tipo no lo lleva» y no hay nada que escribir.
        foreach ($data as $clave => $valor) {
            if ($valor !== null && $nodo->child((string) $clave) === null) {
                throw XmlBuildException::unknownElement("{$ruta}.{$clave}");
            }
        }

        foreach ($nodo->children as $hijo) {
            if ($hijo->isAny || ! array_key_exists($hijo->name, $data)) {
                continue;
            }

            $valor = $data[$hijo->name];
            $rutaHijo = "{$ruta}.{$hijo->name}";

            if ($hijo->isRepeatable()) {
                if (! is_array($valor) || ! array_is_list($valor)) {
                    throw XmlBuildException::expectedList($rutaHijo);
                }

                if ($hijo->maxOccurs !== null && count($valor) > $hijo->maxOccurs) {
                    throw XmlBuildException::tooMany($rutaHijo, $hijo->maxOccurs);
                }

                foreach ($valor as $i => $ocurrencia) {
                    $this->append($doc, $padre, $hijo, $ocurrencia, "{$rutaHijo}[{$i}]");
                }

                continue;
            }

            $this->append($doc, $padre, $hijo, $valor, $rutaHijo);
        }
    }

    private function append(DOMDocument $doc, DOMElement $padre, XsdNode $nodo, mixed $valor, string $ruta): void
    {
        if ($valor === null || $valor === '') {
            return;
        }

        $elemento = $doc->createElement($nodo->name);

        if ($nodo->children !== []) {
            if (! is_array($valor)) {
                throw XmlBuildException::expectedGroup($ruta);
            }

            $this->fill($doc, $elemento, $nodo, $valor, $ruta);

            // Un grupo OPCIONAL que se quedó sin nada dentro no se escribe. Uno obligatorio sí, vacío:
            // p. ej. <Comprador/> en una factura de consumo a un cliente sin identificar, que el XSD
            // exige como elemento aunque todos sus campos sean opcionales.
            if (! $elemento->hasChildNodes() && $nodo->minOccurs === 0) {
                return;
            }
        } else {
            if (is_array($valor) || is_object($valor)) {
                throw XmlBuildException::expectedValue($ruta);
            }

            // createTextNode escapa «<», «&»…: el texto nunca se interpreta como marcado.
            $elemento->appendChild($doc->createTextNode(is_bool($valor) ? ($valor ? '1' : '0') : (string) $valor));
        }

        $padre->appendChild($elemento);
    }
}
