<?php

declare(strict_types=1);

namespace App\Modules\ElectronicInvoicing\Tax;

/**
 * Totales de un documento calculados como los define el Formato e-CF [FMT sección A «Totales»].
 *
 * `chargedTotal` es lo que el cliente pagó (la suma de los montos de ítem) y `difference`, lo que
 * se separa del `total` del e-CF por redondeo cuando los precios incluyen ITBIS. No se esconde: la
 * DGII no publica tolerancia (ver pendientes) y alguien tiene que poder verlo.
 */
final readonly class TaxResult
{
    /**
     * @param  array<int, string>  $lineAmounts  monto de cada ítem [FMT campo 39], en el orden de entrada
     * @param  array<int, string>  $taxedBase  monto gravado por indicador (1, 2, 3)
     * @param  array<int, string>  $itbis  ITBIS por indicador (1, 2, 3)
     */
    public function __construct(
        public array $lineAmounts,
        public array $taxedBase,
        public string $taxedTotal,
        public string $exempt,
        public array $itbis,
        public string $itbisTotal,
        public string $total,
        public string $chargedTotal,
        public string $difference,
        public bool $pricesIncludeTax,
    ) {}
}
