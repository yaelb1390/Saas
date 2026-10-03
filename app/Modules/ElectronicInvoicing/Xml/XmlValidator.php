<?php

declare(strict_types=1);

namespace App\Modules\ElectronicInvoicing\Xml;

use DOMDocument;
use DOMElement;
use LibXMLError;

/**
 * Valida un XML contra el esquema oficial de la DGII y traduce los errores a algo que un dueño de
 * negocio entiende.
 *
 * ANTES DE FIRMAR. El XSD del e-CF termina en `FechaHoraFirma` + un elemento obligatorio cualquiera
 * (el hueco de la firma), así que un XML sin firmar nunca validaría. Para validar antes de firmar —que
 * es cuando sirve: si falla, no se firma ni se envía— se valida una COPIA a la que se le pone una fecha
 * de firma y un marcador en ese hueco. El documento original no se toca y el XSD oficial tampoco.
 */
final class XmlValidator
{
    private const MARCADOR = 'FirmaPendienteDeValidacion';

    /**
     * Valida un documento todavía sin firmar.
     *
     * @return list<ValidationError>
     */
    public function validateUnsigned(DOMDocument $document, string $xsdPath): array
    {
        $copia = $this->copia($document);
        $raiz = $copia->documentElement;

        if ($raiz instanceof DOMElement && $this->esquemaPideFirma($xsdPath)) {
            if ($this->hijo($raiz, 'FechaHoraFirma') === null) {
                $fecha = $copia->createElement('FechaHoraFirma');
                $fecha->appendChild($copia->createTextNode(now()->setTimezone((string) config('ecf.formats.signature_utc_offset', '-04:00'))->format((string) config('ecf.formats.datetime', 'd-m-Y H:i:s'))));
                $raiz->appendChild($fecha);
            }

            $raiz->appendChild($copia->createElement(self::MARCADOR));
        }

        return $this->validar($copia, $xsdPath);
    }

    /**
     * Valida un documento ya firmado (o un esquema sin firma, como un acuse).
     *
     * @return list<ValidationError>
     */
    public function validate(DOMDocument $document, string $xsdPath): array
    {
        return $this->validar($this->copia($document), $xsdPath);
    }

    /** @return list<ValidationError> */
    private function validar(DOMDocument $doc, string $xsdPath): array
    {
        $anterior = libxml_use_internal_errors(true);
        libxml_clear_errors();

        try {
            // Los XSD oficiales son autocontenidos (sin import/include): validar no sale a la red.
            $doc->schemaValidate($xsdPath);
            $errores = array_map(fn (LibXMLError $e): ValidationError => $this->traducir($e), libxml_get_errors());
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($anterior);
        }

        return array_values($errores);
    }

    /**
     * Copia que no comparte nada con el original. Se carga sin red y sin expandir entidades (anti-XXE):
     * aunque el XML lo genera BMIA, la regla es la misma para todo lo que lea este módulo.
     */
    private function copia(DOMDocument $document): DOMDocument
    {
        $copia = new DOMDocument;
        $copia->preserveWhiteSpace = false;
        $copia->loadXML((string) $document->saveXML(), LIBXML_NONET);

        return $copia;
    }

    private function esquemaPideFirma(string $xsdPath): bool
    {
        $raiz = (new XsdTree)->root($xsdPath);

        foreach ($raiz->children as $hijo) {
            if ($hijo->isAny && $hijo->minOccurs > 0) {
                return true;
            }
        }

        return false;
    }

    private function hijo(DOMElement $padre, string $nombre): ?DOMElement
    {
        foreach ($padre->childNodes as $n) {
            if ($n instanceof DOMElement && $n->localName === $nombre) {
                return $n;
            }
        }

        return null;
    }

    /**
     * Mensajes de libxml → español claro. El texto original queda en `detail`.
     */
    private function traducir(LibXMLError $error): ValidationError
    {
        $crudo = trim($error->message);
        $campo = preg_match("/Element '(?:\\{[^}]*\\})?([^']+)'/", $crudo, $m) === 1 ? $m[1] : null;
        $valor = preg_match("/The value '([^']*)'/", $crudo, $v) === 1 ? $v[1] : null;
        $esperado = preg_match('/Expected is(?: one of)? \( ([^)]+) \)/', $crudo, $x) === 1
            ? implode(', ', array_map(fn (string $s): string => '«'.trim(preg_replace('/\{[^}]*\}/', '', $s)).'»', explode(',', $x[1])))
            : null;

        $nombre = $campo !== null ? "«{$campo}»" : 'un campo';

        $mensaje = match (true) {
            str_contains($crudo, 'Missing child element') => "Falta un dato obligatorio dentro de {$nombre}: se esperaba {$esperado}.",
            str_contains($crudo, 'This element is not expected') && $esperado !== null => "{$nombre} no va en esa posición o no corresponde a este tipo de comprobante; se esperaba {$esperado}.",
            str_contains($crudo, 'This element is not expected') => "{$nombre} no corresponde a este tipo de comprobante.",
            str_contains($crudo, "[facet 'enumeration']") => "El valor «{$valor}» no está permitido en {$nombre}.",
            str_contains($crudo, "[facet 'pattern']") => "{$nombre} no tiene el formato que exige la DGII (valor «{$valor}»).",
            str_contains($crudo, "[facet 'maxLength']") => "{$nombre} es más largo de lo que permite la DGII.",
            str_contains($crudo, "[facet 'minLength']") => "{$nombre} está vacío o es más corto de lo que exige la DGII.",
            str_contains($crudo, "[facet 'minExclusive']"), str_contains($crudo, "[facet 'minInclusive']") => "{$nombre} debe ser mayor (valor «{$valor}»).",
            str_contains($crudo, "[facet 'maxInclusive']"), str_contains($crudo, "[facet 'totalDigits']"), str_contains($crudo, "[facet 'fractionDigits']") => "{$nombre} tiene demasiadas cifras o decimales (valor «{$valor}»).",
            str_contains($crudo, 'is not a valid value') => "{$nombre} no tiene un valor válido (valor «{$valor}»).",
            default => 'El XML no cumple el esquema oficial de la DGII.',
        };

        return new ValidationError($mensaje, $campo, "línea {$error->line}: {$crudo}");
    }
}
