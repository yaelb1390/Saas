<?php

declare(strict_types=1);

use App\Modules\Core\Support\TaxCalculator;

it('extrae el ITBIS de un precio que ya lo incluye', function (): void {
    $tax = new TaxCalculator(rate: '18', pricesIncludeTax: true);

    expect($tax->breakdown('118.00'))->toBe([
        'subtotal' => '100.00',
        'tax' => '18.00',
        'total' => '118.00',
    ]);
});

it('base + ITBIS siempre cuadra con el total, sin descuadres de centavos', function (): void {
    $tax = new TaxCalculator(rate: '18', pricesIncludeTax: true);

    // Importes que no dividen exacto entre 1.18: es donde aparecen los centavos fantasma.
    foreach (['300.00', '99.99', '0.01', '1234.57', '7.35'] as $amount) {
        $r = $tax->breakdown($amount);

        expect(bcadd($r['subtotal'], $r['tax'], 2))->toBe($r['total'])
            ->and($r['total'])->toBe($amount);
    }
});

it('redondea a la mitad hacia arriba en vez de truncar', function (): void {
    $tax = new TaxCalculator(rate: '18', pricesIncludeTax: true);

    // 300 / 1.18 = 254.2372... → 254.24 (truncar daría 254.23 y el ITBIS saldría un centavo alto).
    expect($tax->breakdown('300.00'))->toBe([
        'subtotal' => '254.24',
        'tax' => '45.76',
        'total' => '300.00',
    ]);
});

it('suma el ITBIS encima cuando los precios no lo incluyen', function (): void {
    $tax = new TaxCalculator(rate: '18', pricesIncludeTax: false);

    expect($tax->breakdown('100.00'))->toBe([
        'subtotal' => '100.00',
        'tax' => '18.00',
        'total' => '118.00',
    ]);
});

it('con tasa 0 no calcula impuesto', function (): void {
    $tax = new TaxCalculator(rate: '0');

    expect($tax->breakdown('100.00'))->toBe([
        'subtotal' => '100.00',
        'tax' => '0.00',
        'total' => '100.00',
    ]);
});
