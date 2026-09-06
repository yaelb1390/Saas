<?php

declare(strict_types=1);

use App\Modules\Sales\DTOs\CreateSaleData;
use App\Modules\Sales\DTOs\PaymentData;
use App\Modules\Sales\DTOs\SaleLineData;
use App\Modules\Sales\Enums\OrderType;
use App\Modules\Sales\Enums\PaymentMethod;

/*
 * QUE `withCashSession()` NO PIERDA NINGÚN CAMPO POR EL CAMINO.
 *
 * El método copia el DTO entero a mano, campo a campo. Es la clase de código que se rompe sola: se
 * añade una propiedad al DTO, nadie se acuerda de añadirla también a la copia, y el compilador no
 * puede avisar de un argumento que simplemente NO SE PASA —el valor por omisión tapa el hueco—.
 *
 * Ya pasó una vez con `customerId`: el POS enviaba el cliente y la venta se guardaba sin él.
 * Volvería a pasar, y hoy sería más caro: perder `payments` haría que el servicio no viera el
 * reparto, cayera en el camino de una sola vía con efectivo por omisión, y metiera el TOTAL ENTERO
 * al cajón de una venta cobrada mitad con tarjeta.
 *
 * Por eso el test no enumera los campos: los DESCUBRE por reflexión. Un campo nuevo que se olvide en
 * la copia pone esto en rojo sin que nadie tenga que acordarse de tocar el test.
 */

it('withCashSession conserva todos los campos del DTO', function (): void {
    // Cada campo con un valor DISTINTO del que trae por omisión: si la copia se olvida de uno, el
    // valor cae al de omisión y la comparación lo caza. Con el valor por defecto no se notaría nada.
    $original = new CreateSaleData(
        warehouseId: 7,
        lines: [new SaleLineData(productId: 3, quantity: '2.500', unitPrice: '199.99', discount: '10')],
        paymentMethod: PaymentMethod::Card,
        paid: '450.00',
        customerName: 'Ferretería del Este',
        branchId: 4,
        cashSessionId: null,
        customerId: 12,
        tip: '25.00',
        discountTotal: '30.00',
        employeeId: 9,
        orderType: OrderType::Delivery,
        clientUuid: '7f1c2a3e-0000-4000-8000-abcdef123456',
        payments: [
            new PaymentData(PaymentMethod::Card, '200.00', 'AUTH-9911'),
            new PaymentData(PaymentMethod::Cash, '250.00'),
        ],
    );

    $copia = $original->withCashSession(42);

    expect($copia->cashSessionId)->toBe(42);

    $parametros = (new ReflectionClass(CreateSaleData::class))->getConstructor()->getParameters();

    // Que la reflexión encuentre algo: si el DTO dejara de ser un constructor promovido, este test
    // pasaría en vacío sin comprobar ni un campo, y esa es la peor manera de fallar.
    expect($parametros)->not->toBeEmpty();

    foreach ($parametros as $parametro) {
        $campo = $parametro->getName();

        if ($campo === 'cashSessionId') {
            continue;   // es justo el que el método cambia
        }

        expect($copia->{$campo})
            ->toEqual($original->{$campo}, "withCashSession() perdió el campo «{$campo}» al copiar el DTO");
    }
});

/*
 * Y que la copia siga siendo una copia: el DTO es `readonly` y el original no debe moverse. Si
 * `withCashSession()` mutara en vez de clonar, el mismo DTO reutilizado en dos cobros arrastraría la
 * sesión de caja del primero.
 */
it('withCashSession no toca el DTO original', function (): void {
    $original = new CreateSaleData(warehouseId: 1, lines: []);

    $original->withCashSession(42);

    expect($original->cashSessionId)->toBeNull();
});
