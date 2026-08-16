<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Cash\Models\CashRegister;
use App\Modules\Cash\Services\CashService;
use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\Delivery\Enums\DeliveryStatus;
use App\Modules\Delivery\Models\Delivery;
use App\Modules\Delivery\Services\DeliveryService;
use App\Modules\Finance\Models\Account;
use App\Modules\HR\Models\Employee;
use App\Modules\Inventory\Models\Product;
use App\Modules\Sales\Enums\OrderType;
use App\Modules\Sales\Enums\PaymentMethod;
use App\Modules\Sales\Models\Sale;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * Del pedido a la puerta.
 *
 * Antes el punto de venta no sabía si lo que cobraba era para comer allí o para llevar a una casa: se
 * cobraba igual y alguien creaba la entrega a mano copiando la dirección de un papel.
 *
 * Lo que se fija aquí es sobre todo EL DINERO, que es donde equivocarse cuesta de verdad:
 *
 *   · Pagado en el mostrador → se anota al cobrar, como siempre.
 *   · Pagado en la puerta    → no se anota nada al vender; se anota cuando el motorista liquida.
 *
 * Una sola frase: el dinero se anota cuando llega.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(CurrentCompany::class)->forget();
    $this->company = app(CompanyService::class)->create(new CreateCompanyData(name: 'Batidera'));
    app(CurrentCompany::class)->set($this->company->id);
    $this->company->update(['modules' => ['pos', 'quick_pos', 'inventory', 'sales', 'delivery', 'hr', 'finance']]);

    $this->cajero = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Cajera',
        'email' => 'cajera@batidera.test', 'password' => 'secret-password',
    ]), 'owner');

    $this->batida = Product::create([
        'name' => 'Batida de guineo', 'sku' => 'BAT-1', 'price' => '150', 'track_stock' => false,
    ]);

    // Sin caja abierta no se cobra nada, así que se abre una.
    $this->actingAs($this->cajero);
    app(CashService::class)->open(CashRegister::create(['name' => 'Caja 1', 'is_active' => true]), '0');
});

/** El cuerpo del cobro tal como lo manda el terminal. */
function cobroDeMostrador(array $extra = []): array
{
    return array_merge([
        'cart' => json_encode([['id' => test()->batida->id, 'qty' => 2]]),
        'paid' => '300',
        'payment_method' => 'cash',
    ], $extra);
}

function saldoDeCajaGeneral(): string
{
    return (string) Account::query()->where('is_default', true)->firstOrFail()->balance;
}

// -------------------------------------------------------------------- Solo el envío crea entrega

it('un pedido para comer aquí no crea ninguna entrega', function (): void {
    $this->actingAs($this->cajero)
        ->postJson(route('panel.pos.checkout'), cobroDeMostrador(['order_type' => OrderType::DineIn->value]))
        ->assertOk();

    expect(Delivery::count())->toBe(0)
        ->and(Sale::latest('id')->first()->order_type)->toBe(OrderType::DineIn);
});

it('para llevar tampoco: se anota y ya', function (): void {
    // «Para llevar» existe para poder responder después qué se vende de cada forma, no para repartir.
    $this->actingAs($this->cajero)
        ->postJson(route('panel.pos.checkout'), cobroDeMostrador(['order_type' => OrderType::Takeaway->value]))
        ->assertOk();

    expect(Delivery::count())->toBe(0)
        ->and(Sale::latest('id')->first()->order_type)->toBe(OrderType::Takeaway);
});

it('un envío crea la entrega con su dirección y su venta', function (): void {
    $this->actingAs($this->cajero)
        ->postJson(route('panel.pos.checkout'), cobroDeMostrador([
            'order_type' => OrderType::Delivery->value,
            'delivery_address' => 'Calle Duarte 45, casa amarilla',
            'delivery_phone' => '8095551234',
            'customer_name' => 'Doña Ana',
        ]))
        ->assertOk();

    $entrega = Delivery::firstOrFail();
    $venta = Sale::latest('id')->firstOrFail();

    expect($entrega->address)->toBe('Calle Duarte 45, casa amarilla')
        ->and($entrega->phone)->toBe('8095551234')
        ->and($entrega->customer_name)->toBe('Doña Ana')
        // El enlace con la venta es lo que permite reimprimir el ticket desde la entrega.
        ->and($entrega->sale_id)->toBe($venta->id);
});

it('un envío sin dirección no se cobra', function (): void {
    // Una entrega sin destino es un pedido perdido: el cliente pagó y nadie sabe a dónde llevarlo.
    $this->actingAs($this->cajero)
        ->postJson(route('panel.pos.checkout'), cobroDeMostrador(['order_type' => OrderType::Delivery->value]))
        ->assertStatus(422);

    expect(Sale::count())->toBe(0)
        ->and(Delivery::count())->toBe(0);
});

it('sin el módulo de entregas contratado no se puede pedir envío', function (): void {
    // Ocultar el botón no impide que alguien mande la petición a mano.
    $this->company->update(['modules' => ['pos', 'quick_pos', 'inventory', 'sales']]);

    $this->actingAs($this->cajero)
        ->postJson(route('panel.pos.checkout'), cobroDeMostrador([
            'order_type' => OrderType::Delivery->value,
            'delivery_address' => 'Calle Duarte 45',
        ]))
        ->assertStatus(422);

    expect(Sale::count())->toBe(0);
});

// -------------------------------------------------------------------- El dinero

it('pagado en el mostrador: el dinero entra al cobrar', function (): void {
    $saldoAntes = saldoDeCajaGeneral();

    $this->actingAs($this->cajero)
        ->postJson(route('panel.pos.checkout'), cobroDeMostrador([
            'order_type' => OrderType::Delivery->value,
            'delivery_address' => 'Calle Duarte 45',
        ]))
        ->assertOk();

    expect(saldoDeCajaGeneral())->toBe(bcadd($saldoAntes, '300', 2))
        // Y el motorista sale sin dinero: no hay nada que cobrar en la puerta.
        ->and((float) Delivery::firstOrFail()->amount_to_collect)->toBe(0.0);
});

it('pagado en la puerta: al vender NO se anota nada', function (): void {
    // Es el fallo que se viene a evitar. Si se anotara aquí, el dueño vería en su cuenta unos pesos
    // que todavía están en el bolsillo del motorista, y contaría con dinero que no puede tocar.
    $saldoAntes = saldoDeCajaGeneral();

    $this->actingAs($this->cajero)
        ->postJson(route('panel.pos.checkout'), cobroDeMostrador([
            'order_type' => OrderType::Delivery->value,
            'delivery_address' => 'Calle Duarte 45',
            'delivery_pay_on_arrival' => true,
        ]))
        ->assertOk();

    expect(saldoDeCajaGeneral())->toBe($saldoAntes);
});

it('pagado en la puerta: la venta va a crédito y sin pago', function (): void {
    // Crédito es literalmente «se cobra después». Y `entersCashDrawer()` es false para él, así que el
    // arqueo del turno cuadra sin que nadie tenga que acordarse de nada.
    $this->actingAs($this->cajero)
        ->postJson(route('panel.pos.checkout'), cobroDeMostrador([
            'order_type' => OrderType::Delivery->value,
            'delivery_address' => 'Calle Duarte 45',
            'delivery_pay_on_arrival' => true,
            // Aunque el terminal mande efectivo, manda el envío: la forma de pago la decide el
            // servidor, no el navegador.
            'payment_method' => 'cash',
        ]))
        ->assertOk();

    $venta = Sale::latest('id')->firstOrFail();

    expect($venta->payment_method)->toBe(PaymentMethod::Credit->value)
        ->and((float) $venta->paid)->toBe(0.0)
        ->and((float) $venta->change)->toBe(0.0)
        ->and($venta->estaCobrada())->toBeFalse();
});

it('pagado en la puerta: el motorista sale con el total exacto a cobrar', function (): void {
    // El monto sale del TOTAL DE LA VENTA y no de nada que llegue del navegador: es dinero que alguien
    // va a reclamar en una puerta.
    $this->actingAs($this->cajero)
        ->postJson(route('panel.pos.checkout'), cobroDeMostrador([
            'order_type' => OrderType::Delivery->value,
            'delivery_address' => 'Calle Duarte 45',
            'delivery_pay_on_arrival' => true,
        ]))
        ->assertOk();

    $venta = Sale::latest('id')->firstOrFail();

    expect((string) Delivery::firstOrFail()->amount_to_collect)->toBe((string) $venta->total);
});

// -------------------------------------------------------------------- El repartidor

it('le busca repartidor sola: al que menos lleva encima', function (): void {
    // Con «el primero de la lista», uno saldría con seis pedidos mientras otro espera.
    $cargado = Employee::create(['name' => 'Cargado', 'is_active' => true]);
    $libre = Employee::create(['name' => 'Libre', 'is_active' => true]);

    Delivery::create([
        'company_id' => $this->company->id, 'code' => 'ENV-999', 'address' => 'X',
        'status' => DeliveryStatus::Assigned, 'employee_id' => $cargado->id, 'amount_to_collect' => 0,
    ]);

    $this->actingAs($this->cajero)
        ->postJson(route('panel.pos.checkout'), cobroDeMostrador([
            'order_type' => OrderType::Delivery->value,
            'delivery_address' => 'Calle Duarte 45',
        ]))
        ->assertOk();

    expect(Delivery::where('code', '!=', 'ENV-999')->firstOrFail()->employee_id)->toBe($libre->id);
});

it('el repartidor elegido a mano manda sobre el automático', function (): void {
    Employee::create(['name' => 'Libre', 'is_active' => true]);
    $elegido = Employee::create(['name' => 'Elegido', 'is_active' => true]);
    Delivery::create([
        'company_id' => $this->company->id, 'code' => 'ENV-999', 'address' => 'X',
        'status' => DeliveryStatus::Assigned, 'employee_id' => $elegido->id, 'amount_to_collect' => 0,
    ]);

    $this->actingAs($this->cajero)
        ->postJson(route('panel.pos.checkout'), cobroDeMostrador([
            'order_type' => OrderType::Delivery->value,
            'delivery_address' => 'Calle Duarte 45',
            'delivery_employee_id' => $elegido->id,
        ]))
        ->assertOk();

    // Se le asigna al elegido aunque sea el que más carga tiene.
    expect(Delivery::where('code', '!=', 'ENV-999')->firstOrFail()->employee_id)->toBe($elegido->id);
});

it('sin nadie activo, la entrega queda sin asignar en vez de inventarse un repartidor', function (): void {
    Employee::create(['name' => 'De baja', 'is_active' => false]);

    $this->actingAs($this->cajero)
        ->postJson(route('panel.pos.checkout'), cobroDeMostrador([
            'order_type' => OrderType::Delivery->value,
            'delivery_address' => 'Calle Duarte 45',
        ]))
        ->assertOk();

    $entrega = Delivery::firstOrFail();

    expect($entrega->employee_id)->toBeNull()
        // Y se queda pendiente, que es como aparece en la lista para asignarla a mano.
        ->and($entrega->status)->toBe(DeliveryStatus::Pending);
});

// -------------------------------------------------------------------- El ciclo completo del dinero

it('el ciclo entero: se vende a crédito, el motorista cobra y el dinero acaba en la cuenta', function (): void {
    $kelvin = Employee::create(['name' => 'Kelvin', 'is_active' => true]);
    $saldoAntes = saldoDeCajaGeneral();

    $this->actingAs($this->cajero)
        ->postJson(route('panel.pos.checkout'), cobroDeMostrador([
            'order_type' => OrderType::Delivery->value,
            'delivery_address' => 'Calle Duarte 45',
            'delivery_pay_on_arrival' => true,
            'delivery_employee_id' => $kelvin->id,
        ]))
        ->assertOk();

    // Al vender no ha entrado nada.
    expect(saldoDeCajaGeneral())->toBe($saldoAntes);

    $entrega = Delivery::firstOrFail();
    app(DeliveryService::class)->markCollected($entrega);

    // Cobrado por el motorista, sigue sin entrar: está en su bolsillo.
    expect(saldoDeCajaGeneral())->toBe($saldoAntes);

    app(DeliveryService::class)->settle($kelvin);

    // Y ahora sí, exactamente el total de la venta.
    expect(saldoDeCajaGeneral())->toBe(bcadd($saldoAntes, '300', 2));
});

// -------------------------------------------------------------------- Lo que se acabó no se vende

it('un producto marcado como agotado no se puede cobrar', function (): void {
    // Sigue viéndose en la rejilla —para poder reactivarlo— así que se puede tocar por descuido.
    // Ocultarlo nunca ha sido protegerlo.
    $this->batida->update(['is_available' => false]);

    $this->actingAs($this->cajero)
        ->postJson(route('panel.pos.checkout'), cobroDeMostrador())
        ->assertStatus(422);

    expect(Sale::count())->toBe(0);
});

it('y no se descarta en silencio: se dice cuál es', function (): void {
    // Saltarse la línea cobraría menos de lo que el cliente tiene delante, y el cajero no lo vería
    // hasta contar la caja.
    $this->batida->update(['is_available' => false]);

    $respuesta = $this->actingAs($this->cajero)
        ->postJson(route('panel.pos.checkout'), cobroDeMostrador())
        ->assertStatus(422);

    expect($respuesta->json('message'))->toContain('Batida de guineo');
});

it('en un pedido que paga el cliente en la puerta no hace falta indicar importe', function (): void {
    // El cajero no recibe nada, así que exigirle un importe le obligaría a escribir un cero que no
    // significa nada —y el terminal se quedaba sin poder enviar el pedido—.
    $cuerpo = cobroDeMostrador([
        'order_type' => OrderType::Delivery->value,
        'delivery_address' => 'Calle Duarte 45',
        'delivery_pay_on_arrival' => true,
    ]);
    unset($cuerpo['paid']);

    $this->actingAs($this->cajero)
        ->postJson(route('panel.pos.checkout'), $cuerpo)
        ->assertOk();

    expect((float) Sale::latest('id')->firstOrFail()->paid)->toBe(0.0);
});

it('pero en cualquier otro pedido sigue haciendo falta', function (): void {
    // Sin importe no hay forma de saber cuánto cambio devolver ni cuánto entró al cajón.
    $cuerpo = cobroDeMostrador(['order_type' => OrderType::DineIn->value]);
    unset($cuerpo['paid']);

    $this->actingAs($this->cajero)
        ->postJson(route('panel.pos.checkout'), $cuerpo)
        ->assertStatus(422);

    expect(Sale::count())->toBe(0);
});
