<?php

declare(strict_types=1);

namespace App\Modules\Billing\Services\Extraction;

/**
 * Extractor por defecto (sin IA): no lee el archivo, devuelve un borrador vacío para que el usuario
 * escriba los datos a mano. Se reemplaza por una implementación con visión cuando haya API key.
 */
final class NullInvoiceExtractor implements InvoiceExtractor
{
    public function extract(string $fileContent, string $mime): array
    {
        return [];
    }
}
