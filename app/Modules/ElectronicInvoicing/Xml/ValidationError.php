<?php

declare(strict_types=1);

namespace App\Modules\ElectronicInvoicing\Xml;

/**
 * Un problema encontrado al validar: `message` es para el usuario; `detail` es el texto técnico
 * original (de libxml o de la regla), para soporte.
 */
final readonly class ValidationError
{
    public function __construct(
        public string $message,
        public ?string $field = null,
        public ?string $detail = null,
    ) {}
}
