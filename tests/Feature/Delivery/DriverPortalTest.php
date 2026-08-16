<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Services\CompanyUserService;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\Delivery\Enums\DeliveryOutcomeReason;
use App\Modules\Delivery\Enums\DeliveryStatus;
use App\Modules\Delivery\Models\Delivery;
use App\Modules\Delivery\Services\DeliveryService;
use App\Modules\HR\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

/*
 * El portal del repartidor.
 *
 * Antes el motorista no participaba: alguien en el local le preguntaba por teléfono cómo le había
 * ido y tecleaba el resultado. Ahora cierra él sus entregas desde el móvil.
 *
 * Lo que se fija aquí es lo que cuesta dinero o confianza cuando falla: que un repartidor no vea ni
 * toque las entregas de otro, que el motivo elegido y el estado guardado no puedan discrepar, y que
 * su sesión —la que anda por la calle en un teléfono— no llegue a ninguna otra pantalla.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(CurrentCompany::class)->forget();
    $this->company = app(CompanyService::class)->create(new CreateCompanyData(name: 'Reparto Co'));
    app(CurrentCompany::class)->set($this->company->id);
});

/** Un repartidor completo: ficha de empleado + cuenta con rol «driver», ya vinculadas. */
function motoristaLlamado(string $nombre): Employee
{
    $empleado = Employee::create(['name' => $nombre, 'position' => 'Mensajero', 'is_active' => true]);

    app(CompanyUserService::class)->create(
        (int) test()->company->id,
        [
            'name' => $nombre,
            'email' => str($nombre)->slug()->toString().'@reparto.test',
            'password' => 'secret-password',
            'employee_id' => $empleado->id,
        ],
        'driver',
    );

    return $empleado->refresh();
}

/** Una entrega ya asignada a ese motorista. */
function pedidoDe(Employee $motorista, string $aCobrar = '0', string $direccion = 'Calle Duarte 45'): Delivery
{
    $entrega = app(DeliveryService::class)->create($direccion, amountToCollect: $aCobrar);

    return app(DeliveryService::class)->assign($entrega, $motorista);
}

// -------------------------------------------------------------------- Solo lo suyo

it('el repartidor ve sus entregas y ni una de su compañero', function (): void {
    // La prueba que de verdad importa: lo que se cobra en la puerta es dinero, y el saldo de otro
    // no es asunto suyo.
    $kelvin = motoristaLlamado('Kelvin');
    $ramon = motoristaLlamado('Ramón');

    pedidoDe($kelvin, direccion: 'Calle de Kelvin 1');
    pedidoDe($ramon, direccion: 'Calle de Ramón 2');

    $this->actingAs($kelvin->user)->get(route('portal.deliveries'))
        ->assertOk()
        ->assertSee('Calle de Kelvin 1')
        ->assertDontSee('Calle de Ramón 2');
});

it('no puede cerrar una entrega que no lleva él, aunque teclee la URL', function (): void {
    // Ocultar nunca es proteger.
    $kelvin = motoristaLlamado('Kelvin');
    $ramon = motoristaLlamado('Ramón');
    $ajena = pedidoDe($ramon);

    $this->actingAs($kelvin->user)
        ->post(route('portal.deliveries.close', $ajena), ['reason' => DeliveryOutcomeReason::Delivered->value])
        ->assertSessionHas('panel_error');

    expect($ajena->fresh()->status)->toBe(DeliveryStatus::Assigned);
});

it('un usuario sin ficha de empleado no ve entregas, y se le dice por qué', function (): void {
    // Dar de alta un repartidor YA le crea la ficha, así que este estado solo se alcanza si alguien
    // la borra después. Se simula así en vez de crear la cuenta a medias: probar un caso que el
    // sistema ya no produce diría que el aviso sobra, y no sobra —el día que borren una ficha, el
    // repartidor vería una pantalla vacía y concluiría que no le han asignado nada—.
    $suelto = motoristaLlamado('Suelto');
    $usuario = $suelto->user;
    $suelto->delete();

    $this->actingAs($usuario)->get(route('portal.deliveries'))
        ->assertOk()
        ->assertSee('no está vinculado a tu ficha de empleado', false);
});

// -------------------------------------------------------------------- Los tres finales

it('cada motivo deja la entrega en el estado que le corresponde', function (): void {
    // Recorre el enum ENTERO: un motivo nuevo sin estado decidido pone este test en rojo.
    $kelvin = motoristaLlamado('Kelvin');

    foreach (DeliveryOutcomeReason::cases() as $motivo) {
        $entrega = pedidoDe($kelvin);

        $this->actingAs($kelvin->user)
            ->post(route('portal.deliveries.close', $entrega), ['reason' => $motivo->value])
            ->assertSessionHasNoErrors();

        expect($entrega->fresh()->status)->toBe($motivo->status())
            ->and($entrega->fresh()->outcome_reason)->toBe($motivo);
    }
});

it('«no entregada» y «cancelada» no son el mismo final', function (): void {
    // Contarlas juntas taparía la única pregunta que importa al cerrar el día: cuánto se dejó de
    // vender por culpa nuestra y cuánto porque el cliente cambió de idea.
    expect(DeliveryOutcomeReason::WrongAddress->status())->toBe(DeliveryStatus::Failed)
        ->and(DeliveryOutcomeReason::CustomerCancelled->status())->toBe(DeliveryStatus::Cancelled)
        ->and(DeliveryStatus::Cancelled->isFinal())->toBeTrue();
});

it('guarda la nota que escribe el repartidor sin machacar las señas de la casa', function (): void {
    // `notes` lleva la referencia para encontrar la casa; perderla dejaría al siguiente reparto a
    // ciegas.
    $kelvin = motoristaLlamado('Kelvin');
    $entrega = pedidoDe($kelvin);
    $entrega->update(['notes' => 'Portón azul, timbre de abajo']);

    $this->actingAs($kelvin->user)->post(route('portal.deliveries.close', $entrega), [
        'reason' => DeliveryOutcomeReason::NotHome->value,
        'note' => 'Toqué tres veces',
    ]);

    $entrega->refresh();

    expect($entrega->outcome_note)->toBe('Toqué tres veces')
        ->and($entrega->notes)->toBe('Portón azul, timbre de abajo');
});

it('una entrega ya cerrada no se reabre repitiendo la petición', function (): void {
    $kelvin = motoristaLlamado('Kelvin');
    $entrega = pedidoDe($kelvin);

    $this->actingAs($kelvin->user)->post(route('portal.deliveries.close', $entrega), [
        'reason' => DeliveryOutcomeReason::Delivered->value,
    ]);

    $this->actingAs($kelvin->user)
        ->post(route('portal.deliveries.close', $entrega), ['reason' => DeliveryOutcomeReason::NotHome->value])
        ->assertSessionHas('panel_error');

    expect($entrega->fresh()->status)->toBe(DeliveryStatus::Delivered);
});

it('lo cerrado hoy sigue en pantalla, se haya cerrado como se haya cerrado', function (): void {
    // Cerrar una entrega la saca de la lista de pendientes; si además desapareciera del todo, pulsar
    // el botón equivocado no tendría arreglo posible: el repartidor no vería ni que lo hizo.
    //
    // Las canceladas se quedaban fuera porque nadie les sellaba la fecha de cierre. Se recorren los
    // tres finales para que un estado nuevo no vuelva a colarse por el mismo hueco.
    $kelvin = motoristaLlamado('Kelvin');

    foreach ([DeliveryOutcomeReason::Delivered, DeliveryOutcomeReason::NotHome, DeliveryOutcomeReason::Refused] as $motivo) {
        $entrega = pedidoDe($kelvin, direccion: 'Calle de '.$motivo->value);
        app(DeliveryService::class)->close($entrega, $motivo);

        expect($entrega->fresh()->delivered_at)->not->toBeNull();
    }

    $html = $this->actingAs($kelvin->user)->get(route('portal.deliveries'))->assertOk()->getContent();

    expect($html)->toContain('Cerradas hoy')
        ->toContain('Calle de '.DeliveryOutcomeReason::Refused->value);
});

it('el doble toque no vuelve a cerrar la misma entrega', function (): void {
    // En un móvil, con el pulgar y con prisa, el doble toque no es un caso raro sino el normal. Sin
    // el corte, el segundo volvía a sellar la hora del cobro y machacaba el motivo ya anotado.
    $kelvin = motoristaLlamado('Kelvin');
    $entrega = pedidoDe($kelvin, aCobrar: '450');

    $this->actingAs($kelvin->user)->post(route('portal.deliveries.close', $entrega), [
        'reason' => DeliveryOutcomeReason::Delivered->value, 'collected' => '1',
    ]);

    $primerCobro = $entrega->fresh()->collected_at;

    $this->actingAs($kelvin->user)
        ->post(route('portal.deliveries.close', $entrega), [
            'reason' => DeliveryOutcomeReason::Delivered->value, 'collected' => '1',
        ])
        ->assertSessionHas('panel_error');

    expect($entrega->fresh()->collected_at->toDateTimeString())->toBe($primerCobro->toDateTimeString());
});

// -------------------------------------------------------------------- El dinero

it('al entregar y cobrar, el dinero queda pendiente de liquidar', function (): void {
    $kelvin = motoristaLlamado('Kelvin');
    $entrega = pedidoDe($kelvin, aCobrar: '450');

    $this->actingAs($kelvin->user)->post(route('portal.deliveries.close', $entrega), [
        'reason' => DeliveryOutcomeReason::Delivered->value,
        'collected' => '1',
    ]);

    $entrega->refresh();

    expect($entrega->collected_at)->not->toBeNull()
        ->and($entrega->settled_at)->toBeNull()
        ->and($entrega->pendienteDeLiquidar())->toBeTrue();
});

it('cerrar con cobro no registra ningún ingreso: eso lo hizo la venta', function (): void {
    // La misma regla que gobierna la liquidación. Aquí se vigila desde la otra punta: el cobro entra
    // por el móvil del repartidor y tampoco puede duplicar el dinero.
    $kelvin = motoristaLlamado('Kelvin');
    $entrega = pedidoDe($kelvin, aCobrar: '450');

    $movimientosAntes = DB::table('financial_movements')->count();

    $this->actingAs($kelvin->user)->post(route('portal.deliveries.close', $entrega), [
        'reason' => DeliveryOutcomeReason::Delivered->value,
        'collected' => '1',
    ]);

    expect(DB::table('financial_movements')->count())->toBe($movimientosAntes);
});

it('no se cobra lo que no se entregó', function (): void {
    // Sin esto, marcar «no estaba nadie» con la casilla de cobro puesta le apuntaría al motorista un
    // dinero que nadie le dio, y la caja se lo reclamaría al volver.
    $kelvin = motoristaLlamado('Kelvin');
    $entrega = pedidoDe($kelvin, aCobrar: '450');

    $this->actingAs($kelvin->user)->post(route('portal.deliveries.close', $entrega), [
        'reason' => DeliveryOutcomeReason::NotHome->value,
        'collected' => '1',
    ]);

    expect($entrega->fresh()->collected_at)->toBeNull();
});

it('la pantalla le canta cuánto lleva encima', function (): void {
    $kelvin = motoristaLlamado('Kelvin');
    $entrega = pedidoDe($kelvin, aCobrar: '450');
    app(DeliveryService::class)->close($entrega, DeliveryOutcomeReason::Delivered, cobro: true);

    $this->actingAs($kelvin->user)->get(route('portal.deliveries'))
        ->assertOk()
        ->assertSee('450.00');
});

// -------------------------------------------------------------------- La sesión de la calle

it('el repartidor no llega a ninguna otra pantalla del panel', function (): void {
    // Es el usuario que anda por la calle con el teléfono y el que más fácil lo pierde. Lo que su
    // sesión puede hacer en otras manos tiene que ser exactamente esto y nada más.
    $kelvin = motoristaLlamado('Kelvin');

    foreach (['panel.products', 'panel.sales', 'panel.quick-pos.index', 'panel.deliveries', 'dashboard'] as $ruta) {
        $this->actingAs($kelvin->user)->get(route($ruta))
            ->assertRedirect(route('portal.deliveries'));
    }
});

it('pero sí puede cerrar su sesión', function (): void {
    // Sin la ruta de salida en la lista blanca, el repartidor queda atrapado y solo sale borrando
    // las cookies del móvil.
    $kelvin = motoristaLlamado('Kelvin');

    $this->actingAs($kelvin->user)->post(route('logout'))->assertRedirect();
    $this->assertGuest();
});

// -------------------------------------------------------------------- El vínculo con la ficha

it('vincular una cuenta con un empleado la deja lista para repartir', function (): void {
    $kelvin = motoristaLlamado('Kelvin');

    expect($kelvin->user_id)->not->toBeNull()
        ->and($kelvin->user->hasRole('driver'))->toBeTrue();
});

it('un empleado que ya tiene cuenta no se le puede colgar a otra', function (): void {
    // Sería robarle el acceso: sus entregas pasarían a aparecer en el móvil de otra persona.
    $kelvin = motoristaLlamado('Kelvin');

    $dueno = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Dueña',
        'email' => 'duena@reparto.test', 'password' => 'secret-password',
    ]), 'owner');

    $this->actingAs($dueno)->post(route('panel.users.store'), [
        'name' => 'Otro', 'email' => 'otro@reparto.test',
        'password' => 'secret-password-99', 'password_confirmation' => 'secret-password-99',
        'role' => 'driver', 'employee_id' => $kelvin->id,
    ])->assertSessionHasErrors('employee_id');

    expect($kelvin->fresh()->user_id)->toBe($kelvin->user_id);
});

it('cambiar la cuenta de empleado suelta la anterior', function (): void {
    // Dos empleados apuntando al mismo usuario dejarían al portal eligiendo entre dos, con el dinero
    // de uno en la pantalla del otro.
    $kelvin = motoristaLlamado('Kelvin');
    $nuevo = Employee::create(['name' => 'Nuevo', 'is_active' => true]);

    $dueno = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Dueña',
        'email' => 'duena@reparto.test', 'password' => 'secret-password',
    ]), 'owner');

    $this->actingAs($dueno)->put(route('panel.users.update', $kelvin->user), [
        'name' => 'Kelvin', 'email' => 'kelvin@reparto.test',
        'role' => 'driver', 'is_active' => '1', 'employee_id' => $nuevo->id,
    ])->assertSessionHasNoErrors();

    expect($kelvin->fresh()->user_id)->toBeNull()
        ->and($nuevo->fresh()->user_id)->toBe($kelvin->user->id);
});

it('el rol de repartidor existe en la empresa y lleva su permiso', function (): void {
    // Los roles son POR EMPRESA (spatie con equipos): hay un «driver» por cada una. Si no existiera,
    // la pantalla de usuarios ofrecería un rol que no se puede asignar.
    $rol = Role::query()->where('company_id', $this->company->id)->where('name', 'driver')->first();

    expect($rol)->not->toBeNull()
        ->and($rol->hasPermissionTo('delivery.own'))->toBeTrue()
        // Y ni uno más: es la sesión que anda por la calle.
        ->and($rol->permissions)->toHaveCount(1);
});

// ------------------------------------------------------------ La ficha de un repartidor nunca falta

it('al crear un repartidor se le crea la ficha de empleado sola', function (): void {
    // Un usuario con rol «Repartidor» y sin ficha NO SIRVE PARA NADA: no sale en la lista de
    // repartidores de Entregas —que se saca de los empleados, no de los usuarios— y su portal aparece
    // vacío. Se podían crear así y nada lo impedía: la pantalla lo avisaba en amarillo y ahí acababa.
    $dueno = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Dueña',
        'email' => 'duena@reparto.test', 'password' => 'secret-password',
    ]), 'owner');

    $this->actingAs($dueno)->post(route('panel.users.store'), [
        'name' => 'Yael', 'email' => 'yael@reparto.test',
        'password' => 'secret-password-99', 'password_confirmation' => 'secret-password-99',
        'role' => 'driver',
    ])->assertSessionHasNoErrors();

    $ficha = Employee::query()->where('name', 'Yael')->first();

    expect($ficha)->not->toBeNull()
        ->and($ficha->is_active)->toBeTrue()
        ->and($ficha->user->email)->toBe('yael@reparto.test');
});

it('y así aparece en la lista de repartidores de Entregas', function (): void {
    // Es la queja concreta: se crea el usuario con el rol y no se ve por ningún lado.
    $dueno = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Dueña',
        'email' => 'duena2@reparto.test', 'password' => 'secret-password',
    ]), 'owner');

    $this->actingAs($dueno)->post(route('panel.users.store'), [
        'name' => 'Yael', 'email' => 'yael2@reparto.test',
        'password' => 'secret-password-99', 'password_confirmation' => 'secret-password-99',
        'role' => 'driver',
    ]);

    $this->actingAs($dueno)->get(route('panel.deliveries'))->assertOk()->assertSee('Yael');
});

it('a un dueño no se le inventa una ficha: no todo usuario está en la plantilla', function (): void {
    $dueno = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Dueña',
        'email' => 'duena3@reparto.test', 'password' => 'secret-password',
    ]), 'owner');

    $this->actingAs($dueno)->post(route('panel.users.store'), [
        'name' => 'Otro Dueño', 'email' => 'otro@reparto.test',
        'password' => 'secret-password-99', 'password_confirmation' => 'secret-password-99',
        'role' => 'owner',
    ]);

    expect(Employee::query()->where('name', 'Otro Dueño')->exists())->toBeFalse();
});

it('si ya se eligió una ficha, no se crea otra', function (): void {
    // Duplicarla dejaría dos empleados con el mismo nombre y las entregas repartidas entre los dos.
    $dueno = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Dueña',
        'email' => 'duena4@reparto.test', 'password' => 'secret-password',
    ]), 'owner');

    $existente = Employee::create(['name' => 'Ya fichado', 'is_active' => true]);

    $this->actingAs($dueno)->post(route('panel.users.store'), [
        'name' => 'Yael', 'email' => 'yael4@reparto.test',
        'password' => 'secret-password-99', 'password_confirmation' => 'secret-password-99',
        'role' => 'driver', 'employee_id' => $existente->id,
    ])->assertSessionHasNoErrors();

    expect(Employee::query()->count())->toBe(1)
        ->and($existente->fresh()->user->email)->toBe('yael4@reparto.test');
});

it('ascender a alguien a repartidor también le crea la ficha', function (): void {
    $dueno = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Dueña',
        'email' => 'duena5@reparto.test', 'password' => 'secret-password',
    ]), 'owner');

    $cajero = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Cajero Ascendido',
        'email' => 'cajero5@reparto.test', 'password' => 'secret-password',
    ]), 'staff');

    $this->actingAs($dueno)->put(route('panel.users.update', $cajero), [
        'name' => 'Cajero Ascendido', 'email' => 'cajero5@reparto.test',
        'role' => 'driver', 'is_active' => '1',
    ])->assertSessionHasNoErrors();

    expect(Employee::query()->where('name', 'Cajero Ascendido')->exists())->toBeTrue();
});
