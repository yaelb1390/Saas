<?php

declare(strict_types=1);

namespace App\Modules\ElectronicInvoicing\Xml;

use DOMDocument;
use DOMElement;
use DOMXPath;
use RuntimeException;

/**
 * Lee el XSD oficial y devuelve el árbol de elementos en su orden.
 *
 * Es lo que permite NO escribir a mano un constructor por tipo de e-CF: el orden de los elementos,
 * qué se repite y qué existe en cada tipo lo dice el propio esquema de la DGII. Si la DGII publica un
 * esquema nuevo, se cambia el archivo y el XML sigue saliendo en el orden correcto.
 *
 * Solo entiende lo que usan los XSD del e-CF v1.0: `xs:element` con tipo anónimo
 * (`xs:complexType/xs:sequence`) o con tipo simple con nombre, y `xs:any`. Si un esquema futuro usa
 * otra construcción (`ref`, `complexType` con nombre, `choice`), se detiene con un error en vez de
 * generar un XML a medias.
 */
final class XsdTree
{
    private const XS = 'http://www.w3.org/2001/XMLSchema';

    /** @var array<string, XsdNode> */
    private static array $cache = [];

    public function root(string $xsdPath): XsdNode
    {
        $clave = $xsdPath.'|'.(is_file($xsdPath) ? filemtime($xsdPath) : '');

        return self::$cache[$clave] ??= $this->parse($xsdPath);
    }

    private function parse(string $xsdPath): XsdNode
    {
        $doc = new DOMDocument;

        // Sin red y sin entidades: el esquema es un archivo local de confianza, pero la regla es la
        // misma para todo XML que lea este módulo.
        if (! is_file($xsdPath) || ! $doc->load($xsdPath, LIBXML_NONET)) {
            throw new RuntimeException("No se pudo leer el esquema {$xsdPath}.");
        }

        $xpath = new DOMXPath($doc);
        $xpath->registerNamespace('xs', self::XS);

        $raiz = $xpath->query('/xs:schema/xs:element')->item(0);

        if (! $raiz instanceof DOMElement) {
            throw new RuntimeException("El esquema {$xsdPath} no declara un elemento raíz.");
        }

        return $this->node($xpath, $raiz);
    }

    private function node(DOMXPath $xpath, DOMElement $elemento): XsdNode
    {
        if ($elemento->hasAttribute('ref')) {
            throw new RuntimeException('Construcción de XSD no soportada: xs:element con ref.');
        }

        $hijos = [];

        foreach ($xpath->query('./xs:complexType/*', $elemento) as $modelo) {
            if (! $modelo instanceof DOMElement || $modelo->localName === 'attribute') {
                continue;
            }

            if ($modelo->localName !== 'sequence') {
                throw new RuntimeException("Construcción de XSD no soportada: xs:{$modelo->localName} en «{$elemento->getAttribute('name')}».");
            }

            foreach ($xpath->query('./*', $modelo) as $hijo) {
                if (! $hijo instanceof DOMElement) {
                    continue;
                }

                $hijos[] = match ($hijo->localName) {
                    'element' => $this->node($xpath, $hijo),
                    'any' => new XsdNode('*', $this->min($hijo), $this->max($hijo), isAny: true),
                    default => throw new RuntimeException("Construcción de XSD no soportada: xs:{$hijo->localName}."),
                };
            }
        }

        return new XsdNode($elemento->getAttribute('name'), $this->min($elemento), $this->max($elemento), $hijos);
    }

    private function min(DOMElement $e): int
    {
        return $e->hasAttribute('minOccurs') ? (int) $e->getAttribute('minOccurs') : 1;
    }

    private function max(DOMElement $e): ?int
    {
        if (! $e->hasAttribute('maxOccurs')) {
            return 1;
        }

        $max = $e->getAttribute('maxOccurs');

        return $max === 'unbounded' ? null : (int) $max;
    }
}
