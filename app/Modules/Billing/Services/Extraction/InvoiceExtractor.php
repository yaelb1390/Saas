<?php

declare(strict_types=1);

namespace App\Modules\Billing\Services\Extraction;

/**
 * Extractor de datos de una factura a partir de su archivo (foto/PDF).
 *
 * Punto de extensión: hoy la implementación por defecto (NullInvoiceExtractor) devuelve un borrador
 * vacío (entrada manual). Cuando haya una API key de IA, una implementación con visión leerá el
 * archivo y devolverá los campos pre-llenados con la MISMA forma, sin cambiar el resto del módulo.
 */
interface InvoiceExtractor
{
    /**
     * Devuelve un borrador de campos del comprobante (claves como provider_name, provider_tax_id,
     * ncf, invoice_date, amount, itbis, ...). Vacío = sin extracción, se llena a mano.
     *
     * @return array<string, mixed>
     */
    public function extract(string $fileContent, string $mime): array;
}
