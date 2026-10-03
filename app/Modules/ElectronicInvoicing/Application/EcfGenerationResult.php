<?php

declare(strict_types=1);

namespace App\Modules\ElectronicInvoicing\Application;

use App\Modules\ElectronicInvoicing\Tax\TaxResult;
use App\Modules\ElectronicInvoicing\Xml\ValidationError;
use DOMDocument;

/**
 * Resultado de generar un e-CF. Si hay errores, `xml` es null: un documento que no valida no existe
 * para el resto del sistema (no se firma ni se envía).
 */
final readonly class EcfGenerationResult
{
    /**
     * @param  list<ValidationError>  $errors
     */
    public function __construct(
        public ?DOMDocument $xml,
        public array $errors,
        public ?TaxResult $tax,
        public ?string $schemaDate,
    ) {}

    public function isValid(): bool
    {
        return $this->xml !== null && $this->errors === [];
    }
}
