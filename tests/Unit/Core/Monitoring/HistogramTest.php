<?php

declare(strict_types=1);

use App\Modules\Core\Monitoring\Metrics\Histogram;
use App\Modules\Core\Monitoring\Metrics\Percentiles;

/*
 * El histograma de nueve tramos que comparten las tres métricas (trabajos, HTTP, consultas), y la
 * estimación de percentiles a partir de él. Puro: sin base de datos, sin red.
 */

it('cada duración cae en el tramo que le toca', function (): void {
    expect(Histogram::tramo(0))->toBe(0)
        ->and(Histogram::tramo(99))->toBe(0)
        ->and(Histogram::tramo(100))->toBe(1) // el límite pertenece al tramo de ARRIBA
        ->and(Histogram::tramo(299))->toBe(1)
        ->and(Histogram::tramo(300))->toBe(2)
        ->and(Histogram::tramo(999))->toBe(2)
        ->and(Histogram::tramo(1_000))->toBe(3)
        ->and(Histogram::tramo(179_999))->toBe(7)
        ->and(Histogram::tramo(180_000))->toBe(8)
        ->and(Histogram::tramo(999_999))->toBe(8); // el último tramo no tiene techo
});

it('columna() da el nombre de columna, no el índice', function (): void {
    expect(Histogram::columna(0))->toBe('h0')
        ->and(Histogram::columna(500))->toBe('h2')
        ->and(Histogram::columna(999_999))->toBe('h8');
});

it('sin ninguna observación, no hay percentil que estimar', function (): void {
    expect(Percentiles::estimar([0, 0, 0, 0, 0, 0, 0, 0, 0], 0.95))->toBeNull();
});

it('todas las observaciones en un solo tramo: el percentil interpola dentro de ÉL', function (): void {
    // Cien observaciones, todas en h0 (<100ms): el P50 tiene que caer DENTRO de [0, 100).
    $tramos = [100, 0, 0, 0, 0, 0, 0, 0, 0];

    $p50 = Percentiles::estimar($tramos, 0.50);

    expect($p50)->not->toBeNull()->and($p50)->toBeGreaterThanOrEqual(0.0)->and($p50)->toBeLessThan(100.0);
});

it('el percentil del último tramo (sin techo) es conservador: el suelo, no un número inventado', function (): void {
    $tramos = [0, 0, 0, 0, 0, 0, 0, 0, 10]; // diez observaciones, todas ≥180s

    expect(Percentiles::estimar($tramos, 0.95))->toBe(180_000.0);
});

it('P50 y P95 se separan cuando hay una cola de observaciones lentas', function (): void {
    // 90 rápidas (h0) y 10 lentas (h4, 3-10s): con 100 en total, el percentil 95 —el dato #95— cae
    // DENTRO del grupo lento (los rápidos son del #1 al #90); el 50 sigue en el rápido.
    $tramos = [90, 0, 0, 0, 10, 0, 0, 0, 0];

    $p50 = Percentiles::estimar($tramos, 0.50);
    $p95 = Percentiles::estimar($tramos, 0.95);

    expect($p50)->toBeLessThan(100.0)
        ->and($p95)->toBeGreaterThanOrEqual(3_000.0)
        ->and($p95)->toBeLessThan(10_000.0);
});

it('interpola en línea recta dentro del tramo, no siempre el mismo punto', function (): void {
    // Diez observaciones en h1 (100-300ms): el percentil pedido en el borde debe acercarse al
    // extremo correspondiente, no devolver siempre el centro del tramo.
    $tramos = [0, 10, 0, 0, 0, 0, 0, 0, 0];

    $cercaDelInicio = Percentiles::estimar($tramos, 0.05);
    $cercaDelFinal = Percentiles::estimar($tramos, 0.95);

    expect($cercaDelInicio)->toBeLessThan($cercaDelFinal);
});
