<?php

declare(strict_types=1);

namespace App\Modules\ElectronicInvoicing\Tax;

/**
 * Una línea de detalle tal como la pide el e-CF: cantidad, precio unitario, descuento, recargo e
 * indicador de facturación. Los importes van como cadenas decimales (bcmath), nunca como float.
 */
final readonly class TaxLine
{
    public function __construct(
        public string $quantity,
        public string $unitPrice,
        public BillingIndicator $indicator,
        public string $discount = '0',
        public string $surcharge = '0',
    ) {}
}
