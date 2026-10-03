<?php

declare(strict_types=1);

namespace App\Modules\ElectronicInvoicing\Domain;

use Carbon\CarbonInterface;

/**
 * El e-CF en forma canónica: independiente del XML y del proveedor.
 *
 * Es lo que el resto de BMIA (factura A4, punto de venta, compras) entrega al módulo. A partir de
 * aquí, `EcfXmlGenerator` calcula impuestos, arma el XML con el esquema oficial y lo valida.
 *
 * `declaredTotal` es lo que dice el documento comercial de origen (lo que se cobró); si se informa,
 * se contrasta con el total calculado del e-CF.
 *
 * @param  list<EcfLine>  $lines
 * @param  list<array{form: int, amount: string}>  $paymentForms
 */
final readonly class EcfDocument
{
    /**
     * @param  list<EcfLine>  $lines
     * @param  list<array{form: int, amount: string}>  $paymentForms
     */
    public function __construct(
        public EcfType $type,
        public string $encf,
        public CarbonInterface $issueDate,
        public EcfParty $emitter,
        public array $lines,
        public bool $pricesIncludeTax = true,
        public ?EcfParty $buyer = null,
        public ?CarbonInterface $sequenceExpiresAt = null,
        public string $incomeType = '01',
        public int $paymentType = 1,
        public ?CarbonInterface $paymentDueDate = null,
        public array $paymentForms = [],
        public ?string $declaredTotal = null,
        public ?EcfReference $reference = null,
    ) {}
}
