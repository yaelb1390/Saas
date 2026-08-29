<?php

declare(strict_types=1);

use App\Modules\Core\Support\PlanDeCuotas;
use Illuminate\Support\Carbon;

/*
 * El reparto de un total en cuotas.
 *
 * ESTOS TESTS NACEN DE UN AGUJERO REAL. La regla de que la última cuota absorbe el redondeo llevaba
 * meses escrita dentro de `LoanService` y NO estaba probada: al mutarla —quitarle la absorción— los
 * 45 tests de Préstamos seguían en verde. Pasaba porque todos usan cifras que dividen exactas
 * (10.000 entre 4), y ahí no hay céntimo que repartir.
 *
 * Ahora que el dealer de vehículos financia con las mismas cuentas, un error aquí cobraría de menos
 * en dos módulos a la vez. Por eso los casos de abajo están elegidos para que SOBREN céntimos.
 */

/** Mensual, que es lo que usan tanto el préstamo como la venta a cuotas del dealer. */
function mensual(): Closure
{
    return fn (Carbon $desde): Carbon => $desde->copy()->addMonthNoOverflow();
}

it('las cuotas suman EXACTAMENTE el total aunque no divida', function (): void {
    /*
     * 100.000 entre 3 da 33.333,33, y tres de esas suman 99.999,99: falta un céntimo. Si no lo
     * absorbiera la última cuota, el crédito no llegaría nunca a saldo cero y quedaría abierto para
     * siempre por ese céntimo.
     */
    $plan = PlanDeCuotas::calcular('100000', '0', 3, Carbon::parse('2026-01-15'), mensual());

    $suma = array_reduce($plan, fn (string $t, array $c): string => bcadd($t, $c['amount'], 2), '0');

    expect($suma)->toBe('100000.00')
        ->and($plan[0]['amount'])->toBe('33333.33')
        ->and($plan[1]['amount'])->toBe('33333.33')
        // La última carga con lo que faltaba.
        ->and($plan[2]['amount'])->toBe('33333.34');
});

it('el capital y el interés también cuadran por separado', function (): void {
    // No basta con que sumen bien las cuotas: el reparto capital/interés alimenta la contabilidad,
    // y si el capital devuelto no suma lo prestado, el saldo del préstamo nunca cierra.
    $plan = PlanDeCuotas::calcular('10000', '1000', 3, Carbon::parse('2026-03-31'), mensual());

    $capital = array_reduce($plan, fn (string $t, array $c): string => bcadd($t, $c['principal_portion'], 2), '0');
    $interes = array_reduce($plan, fn (string $t, array $c): string => bcadd($t, $c['interest_portion'], 2), '0');

    expect($capital)->toBe('10000.00')
        ->and($interes)->toBe('1000.00');
});

it('el importe de cada cuota se redondea HACIA ABAJO', function (): void {
    // Hacia arriba se le cobrarían al cliente unos céntimos de más que nadie sabe justificar. Lo que
    // falta se lo lleva la última cuota, que es una diferencia que sí se puede explicar.
    expect(PlanDeCuotas::importeDeCuota('100', 3))->toBe('33.33');
});

it('las fechas se espacian con la función que se le pasa, no con una suya', function (): void {
    // El préstamo cobra hasta a diario y el dealer no. El calculador no tiene por qué saber de
    // frecuencias: recibe cómo avanzar y obedece.
    $plan = PlanDeCuotas::calcular('300', '0', 3, Carbon::parse('2026-01-10'), fn (Carbon $d): Carbon => $d->copy()->addWeek());

    expect($plan[0]['due_date']->toDateString())->toBe('2026-01-10')
        ->and($plan[1]['due_date']->toDateString())->toBe('2026-01-17')
        ->and($plan[2]['due_date']->toDateString())->toBe('2026-01-24');
});

it('empezar un día 31 no salta de mes', function (): void {
    // `addMonthNoOverflow` existe por esto: un crédito firmado el 31 de enero vencería el 3 de marzo
    // con una suma normal de meses, y el cliente reclamaría con razón.
    $plan = PlanDeCuotas::calcular('300', '0', 3, Carbon::parse('2026-01-31'), mensual());

    expect($plan[1]['due_date']->toDateString())->toBe('2026-02-28')
        ->and($plan[2]['due_date']->toDateString())->toBe('2026-03-28');
});

it('una sola cuota se lleva todo', function (): void {
    // El caso de contado a un pago. Sin el máximo de 1, un cero dividiría por cero.
    $plan = PlanDeCuotas::calcular('5000', '500', 0, Carbon::parse('2026-05-01'), mensual());

    expect($plan)->toHaveCount(1)
        ->and($plan[0]['amount'])->toBe('5500.00');
});
