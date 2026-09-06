<?php

declare(strict_types=1);

use App\Modules\Sales\DTOs\PaymentData;
use App\Modules\Sales\Enums\PaymentMethod;
use App\Modules\Sales\Exceptions\InsufficientPaymentException;
use App\Modules\Sales\Exceptions\PaymentSplitException;
use App\Modules\Sales\Support\RepartoDePagos;

/*
 * LA ARITMÉTICA DEL REPARTO, SIN BASE DE DATOS.
 *
 * Está aquí y no en un test de integración a propósito: estas reglas no dependen de nada del sistema,
 * y probarlas sueltas permite recorrer los bordes —el céntimo perdido, el vuelto, el cobro de más—
 * en milésimas y sin montar una empresa entera.
 *
 * La invariante es una sola: LO IMPUTADO SUMA EXACTAMENTE EL TOTAL. Todo lo demás son las maneras
 * concretas en que eso puede romperse.
 */

/** @var RepartoDePagos $reparto */
$reparto = new RepartoDePagos;

// ------------------------------------------------------------------ Una sola vía (lo de siempre)

it('con una sola via imputa el total y guarda lo entregado', function () use ($reparto): void {
    $desglose = $reparto->deUnaSolaVia(PaymentMethod::Cash, '1500', '1000');

    expect($desglose->total())->toBe('1000.00')      // se imputa la venta, no lo que trajo el cliente
        ->and($desglose->entregado())->toBe('1500.00')
        ->and($desglose->cambio())->toBe('500.00')
        ->and($desglose->esMixto())->toBeFalse();
});

it('con una sola via y sin importe se asume que pago justo', function () use ($reparto): void {
    $desglose = $reparto->deUnaSolaVia(PaymentMethod::Card, null, '1000');

    expect($desglose->total())->toBe('1000.00')
        ->and($desglose->cambio())->toBe('0.00');
});

it('con una sola via rechaza lo que no cubre el total', function () use ($reparto): void {
    expect(fn () => $reparto->deUnaSolaVia(PaymentMethod::Cash, '900', '1000'))
        ->toThrow(InsufficientPaymentException::class);
});

/*
 * El crédito es la excepción de siempre: se imputa la venta entera aunque no entre un peso. Ese
 * dinero lo anota después quien lo cobra —el motorista al liquidar—, no este reparto.
 */
it('el credito imputa el total sin recibir nada', function () use ($reparto): void {
    $desglose = $reparto->deUnaSolaVia(PaymentMethod::Credit, null, '1000');

    expect($desglose->total())->toBe('1000.00')
        ->and($desglose->entregado())->toBe('0.00')
        ->and($desglose->cobradoAhora())->toBe('0.00')   // no hay nada que apuntar en la cuenta
        ->and($desglose->efectivo())->toBe('0.00');      // ni nada que meter al cajón
});

// ------------------------------------------------------------------ El reparto entre varias

it('reparte entre varias vias hasta cubrir el total', function () use ($reparto): void {
    $desglose = $reparto->repartir([
        new PaymentData(PaymentMethod::Card, '400'),
        new PaymentData(PaymentMethod::Transfer, '350'),
        new PaymentData(PaymentMethod::Cash, '250'),
    ], '1000');

    expect($desglose->total())->toBe('1000.00')
        ->and($desglose->esMixto())->toBeTrue()
        ->and($desglose->efectivo())->toBe('250.00')
        ->and($desglose->cambio())->toBe('0.00');
});

/*
 * NI UN CÉNTIMO SE PIERDE.
 *
 * Es el caso que rompe cualquier reparto escrito con `float`: 33.34 + 33.34 + 33.33 en coma flotante
 * no da 100.01. Todo el cálculo va con bcmath por esto.
 */
it('no pierde un centimo al repartir un total impar', function () use ($reparto): void {
    $desglose = $reparto->repartir([
        new PaymentData(PaymentMethod::Card, '33.34'),
        new PaymentData(PaymentMethod::Transfer, '33.34'),
        new PaymentData(PaymentMethod::Cash, '33.33'),
    ], '100.01');

    expect($desglose->total())->toBe('100.01');
});

it('el efectivo absorbe el vuelto y solo imputa lo que faltaba', function () use ($reparto): void {
    $desglose = $reparto->repartir([
        new PaymentData(PaymentMethod::Card, '400'),
        new PaymentData(PaymentMethod::Cash, '1000'),   // entrega un billete de mil para cubrir 600
    ], '1000');

    expect($desglose->total())->toBe('1000.00')
        ->and($desglose->efectivo())->toBe('600.00')     // al cajón entran 600
        ->and($desglose->entregado())->toBe('1400.00')
        ->and($desglose->cambio())->toBe('400.00');
});

it('rechaza un reparto que no llega al total', function () use ($reparto): void {
    expect(fn () => $reparto->repartir([
        new PaymentData(PaymentMethod::Card, '400'),
        new PaymentData(PaymentMethod::Transfer, '300'),
    ], '1000'))->toThrow(PaymentSplitException::class);
});

it('rechaza que una tarjeta cobre de mas', function () use ($reparto): void {
    expect(fn () => $reparto->repartir([new PaymentData(PaymentMethod::Card, '1200')], '1000'))
        ->toThrow(PaymentSplitException::class);
});

/*
 * El orden importa, y esto lo fija: una vez cubierto el total, no queda pendiente que imputar, así
 * que una segunda tarjeta detrás no tiene sitio. La pantalla ordena el efectivo AL FINAL justamente
 * para que sea él quien absorba el resto.
 */
it('rechaza una tarjeta cuando ya no queda pendiente', function () use ($reparto): void {
    expect(fn () => $reparto->repartir([
        new PaymentData(PaymentMethod::Cash, '1000'),
        new PaymentData(PaymentMethod::Card, '100'),
    ], '1000'))->toThrow(PaymentSplitException::class);
});

it('rechaza una forma de pago sin importe', function () use ($reparto): void {
    expect(fn () => $reparto->repartir([
        new PaymentData(PaymentMethod::Card, '0'),
        new PaymentData(PaymentMethod::Cash, '1000'),
    ], '1000'))->toThrow(PaymentSplitException::class);

    expect(fn () => $reparto->repartir([
        new PaymentData(PaymentMethod::Card, '-50'),
        new PaymentData(PaymentMethod::Cash, '1050'),
    ], '1000'))->toThrow(PaymentSplitException::class);
});

/*
 * El tope no es cosmético: cada fila entra en una transacción que mantiene bloqueada la fila del
 * stock, y una petición a mano con mil formas de pago la alargaría a placer.
 */
it('rechaza mas formas de pago que el tope', function () use ($reparto): void {
    $seis = array_fill(0, RepartoDePagos::TOPE + 1, new PaymentData(PaymentMethod::Cash, '100'));

    expect(fn () => $reparto->repartir($seis, '600'))->toThrow(PaymentSplitException::class);
});

// ------------------------------------------------------------------ Lo que se deriva del desglose

/*
 * `metodoDominante()` es lo que acaba en la columna `payment_method` de la venta. Tiene que ser
 * SIEMPRE un valor del enum: hay `match` por todo el sistema —el 607, los informes— con rama por
 * omisión, así que un valor inventado no fallaría, declararía mal en silencio.
 */
it('el metodo dominante es el de mayor importe', function () use ($reparto): void {
    $desglose = $reparto->repartir([
        new PaymentData(PaymentMethod::Cash, '300'),
        new PaymentData(PaymentMethod::Card, '700'),
    ], '1000');

    expect($desglose->metodoDominante())->toBe(PaymentMethod::Card);
});

it('con importes empatados el metodo dominante sigue siendo valido', function () use ($reparto): void {
    $desglose = $reparto->repartir([
        new PaymentData(PaymentMethod::Cash, '500'),
        new PaymentData(PaymentMethod::Card, '500'),
    ], '1000');

    expect(PaymentMethod::tryFrom($desglose->metodoDominante()->value))->not->toBeNull();
});

/*
 * `cobradoAhora()` es lo que se apunta en la cuenta del negocio. Se separa de `total()` por las
 * ventas cobradas a medias: apuntar el total entero enseñaría al dueño dinero que sigue en la calle.
 */
it('lo cobrado ahora deja fuera la parte a credito', function () use ($reparto): void {
    $desglose = $reparto->repartir([
        new PaymentData(PaymentMethod::Cash, '400'),
        new PaymentData(PaymentMethod::Credit, '600'),
    ], '1000');

    expect($desglose->total())->toBe('1000.00')
        ->and($desglose->cobradoAhora())->toBe('400.00')
        ->and($desglose->efectivo())->toBe('400.00');
});
