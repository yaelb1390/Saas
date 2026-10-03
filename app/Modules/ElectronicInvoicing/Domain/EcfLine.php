<?php

declare(strict_types=1);

namespace App\Modules\ElectronicInvoicing\Domain;

use App\Modules\ElectronicInvoicing\Tax\BillingIndicator;

/**
 * Una línea del detalle. Importes como cadenas decimales (bcmath), nunca float.
 */
final readonly class EcfLine
{
    public function __construct(
        public string $name,
        public string $quantity,
        public string $unitPrice,
        public BillingIndicator $indicator,
        public bool $isService = false,
        public string $discount = '0',
        public ?string $description = null,
    ) {}
}
