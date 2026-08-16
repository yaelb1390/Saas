<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\Delivery\Enums\DeliveryStatus;
use App\Modules\Delivery\Events\DeliveryStatusChanged;
use App\Modules\Delivery\Exceptions\DeliveryException;
use App\Modules\Delivery\Models\Delivery;
use App\Modules\Delivery\Services\DeliveryService;
use App\Modules\Finance\Models\Account;
use App\Modules\HR\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

/*
 * Reparto.
 *
 * El módulo se vendía y no hacía nada: la única ruta era la pantalla de solo lectura, así que
 * `DeliveryService` tenía métodos que ningún camino del código podía alcanzar y la tabla se quedaba
 * vacía para siempre.
 *
 * Lo que se cubre aquí es lo que cuesta dinero de verdad cuando falla: que una entrega no retroceda
 * de estado, que un cobro no se cuente dos veces, y que el efectivo que lleva el motorista encima
 * aparezca en algún sitio hasta que lo traiga a caja.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(CurrentCompany::class)->forget();
    $this->company = app(CompanyService::class)->create(new CreateCompanyData(name: 'Pica Pollo'));
    app(CurrentCompany::class)->set($this->company->id);

    $this->owner = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Dueño',
        'email' => 'dueno@picapollo.test', 'password' => 'secret-password',
    ]), 'owner');
});

/** Un repartidor de la ficha de empleados. */
function repartidorLlamado(string $nombre = 'Kelvin'): Employee
{
    return Employee::create(['name' => $nombre, 'position' => 'Mensajero', 'is_active' => true]);
}

/** Una entrega recién creada, opcionalmente con dinero a cobrar en la puerta. */
function entregaHacia(string $direccion = 'Calle Duarte 45', string $aCobrar = '0'): Delivery
{
    return app(DeliveryService::class)->create($direccion, amountToCollect: $aCobrar);
}

it('registra una entrega a mano desde la pantalla', function (): void {
    // Antes solo se podía crear desde el seeder. Sin esta ruta el módulo era una tabla vacía.
    $this->actingAs($this->owner)
        ->post(route('panel.deliveries.store'), [
            'address' => 'Calle Duarte 45, casa amarilla',
            'customer_name' => 'Doña Ana',
            'phone' => '8095551234',
            'amount_to_collect' => '450',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $entrega = Delivery::firstWhere('customer_name', 'Doña Ana');

    expect($entrega)->not->toBeNull()
        ->and($entrega->code)->toBe('ENV-000001')
        ->and($entrega->status)->toBe(DeliveryStatus::Pending)
        ->and((float) $entrega->amount_to_collect)->toBe(450.0);
});

it('exige la dirección: sin ella no hay reparto', function (): void {
    $this->actingAs($this->owner)
        ->post(route('panel.deliveries.store'), ['customer_name' => 'Doña Ana'])
        ->assertSessionHasErrors('address');

    expect(Delivery::count())->toBe(0);
});

it('al asignar guarda el nombre del repartidor, no solo su ficha', function (): void {
    // Si mañana se borra el empleado, la entrega tiene que seguir diciendo quién la llevó.
    $entrega = entregaHacia();
    $kelvin = repartidorLlamado();

    $this->actingAs($this->owner)
        ->post(route('panel.deliveries.assign', $entrega), ['employee_id' => $kelvin->id])
        ->assertSessionHasNoErrors();

    $entrega->refresh();

    expect($entrega->employee_id)->toBe($kelvin->id)
        ->and($entrega->driver_name)->toBe('Kelvin')
        ->and($entrega->status)->toBe(DeliveryStatus::Assigned)
        ->and($entrega->assigned_at)->not->toBeNull();
});

it('no deja que una entrega cerrada vuelva atrás', function (): void {
    // Repetir una petición no puede resucitar un pedido ya entregado: el reparto del día dejaría de
    // cuadrar sin que nadie hubiera hecho nada raro.
    $entrega = entregaHacia();
    app(DeliveryService::class)->assign($entrega, repartidorLlamado());
    app(DeliveryService::class)->transition($entrega, DeliveryStatus::Delivered);

    $this->actingAs($this->owner)
        ->post(route('panel.deliveries.transition', $entrega), ['status' => DeliveryStatus::Pending->value])
        ->assertSessionHas('panel_error');

    expect($entrega->fresh()->status)->toBe(DeliveryStatus::Delivered);
});

it('cada cambio de estado despacha su evento', function (): void {
    // De este evento cuelgan las automatizaciones: avisar al cliente por WhatsApp cuando el pedido
    // sale, apuntar la incidencia cuando falla. Si deja de dispararse, no se rompe nada visible en
    // pantalla y el cliente simplemente deja de recibir avisos.
    $entrega = entregaHacia();
    app(DeliveryService::class)->assign($entrega, repartidorLlamado());

    Event::fake([DeliveryStatusChanged::class]);

    app(DeliveryService::class)->transition($entrega, DeliveryStatus::Delivered);

    expect($entrega->refresh()->delivered_at)->not->toBeNull();
    Event::assertDispatched(DeliveryStatusChanged::class);
});

it('permite marcar «no se pudo entregar» en cualquier punto del camino', function (): void {
    // La casa puede estar cerrada apenas salido del local o llegando a la puerta.
    $entrega = entregaHacia();

    $this->actingAs($this->owner)
        ->post(route('panel.deliveries.transition', $entrega), ['status' => DeliveryStatus::Failed->value])
        ->assertSessionHasNoErrors();

    expect($entrega->fresh()->status)->toBe(DeliveryStatus::Failed);
});

it('cobrar en la puerta deja la entrega entregada y el dinero en manos del motorista', function (): void {
    $entrega = entregaHacia(aCobrar: '450');
    $kelvin = repartidorLlamado();
    app(DeliveryService::class)->assign($entrega, $kelvin);

    $this->actingAs($this->owner)
        ->post(route('panel.deliveries.collect', $entrega))
        ->assertSessionHasNoErrors();

    $entrega->refresh();

    expect($entrega->status)->toBe(DeliveryStatus::Delivered)
        ->and($entrega->collected_at)->not->toBeNull()
        ->and($entrega->settled_at)->toBeNull();
});

it('no cobra dos veces la misma entrega', function (): void {
    // Repetir el cobro pediría en caja el doble de lo que el motorista lleva encima.
    $entrega = entregaHacia(aCobrar: '450');
    app(DeliveryService::class)->assign($entrega, repartidorLlamado());
    app(DeliveryService::class)->markCollected($entrega);

    $this->actingAs($this->owner)
        ->post(route('panel.deliveries.collect', $entrega))
        ->assertSessionHas('panel_error');

    expect(Delivery::whereNotNull('collected_at')->count())->toBe(1);
});

it('no deja cobrar una entrega que ya venía pagada', function (): void {
    $entrega = entregaHacia();

    $this->actingAs($this->owner)
        ->post(route('panel.deliveries.collect', $entrega))
        ->assertSessionHas('panel_error');

    expect($entrega->fresh()->collected_at)->toBeNull();
});

it('liquida de una vez todo lo que el repartidor trae', function (): void {
    $kelvin = repartidorLlamado();
    $servicio = app(DeliveryService::class);

    foreach (['450', '300.50'] as $monto) {
        $entrega = entregaHacia(aCobrar: $monto);
        $servicio->assign($entrega, $kelvin);
        $servicio->markCollected($entrega);
    }

    $this->actingAs($this->owner)
        ->post(route('panel.deliveries.settle', $kelvin))
        ->assertSessionHasNoErrors();

    expect(Delivery::whereNull('settled_at')->whereNotNull('collected_at')->count())->toBe(0)
        ->and(Delivery::whereNotNull('settled_at')->count())->toBe(2);
});

it('al liquidar, el dinero del motorista entra en las cuentas', function (): void {
    /*
     * ESTE TEST ANTES DECÍA LO CONTRARIO, y estaba mal.
     *
     * Afirmaba que liquidar no anota nada «porque la venta ya lo anotó al cobrarse». Eso solo es
     * cierto de una entrega de una venta ya pagada —que nace con «a cobrar 0» y ni siquiera llega a
     * liquidarse—. Una entrega CON dinero a cobrar nunca lo tuvo anotado: esos pesos entraban por la
     * puerta del local y desaparecían de los libros.
     *
     * La regla correcta es una sola frase: el dinero se anota cuando llega.
     */
    $kelvin = repartidorLlamado();
    $entrega = entregaHacia(aCobrar: '450');
    app(DeliveryService::class)->assign($entrega, $kelvin);
    app(DeliveryService::class)->markCollected($entrega);

    $cuenta = Account::query()->where('is_default', true)->firstOrFail();
    $saldoAntes = (string) $cuenta->balance;

    app(DeliveryService::class)->settle($kelvin);

    // Se comprueba el SALDO y no que «haya un movimiento más»: lo que importa es que el dueño pueda
    // contar ese dinero, no que quedara constancia de algo.
    expect((string) $cuenta->fresh()->balance)->toBe(bcadd($saldoAntes, '450', 2));
});

it('y no se cuenta dos veces cuando la venta ya lo había cobrado', function (): void {
    // Un pedido pagado en el mostrador nace con «a cobrar 0». Si aun así llegara a liquidarse, anotar
    // otra vez su importe haría que el negocio se creyera más rico de lo que es.
    $kelvin = repartidorLlamado();
    $entrega = entregaHacia(aCobrar: '450');
    app(DeliveryService::class)->assign($entrega, $kelvin);
    app(DeliveryService::class)->markCollected($entrega);
    app(DeliveryService::class)->settle($kelvin);

    $cuenta = Account::query()->where('is_default', true)->firstOrFail();
    $saldo = (string) $cuenta->fresh()->balance;

    // Liquidar otra vez no encuentra nada pendiente y no puede volver a sumar.
    expect(fn () => app(DeliveryService::class)->settle($kelvin))
        ->toThrow(DeliveryException::class);

    expect((string) $cuenta->fresh()->balance)->toBe($saldo);
});

it('no liquida a quien no tiene nada pendiente', function (): void {
    // Sin esto, «liquidar» de más marcaría un cuadre a cero que parecería una entrega de dinero real.
    $kelvin = repartidorLlamado();

    $this->actingAs($this->owner)
        ->post(route('panel.deliveries.settle', $kelvin))
        ->assertSessionHas('panel_error');
});

it('la pantalla canta cuánto dinero hay en la calle', function (): void {
    // Es la pregunta del cierre del día y hasta ahora no tenía respuesta en ninguna pantalla.
    $kelvin = repartidorLlamado();
    $entrega = entregaHacia(aCobrar: '450');
    app(DeliveryService::class)->assign($entrega, $kelvin);
    app(DeliveryService::class)->markCollected($entrega);

    $this->actingAs($this->owner)->get(route('panel.deliveries'))
        ->assertOk()
        ->assertSee('Dinero en la calle')
        ->assertSee('Kelvin')
        ->assertSee('450.00');
});

it('lo ya liquidado desaparece del dinero en la calle', function (): void {
    $kelvin = repartidorLlamado();
    $entrega = entregaHacia(aCobrar: '450');
    app(DeliveryService::class)->assign($entrega, $kelvin);
    app(DeliveryService::class)->markCollected($entrega);
    app(DeliveryService::class)->settle($kelvin);

    $this->actingAs($this->owner)->get(route('panel.deliveries'))
        ->assertOk()
        ->assertDontSee('Dinero en la calle');
});

it('un cajero no puede tocar el reparto', function (): void {
    $cajero = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Cajero',
        'email' => 'cajero@picapollo.test', 'password' => 'secret-password',
    ]), 'staff');

    $this->actingAs($cajero)
        ->post(route('panel.deliveries.store'), ['address' => 'Calle Duarte 45'])
        ->assertForbidden();

    expect(Delivery::count())->toBe(0);
});

it('no deja tocar una entrega de otra empresa', function (): void {
    $otra = app(CompanyService::class)->create(new CreateCompanyData(name: 'Otra'));
    app(CurrentCompany::class)->set($otra->id);
    $ajena = entregaHacia('Dirección ajena');
    app(CurrentCompany::class)->set($this->company->id);

    // Ni por consulta directa —el scope de empresa la esconde— ni por la ruta.
    expect(Delivery::count())->toBe(0);

    $this->actingAs($this->owner)
        ->post(route('panel.deliveries.transition', $ajena), ['status' => DeliveryStatus::Failed->value])
        ->assertNotFound();
});

it('no reutiliza el código de una entrega archivada', function (): void {
    // Contar solo las vivas haría chocar la siguiente contra el índice único de (company_id, code) y
    // el reparto se quedaría sin poder registrar nada.
    entregaHacia()->delete();

    expect(entregaHacia()->code)->toBe('ENV-000002');
});
