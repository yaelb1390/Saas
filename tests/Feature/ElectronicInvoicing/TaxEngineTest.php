<?php

declare(strict_types=1);

use App\Modules\Core\Support\TaxCalculator;
use App\Modules\ElectronicInvoicing\Tax\BillingIndicator;
use App\Modules\ElectronicInvoicing\Tax\TaxEngine;
use App\Modules\ElectronicInvoicing\Tax\TaxLine;

/*
 * El ITBIS del e-CF por línea, como lo define el Formato e-CF.
 *
 * El caso más importante es el de la diferencia de un céntimo: con precios que incluyen ITBIS, la
 * fórmula oficial (gravado = cobrado ÷ 1,18; ITBIS = gravado × 18 %) no siempre vuelve a sumar lo
 * cobrado, mientras que la serie B calcula ITBIS = cobrado − base. El motor no lo esconde: lo deja
 * a la vista en `difference`, porque la DGII no publica una tolerancia.
 */

it('un ítem gravado al 18 % con precio que incluye ITBIS', function (): void {
    $r = (new TaxEngine)->calculate([new TaxLine('1', '118.00', BillingIndicator::Itbis1)], pricesIncludeTax: true);

    expect($r->taxedBase[1])->toBe('100.00')
        ->and($r->itbis[1])->toBe('18.00')
        ->and($r->total)->toBe('118.00')
        ->and($r->chargedTotal)->toBe('118.00')
        ->and($r->difference)->toBe('0.00');
});

it('con la fórmula oficial, 100 cobrados pueden declarar 100.01: la diferencia queda a la vista', function (): void {
    $r = (new TaxEngine)->calculate([new TaxLine('1', '100.00', BillingIndicator::Itbis1)], pricesIncludeTax: true);

    // 100 / 1.18 = 84.745… → 84.75; 84.75 × 0.18 = 15.255 → 15.26
    expect($r->taxedBase[1])->toBe('84.75')
        ->and($r->itbis[1])->toBe('15.26')
        ->and($r->total)->toBe('100.01')
        ->and($r->chargedTotal)->toBe('100.00')
        ->and($r->difference)->toBe('-0.01');
});

it('sin ITBIS incluido, el ITBIS se suma encima del precio', function (): void {
    $r = (new TaxEngine)->calculate([new TaxLine('2', '50.00', BillingIndicator::Itbis1)], pricesIncludeTax: false);

    expect($r->taxedBase[1])->toBe('100.00')
        ->and($r->itbis[1])->toBe('18.00')
        ->and($r->total)->toBe('118.00')
        ->and($r->chargedTotal)->toBe('118.00');
});

it('mezcla de tasas, exento y no facturable', function (): void {
    $r = (new TaxEngine)->calculate([
        new TaxLine('1', '118.00', BillingIndicator::Itbis1),
        new TaxLine('1', '116.00', BillingIndicator::Itbis2),
        new TaxLine('1', '40.00', BillingIndicator::Itbis3),
        new TaxLine('3', '25.00', BillingIndicator::Exento),
        new TaxLine('1', '10.00', BillingIndicator::NoFacturable),
    ], pricesIncludeTax: true);

    expect($r->taxedBase)->toBe([1 => '100.00', 2 => '100.00', 3 => '40.00'])
        ->and($r->itbis)->toBe([1 => '18.00', 2 => '16.00', 3 => '0.00'])
        ->and($r->taxedTotal)->toBe('240.00')
        ->and($r->exempt)->toBe('75.00')
        ->and($r->itbisTotal)->toBe('34.00')
        ->and($r->total)->toBe('349.00')
        // El no facturable no se cobra ni se declara.
        ->and($r->chargedTotal)->toBe('349.00')
        ->and($r->lineAmounts)->toBe(['118.00', '116.00', '40.00', '75.00', '10.00']);
});

it('el monto de cada línea es precio × cantidad − descuento + recargo', function (): void {
    $r = (new TaxEngine)->calculate([
        new TaxLine('2', '59.00', BillingIndicator::Itbis1, discount: '10.00', surcharge: '0'),
        new TaxLine('1', '30.00', BillingIndicator::Exento, discount: '0', surcharge: '5.00'),
    ], pricesIncludeTax: true);

    expect($r->lineAmounts)->toBe(['108.00', '35.00'])
        ->and($r->exempt)->toBe('35.00');
});

it('rechaza montos negativos y descuentos mayores que la línea', function (): void {
    $motor = new TaxEngine;

    expect(fn () => $motor->calculate([new TaxLine('-1', '10', BillingIndicator::Itbis1)], true))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => $motor->calculate([new TaxLine('1', '10', BillingIndicator::Itbis1, discount: '11')], true))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => $motor->calculate([new TaxLine('1', 'abc', BillingIndicator::Itbis1)], true))
        ->toThrow(InvalidArgumentException::class);
});

it('frente a la serie B, el total declarado nunca se separa más de un céntimo de lo cobrado', function (): void {
    $motor = new TaxEngine;
    $serieB = app(TaxCalculator::class);
    $peor = '0.00';

    // De RD$0.01 a RD$500.00, de céntimo en céntimo hasta 20 y luego de 0.37 en 0.37.
    $montos = array_merge(range(1, 2000), range(2001, 50000, 37));

    foreach ($montos as $centimos) {
        $bruto = number_format($centimos / 100, 2, '.', '');
        $ecf = $motor->calculate([new TaxLine('1', $bruto, BillingIndicator::Itbis1)], true);

        expect($serieB->breakdown($bruto)['total'])->toBe($ecf->chargedTotal);

        $diferencia = ltrim($ecf->difference, '-');
        expect(bccomp($diferencia, '0.01', 2))->toBeLessThanOrEqual(0, "Con {$bruto} la diferencia fue {$ecf->difference}");

        if (bccomp($diferencia, $peor, 2) > 0) {
            $peor = $diferencia;
        }
    }

    expect($peor)->toBe('0.01');
});
