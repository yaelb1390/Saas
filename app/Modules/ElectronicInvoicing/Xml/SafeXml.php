<?php

declare(strict_types=1);

namespace App\Modules\ElectronicInvoicing\Xml;

use DOMDocument;
use RuntimeException;

/**
 * Carga XML que llega de FUERA (DGII, otros contribuyentes): sin red, sin entidades y rechazando
 * DOCTYPE (anti-XXE). Sin preservar espacios, como exige el estándar de firmado [DTEE «Firmado de XML»].
 */
final class SafeXml
{
    public static function load(string $xml): DOMDocument
    {
        if (stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false) {
            throw new RuntimeException('XML rechazado: contiene DOCTYPE o entidades.');
        }

        $doc = new DOMDocument;
        $doc->preserveWhiteSpace = false;

        $previo = libxml_use_internal_errors(true);
        $ok = $xml !== '' && $doc->loadXML($xml, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previo);

        if (! $ok) {
            throw new RuntimeException('XML ilegible.');
        }

        return $doc;
    }
}
