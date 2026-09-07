<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Services\CompanyUserService;
use App\Modules\Core\Support\DbTable;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\CRM\Models\Customer;
use App\Modules\Delivery\Enums\DeliveryOutcomeReason;
use App\Modules\Delivery\Enums\DeliveryStatus;
use App\Modules\Delivery\Models\Delivery;
use App\Modules\Delivery\Services\DeliveryService;
use App\Modules\Delivery\Support\EvidenciaDeEntrega;
use App\Modules\HR\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
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

    expect($html)->toContain('Entregas completadas hoy')
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

/*
 * LA REGLA PRINCIPAL DEL PORTAL, SUJETA POR UN TEST.
 *
 * El repartidor trabaja para una empresa de logística: NO cobra, no maneja dinero del pedido y no
 * debe ver importes. El comercio se encarga del pago de principio a fin.
 *
 * Esto era justo lo contrario hace nada: la pantalla abría con «Llevas cobrado y sin entregar en
 * caja RD$610» y el botón decía «Entregada y cobré RD$140». Además de no ser asunto suyo, lo hacía
 * responsable de un dinero que nunca debió llevar encima.
 *
 * El test monta el caso PEOR —una entrega con importe, ya cobrada y sin liquidar— y comprueba que
 * aun así no se le escapa un peso a la pantalla. Sin esto, el dinero vuelve a colarse el día que
 * alguien añada un campo «por comodidad».
 */
it('el repartidor no ve dinero por ningun lado', function (): void {
    $kelvin = motoristaLlamado('Kelvin');

    $cobrada = pedidoDe($kelvin, aCobrar: '450', direccion: 'Calle Primera 1');
    app(DeliveryService::class)->close($cobrada, DeliveryOutcomeReason::Delivered, cobro: true);

    // Y una abierta, también con importe: la que se está repartiendo ahora mismo.
    pedidoDe($kelvin, aCobrar: '610', direccion: 'Calle Pendiente 2');

    $html = $this->actingAs($kelvin->user)->get(route('portal.deliveries'))->assertOk()->getContent();

    // Las dos entregas se ven; lo que no se ve es lo que valen.
    expect($html)->toContain('Calle Pendiente 2');

    // Ni los importes, ni el símbolo, ni una palabra del asunto.
    foreach (['450.00', '610.00', 'RD$'] as $rastro) {
        expect($html)->not->toContain($rastro);
    }

    // En minúsculas, porque el HTML mezcla mayúsculas.
    foreach (['cobr', 'liquidar', 'lleva encima', 'entregar en caja'] as $palabra) {
        expect(mb_strtolower($html))->not->toContain($palabra);
    }
});

/*
 * Y EL DINERO SIGUE REGISTRÁNDOSE, solo que lo hace la oficina.
 *
 * Es la otra mitad, y sin ella «quitar el dinero del portal» podría haber roto la contabilidad sin
 * que nadie se enterara: una venta cobrada en la puerta que nunca se marca es un ingreso que no
 * existe en los libros.
 */
it('la oficina marca el cobro sin que el repartidor lo toque', function (): void {
    $kelvin = motoristaLlamado('Kelvin');
    $entrega = pedidoDe($kelvin, aCobrar: '450');

    // El repartidor solo confirma que entregó: ya no manda el cobro.
    app(DeliveryService::class)->close($entrega, DeliveryOutcomeReason::Delivered);
    expect($entrega->fresh()->collected_at)->toBeNull();

    // La oficina lo marca después, por su propio camino, que es el que existía desde siempre.
    app(DeliveryService::class)->markCollected($entrega->fresh());

    expect($entrega->fresh()->collected_at)->not->toBeNull()
        ->and($entrega->fresh()->pendienteDeLiquidar())->toBeTrue();
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

// ------------------------------------------------------------------ Dónde vive el cliente

/*
 * EL PROBLEMA QUE RESUELVE TODO ESTO.
 *
 * Una dirección dominicana de verdad es «Juana #6, callejón blanco». No hay buscador que la
 * resuelva, así que el repartidor acaba llamando por teléfono para que le expliquen cómo llegar.
 *
 * La salida no es adivinar la dirección: es APRENDERLA. La primera vez se navega por el texto; al
 * llegar, el repartidor marca la puerta, y la próxima entrega a ese cliente sale con el punto
 * exacto. Estos tests sujetan esa cadena.
 */

it('el repartidor guarda donde esta la puerta, y queda en la entrega y en el cliente', function (): void {
    $kelvin = motoristaLlamado('Kelvin');
    $cliente = Customer::create(['name' => 'Juana', 'phone' => '8090000000']);
    $entrega = pedidoDe($kelvin, direccion: 'Juana #6');
    $entrega->update(['customer_id' => $cliente->id]);

    $this->actingAs($kelvin->user)
        ->post(route('portal.deliveries.location', $entrega), [
            'latitude' => '18.4861', 'longitude' => '-69.9312',
        ])
        ->assertRedirect();

    // En la ENTREGA: dónde se dejó este pedido concreto.
    expect((float) $entrega->fresh()->latitude)->toBe(18.4861)
        // Y en la FICHA: dónde vive. Esto es lo que hace que el sistema mejore solo — el próximo
        // pedido de Juana nace con el punto puesto y nadie vuelve a llamar por el callejón.
        ->and((float) $cliente->fresh()->latitude)->toBe(18.4861)
        ->and((float) $cliente->fresh()->longitude)->toBe(-69.9312);
});

/*
 * Y ESO SE NOTA EN LA SIGUIENTE ENTREGA, que es el punto entero del ejercicio: una entrega nueva al
 * mismo cliente, sin punto propio, navega igualmente al exacto porque lo hereda de su ficha.
 */
it('la siguiente entrega al mismo cliente ya navega al punto exacto', function (): void {
    $kelvin = motoristaLlamado('Kelvin');
    $cliente = Customer::create([
        'name' => 'Juana', 'latitude' => '18.4861', 'longitude' => '-69.9312',
    ]);
    $entrega = pedidoDe($kelvin, direccion: 'Juana #6');
    $entrega->update(['customer_id' => $cliente->id]);

    $html = $this->actingAs($kelvin->user)->get(route('portal.deliveries'))->assertOk()->getContent();

    // Navega por coordenadas, no por el texto que nadie entiende.
    expect($html)->toContain('ll=18.4861000,-69.9312000')
        ->and($html)->toContain('destination=18.4861000,-69.9312000');
});

/*
 * SIN PUNTO SE NAVEGA POR LA DIRECCIÓN, y la almohadilla va codificada: sin codificar, el mapa
 * recibe «Juana » a secas y enseña un destino plausible pero equivocado, que es peor que ninguno.
 */
it('sin punto navega por la direccion escrita, codificada', function (): void {
    $kelvin = motoristaLlamado('Kelvin');
    pedidoDe($kelvin, direccion: 'Juana #6');

    $html = $this->actingAs($kelvin->user)->get(route('portal.deliveries'))->assertOk()->getContent();

    expect($html)->toContain('Juana%20%236');
});

/*
 * ESCONDER EL BOTÓN NO ES PROTEGER. La misma regla que ya rige cerrar una entrega: sin esto bastaría
 * teclear el código de la entrega de un compañero para escribirle una ubicación — y peor, para
 * escribírsela a la ficha de SU cliente, que la vería el negocio entero.
 */
it('no puede marcar la ubicacion de una entrega que no lleva el', function (): void {
    $kelvin = motoristaLlamado('Kelvin');
    $ramon = motoristaLlamado('Ramón');
    $ajena = pedidoDe($ramon);

    $this->actingAs($kelvin->user)
        ->post(route('portal.deliveries.location', $ajena), [
            'latitude' => '18.4861', 'longitude' => '-69.9312',
        ])
        ->assertSessionHas('panel_error');

    expect($ajena->fresh()->latitude)->toBeNull();
});

/*
 * EL (0,0) SE RECHAZA. Es lo que manda un GPS que aún no ha fijado posición, y cae en el Atlántico
 * frente a África. Guardarlo sería peor que no guardar nada: el próximo repartidor abriría Waze
 * apuntando al océano y vería un pin, no un error.
 */
it('rechaza el punto que manda un GPS sin fijar', function (): void {
    $kelvin = motoristaLlamado('Kelvin');
    $entrega = pedidoDe($kelvin);

    $this->actingAs($kelvin->user)
        ->post(route('portal.deliveries.location', $entrega), ['latitude' => '0', 'longitude' => '0'])
        ->assertSessionHasErrors('latitude');

    expect($entrega->fresh()->latitude)->toBeNull();
});

it('rechaza una latitud imposible', function (): void {
    $kelvin = motoristaLlamado('Kelvin');
    $entrega = pedidoDe($kelvin);

    $this->actingAs($kelvin->user)
        ->post(route('portal.deliveries.location', $entrega), ['latitude' => '200', 'longitude' => '-69.9'])
        ->assertSessionHasErrors('latitude');
});

/*
 * SIN LA MIGRACIÓN APLICADA la pantalla tiene que seguir funcionando entera, y NO ofrecer guardar.
 * En producción las migraciones se aplican a mano; ofrecer un botón que no guarda nada y decirle que
 * sí sería la peor clase de fallo, porque el repartidor confiaría en un punto que no existe.
 */
it('sin las columnas la pantalla funciona y no ofrece guardar la ubicacion', function (): void {
    $kelvin = motoristaLlamado('Kelvin');
    pedidoDe($kelvin, direccion: 'Calle Duarte 45');

    Schema::table('deliveries', function ($t): void {
        $t->dropColumn(['latitude', 'longitude']);
    });
    DbTable::olvidar();

    $html = $this->actingAs($kelvin->user)->get(route('portal.deliveries'))->assertOk()->getContent();

    expect($html)->toContain('Calle Duarte 45')
        // Sigue pudiendo navegar por la dirección: lo único que se cae es guardar el punto.
        ->and($html)->toContain('waze.com')
        ->and($html)->not->toContain('Guardar esta ubicación');
});

// ------------------------------------------------------------------ La ruta del día

/*
 * LA RUTA DEL DÍA, para hacerse una idea antes de arrancar.
 *
 * Va numerada por el ORDEN EN QUE LAS VA A HACER, y ahí hay una trampa que ya piqué escribiéndola:
 * la lista se saca filtrando la colección, y filtrar en PHP CONSERVA LAS CLAVES ORIGINALES. Usando
 * el índice del array, una entrega ya cerrada dejaba un hueco y la ruta salía numerada «1, 3, 4».
 * De ahí que se use el contador del propio bucle.
 */
it('la ruta del dia numera las paradas en orden y sin huecos', function (): void {
    $kelvin = motoristaLlamado('Kelvin');

    // Una cerrada primero, que es la que abre el hueco en las claves.
    $cerrada = pedidoDe($kelvin, direccion: 'Calle Cerrada 0');
    app(DeliveryService::class)->close($cerrada, DeliveryOutcomeReason::Delivered);

    pedidoDe($kelvin, direccion: 'Calle Uno 1');
    pedidoDe($kelvin, direccion: 'Calle Dos 2');

    $html = $this->actingAs($kelvin->user)->get(route('portal.deliveries'))->assertOk()->getContent();

    expect($html)->toContain('Ver ruta del día')
        ->and($html)->toContain('Calle Uno 1')
        ->and($html)->toContain('Calle Dos 2');

    // Las dos paradas abiertas, numeradas 1 y 2. Si la numeración volviera al índice del array,
    // saldrían «2» y «3» y este test lo diría.
    preg_match_all('/entrega-parada-num">(\d+)</', $html, $numeros);
    expect($numeros[1])->toBe(['1', '2']);
});

// ------------------------------------------------------------------ La foto de la entrega

/*
 * LA EVIDENCIA PROTEGE SOBRE TODO AL REPARTIDOR.
 *
 * Cuando un cliente llama diciendo que no le entregaron nada, sin foto es su palabra contra la del
 * cliente — y el que no tiene con qué defenderse es él. No tiene NADA que ver con el pago: él no
 * cobra, esto solo confirma que la mercancía llegó.
 */
it('el repartidor guarda la foto de la entrega', function (): void {
    Storage::fake('local');

    $kelvin = motoristaLlamado('Kelvin');
    $entrega = pedidoDe($kelvin);

    $this->actingAs($kelvin->user)
        ->post(route('portal.deliveries.evidence', $entrega), [
            'evidence' => UploadedFile::fake()->image('puerta.jpg', 1600, 1200),
        ])
        ->assertRedirect()
        ->assertSessionHas('panel_ok');

    $entrega->refresh();

    expect($entrega->evidence_path)->not->toBeNull()
        ->and($entrega->evidence_at)->not->toBeNull()
        // En su carpeta, y no en la de productos: son cosas distintas y se borran por criterios
        // distintos.
        ->and($entrega->evidence_path)->toStartWith('entregas/')
        ->and(EvidenciaDeEntrega::disk()->exists($entrega->evidence_path))->toBeTrue();
});

/*
 * Esconder el botón no es proteger. Sin la comprobación bastaría teclear el código de la entrega de
 * un compañero para colgarle una foto — a él y al cliente de otro.
 */
it('no puede colgar una foto en la entrega de un companero', function (): void {
    Storage::fake('local');

    $kelvin = motoristaLlamado('Kelvin');
    $ramon = motoristaLlamado('Ramón');
    $ajena = pedidoDe($ramon);

    $this->actingAs($kelvin->user)
        ->post(route('portal.deliveries.evidence', $ajena), [
            'evidence' => UploadedFile::fake()->image('puerta.jpg'),
        ])
        ->assertSessionHas('panel_error');

    expect($ajena->fresh()->evidence_path)->toBeNull();
});

/* Un ejecutable con nombre de foto no entra. */
it('rechaza lo que no es una imagen', function (): void {
    Storage::fake('local');

    $kelvin = motoristaLlamado('Kelvin');
    $entrega = pedidoDe($kelvin);

    $this->actingAs($kelvin->user)
        ->post(route('portal.deliveries.evidence', $entrega), [
            'evidence' => UploadedFile::fake()->create('bicho.php', 10),
        ])
        ->assertSessionHasErrors('evidence');

    expect($entrega->fresh()->evidence_path)->toBeNull();
});

// ------------------------------------------------------------------ El perfil del repartidor

/*
 * SU PERFIL, SIN UNA CIFRA DE DINERO.
 *
 * La ficha de empleado guarda el salario, y esta pantalla la abre él en la calle, muchas veces
 * delante de un cliente. Que el sueldo no aparezca no es un detalle estético.
 */
it('el perfil ensena vehiculo y entregas, y jamas el salario', function (): void {
    $kelvin = motoristaLlamado('Kelvin');
    $kelvin->update(['vehicle' => 'Motor Honda 125', 'phone' => '8095551234', 'salary' => '38500']);

    $entrega = pedidoDe($kelvin);
    app(DeliveryService::class)->close($entrega, DeliveryOutcomeReason::Delivered);

    $html = $this->actingAs($kelvin->user)->get(route('portal.employee'))->assertOk()->getContent();

    expect($html)->toContain('Motor Honda 125')
        ->and($html)->toContain('8095551234')
        ->and($html)->toContain('Entregas realizadas')
        // Disponible, porque no lleva ninguna en ruta.
        ->and($html)->toContain('Disponible')
        // Y ni rastro del sueldo.
        ->and($html)->not->toContain('38500')
        ->and($html)->not->toContain('38,500');
});

/*
 * Y a quien no reparte no se le enseñan rótulos de reparto en blanco: un cajero sin vehículo ni
 * entregas vería «Vehículo: Sin indicar», que no informa, solo hace dudar de si falla algo.
 */
it('a quien no reparte no se le ensenan datos de reparto', function (): void {
    $cajero = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Cajera',
        'email' => 'cajera@reparto.test', 'password' => 'secret-password',
    ]), 'staff');
    Employee::create(['name' => 'Cajera', 'user_id' => $cajero->id, 'is_active' => true]);

    $html = $this->actingAs($cajero)->get(route('portal.employee'))->assertOk()->getContent();

    expect($html)->not->toContain('Entregas realizadas')
        ->and($html)->not->toContain('Vehículo');
});
