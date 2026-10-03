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
        // Retenciones que hace quien emite (41 compras, 47 pagos al exterior) [FMT ítem campos 6–7].
        public ?string $itbisWithheld = null,
        public ?string $isrWithheld = null,
    ) {}

    public function hasRetention(): bool
    {
        return $this->itbisWithheld !== null || $this->isrWithheld !== null;
    }
}
