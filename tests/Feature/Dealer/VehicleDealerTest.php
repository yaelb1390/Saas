<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Support\DbTable;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\CRM\Models\Customer;
use App\Modules\Dealer\DTOs\CreateDealData;
use App\Modules\Dealer\DTOs\CreateJobData;
use App\Modules\Dealer\DTOs\CreateVehicleData;
use App\Modules\Dealer\Enums\DealInstallmentStatus;
use App\Modules\Dealer\Enums\DealStatus;
use App\Modules\Dealer\Enums\VehicleStatus;
use App\Modules\Dealer\Exceptions\DealerException;
use App\Modules\Dealer\Models\Vehicle;
use App\Modules\Dealer\Services\VehicleDealService;
use App\Modules\Dealer\Services\VehicleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

/*
 * El dealer de vehículos.
 *
 * Dos cosas se vigilan más que el resto:
 *
 *   · QUE UN CARRO NO SE VENDA DOS VECES. Es el fallo propio de este negocio: dos vendedores
 *     atendiendo a la vez, los dos leen «disponible» y los dos cierran.
 *   · QUE EL COSTO NO SE FILTRE. La rejilla se alimenta de JSON, así que esconder la columna en el
 *     navegador mientras el costo viaja dentro de la respuesta no es una restricción: es una cortina
 *     que cualquiera levanta abriendo la pestaña de red.
 */

uses(RefreshDatabase::class);

/** Un usuario con su rol, en la empresa activa. */
function usuarioDealer(int $companyId, string $rol, string $correo): User
{
    return withRole(User::create([
        'company_id' => $companyId, 'name' => 'Quien sea',
        'email' => $correo, 'password' => 'secret-password',
    ]), $rol);
}

/** Registra una unidad con precio y costo. */
function unidad(string $marca = 'Toyota', string $modelo = 'Corolla', string $costo = '400000', string $precio = '520000'): Vehicle
{
    return app(VehicleService::class)->create(new CreateVehicleData(
        make: $marca, model: $modelo, year: 2019, purchaseCost: $costo, askingPrice: $precio,
    ));
}

beforeEach(function (): void {
    $this->withoutVite();
    app(CurrentCompany::class)->forget();
    DbTable::olvidar();

    $this->empresa = app(CompanyService::class)->create(new CreateCompanyData(name: 'Auto Import'));
    $this->empresa->update(['modules' => ['dealer', 'crm']]);
    app(CurrentCompany::class)->set($this->empresa->id);

    $this->duena = usuarioDealer($this->empresa->id, 'owner', 'duena@dealer.test');
    // El cajero NO recibe los permisos del dealer: cobra en el mostrador, no administra un patio.
    $this->cajera = usuarioDealer($this->empresa->id, 'staff', 'cajera@dealer.test');

    $this->cliente = Customer::create(['company_id' => $this->empresa->id, 'name' => 'Pedro Comprador']);
});

afterEach(fn () => DbTable::olvidar());

// ------------------------------------------------------------------ Que no se venda dos veces

it('un vehículo apartado NO admite un segundo trato', function (): void {
    /*
     * El fallo caro de este dominio. Sin la comprobación, dos vendedores venden el mismo carro y el
     * dealer se entera cuando los dos clientes vienen a buscarlo.
     */
    $carro = unidad();

    $tratos = app(VehicleDealService::class);

    $tratos->open(new CreateDealData(
        vehicleId: $carro->id, customerId: $this->cliente->id, agreedPrice: '500000',
    ));

    $otro = Customer::create(['company_id' => $this->empresa->id, 'name' => 'Ana Segunda']);

    expect(fn () => $tratos->open(new CreateDealData(
        vehicleId: $carro->id, customerId: $otro->id, agreedPrice: '510000',
    )))->toThrow(DealerException::class);

    // Y sigue habiendo UN solo trato sobre esa unidad.
    expect($carro->fresh()->status)->toBe(VehicleStatus::Reserved)
        ->and($carro->deals()->count())->toBe(1);
});

it('la consulta que decide el trato pide el candado de la fila', function (): void {
    /*
     * ESTE TEST NO SE PUEDE HACER MIRANDO EL SQL QUE SE EJECUTA, y es la razón de que exista el
     * ámbito `bloqueadaParaTrato`.
     *
     * Los tests corren sobre SQLite, que NO tiene `FOR UPDATE`: Laravel lo descarta al compilar, sin
     * avisar. Un test que escuchara las consultas reales no encontraría el candado ni con el código
     * bien escrito, y al revés —si alguien lo quitara— tampoco cambiaría nada. No probaría nada.
     *
     * Así que se compila la MISMA consulta que usa el servicio con la gramática de PostgreSQL, que
     * es la base de producción y donde el candado se aplica de verdad. Si alguien le quita el
     * `lockForUpdate` al ámbito, esto se cae.
     */
    $sql = Vehicle::on('pgsql')->bloqueadaParaTrato(1)->toSql();

    expect(mb_strtolower($sql))->toContain('for update');
});

it('abrir el trato usa esa consulta y no otra', function (): void {
    // La otra mitad: que el ámbito exista con el candado no sirve de nada si el servicio no lo usa.
    $fuente = file_get_contents(app_path('Modules/Dealer/Services/VehicleDealService.php'));

    expect($fuente)->toContain('bloqueadaParaTrato($data->vehicleId)');
});

it('dar de baja el trato devuelve la unidad al patio', function (): void {
    // Un apartado que no se concreta y deja el carro bloqueado para siempre es peor que no haberlo
    // registrado.
    $carro = unidad();
    $tratos = app(VehicleDealService::class);

    $trato = $tratos->open(new CreateDealData(
        vehicleId: $carro->id, customerId: $this->cliente->id, agreedPrice: '500000',
    ));

    $tratos->cancel($trato);

    expect($carro->fresh()->status)->toBe(VehicleStatus::Available)
        ->and($trato->fresh()->status)->toBe(DealStatus::Cancelled);
});

// ------------------------------------------------------------------ El costo no se filtra

it('el JSON de la rejilla NO trae el costo si no se puede ver', function (): void {
    /*
     * ESTE ES EL TEST QUE MÁS IMPORTA DE LA PANTALLA.
     *
     * Se comprueba sobre la RESPUESTA y no sobre el HTML: una columna oculta con el dato dentro del
     * JSON se lee abriendo la pestaña de red del navegador. Las claves no se ponen a cero ni a nulo,
     * sencillamente no existen.
     */
    unidad(costo: '400000', precio: '520000');

    // Un usuario que ve el patio pero no lo administra: se le da el permiso de ver a secas.
    $vendedor = User::create([
        'company_id' => $this->empresa->id, 'name' => 'Vendedor',
        'email' => 'vendedor@dealer.test', 'password' => 'secret-password',
    ]);
    $vendedor->givePermissionTo('vehicles.view');

    $fila = $this->actingAs($vendedor)->getJson(route('panel.vehicles.data'))
        ->assertOk()
        ->json('filas.0');

    expect($fila)->toHaveKey('precio')
        ->and($fila)->not->toHaveKey('costo')
        ->and($fila)->not->toHaveKey('gastos')
        ->and($fila)->not->toHaveKey('margen')
        ->and($fila)->not->toHaveKey('costo_real');
});

it('quien administra el patio SÍ recibe el costo y el margen', function (): void {
    // La otra cara: si tampoco le llegara al dueño, la pantalla no serviría para lo que se hizo.
    unidad(costo: '400000', precio: '520000');

    $fila = $this->actingAs($this->duena)->getJson(route('panel.vehicles.data'))
        ->assertOk()
        ->json('filas.0');

    // Se compara sin exigir el tipo: JSON manda 400000 sin decimales y PHP lo lee como entero.
    expect((float) $fila['costo'])->toBe(400000.0)
        ->and((float) $fila['margen'])->toBe(120000.0);
});

// ------------------------------------------------------------------ El costo real

it('un trabajo de taller sube el costo real y baja el margen de ESA unidad', function (): void {
    /*
     * Un dealer que compra en 400 mil y gasta 90 mil en dejarlo presentable no gana 100 mil
     * vendiendo en 500: gana 10. Sin esto el margen sería una cifra bonita y falsa, que es peor que
     * no tener cifra.
     */
    $carro = unidad(costo: '400000', precio: '520000');
    $otro = unidad('Honda', 'Civic', costo: '300000', precio: '400000');

    app(VehicleService::class)->addJob(new CreateJobData(
        vehicleId: $carro->id, description: 'Pintura y gomas', cost: '90000', done: true,
    ));

    $carro->refresh();
    $otro->refresh();

    expect($carro->gastos())->toBe('90000.00')
        ->and($carro->costoReal())->toBe('490000.00')
        ->and($carro->margen())->toBe('30000.00')
        // Y el gasto NO se le pega a la unidad de al lado.
        ->and($otro->gastos())->toBe('0.00')
        ->and($otro->margen())->toBe('100000.00');
});

// ------------------------------------------------------------------ El financiamiento

it('las cuotas suman exactamente lo que queda debiendo más el interés', function (): void {
    // 500.000 − 100.000 de inicial = 400.000, más 10% = 440.000 en 3 cuotas. No divide exacto: la
    // última tiene que absorber los céntimos o el trato nunca llegaría a saldo cero.
    $carro = unidad();

    $trato = app(VehicleDealService::class)->open(new CreateDealData(
        vehicleId: $carro->id, customerId: $this->cliente->id,
        agreedPrice: '500000', downPayment: '100000',
        financing: 'installments', interestRate: '10',
        frequency: 'monthly', installmentsCount: 3, startDate: '2026-09-01',
    ));

    $cuotas = $trato->installments()->get();
    $suma = $cuotas->reduce(fn (string $t, $c): string => bcadd($t, $c->amount, 2), '0');

    expect($cuotas)->toHaveCount(3)
        ->and($trato->balance)->toBe('440000.00')
        ->and($suma)->toBe('440000.00');
});

it('el abono se aplica a la cuota MÁS VIEJA pendiente', function (): void {
    // Es como se cobra de verdad. Aplicarlo a la más nueva dejaría cuotas viejas vencidas mientras
    // las futuras se saldan, que no tiene sentido para nadie.
    $carro = unidad();
    $tratos = app(VehicleDealService::class);

    $trato = $tratos->open(new CreateDealData(
        vehicleId: $carro->id, customerId: $this->cliente->id,
        agreedPrice: '300000', financing: 'installments', interestAmount: '0',
        frequency: 'monthly', installmentsCount: 3, startDate: '2026-09-01',
    ));

    $tratos->registerPayment($trato, '100000');

    $cuotas = $trato->fresh()->installments()->get();

    expect($cuotas[0]->status)->toBe(DealInstallmentStatus::Paid)
        ->and($cuotas[1]->status)->toBe(DealInstallmentStatus::Pending)
        ->and($trato->fresh()->balance)->toBe('200000.00');
});

it('no se puede abonar más que el saldo', function (): void {
    $carro = unidad();
    $tratos = app(VehicleDealService::class);

    $trato = $tratos->open(new CreateDealData(
        vehicleId: $carro->id, customerId: $this->cliente->id,
        agreedPrice: '300000', financing: 'installments', interestAmount: '0',
        frequency: 'monthly', installmentsCount: 3, startDate: '2026-09-01',
    ));

    expect(fn () => $tratos->registerPayment($trato, '999999'))->toThrow(DealerException::class);
});

// ------------------------------------------------------------------ Que no se crucen las empresas

it('el vehículo de una empresa NO existe para la otra', function (): void {
    $mio = unidad();

    $otra = app(CompanyService::class)->create(new CreateCompanyData(name: 'Otro Dealer'));
    app(CurrentCompany::class)->set($otra->id);

    expect(Vehicle::query()->count())->toBe(0)
        ->and(Vehicle::query()->whereKey($mio->id)->first())->toBeNull();
});

it('no se le vende un carro a un cliente de otra empresa', function (): void {
    // El id llega del formulario, así que se comprueba en el servidor. Sin esto, un id ajeno crearía
    // un trato que enlaza dos negocios distintos.
    $carro = unidad();

    $otra = app(CompanyService::class)->create(new CreateCompanyData(name: 'Otro Dealer'));
    $ajeno = Customer::create(['company_id' => $otra->id, 'name' => 'De la otra']);

    app(CurrentCompany::class)->set($this->empresa->id);

    expect(fn () => app(VehicleDealService::class)->open(new CreateDealData(
        vehicleId: $carro->id, customerId: $ajeno->id, agreedPrice: '500000',
    )))->toThrow(DealerException::class);
});

// ------------------------------------------------------------------ Quién entra

it('el cajero no entra al patio', function (): void {
    $this->actingAs($this->cajera)->get(route('panel.vehicles'))->assertForbidden();
    $this->actingAs($this->cajera)->get(route('panel.vehicle-deals'))->assertForbidden();
    $this->actingAs($this->cajera)->get(route('panel.vehicle-jobs'))->assertForbidden();
});

it('sin el módulo contratado las pantallas quedan cerradas', function (): void {
    // El permiso dice lo que el usuario PUEDE hacer; el módulo, lo que la empresa COMPRÓ. Son cosas
    // distintas y las dos tienen que cerrar la puerta.
    $this->empresa->update(['modules' => ['pos']]);

    $this->actingAs($this->duena)->get(route('panel.vehicles'))->assertForbidden();
});

it('el dueño abre las tres pantallas', function (): void {
    $this->actingAs($this->duena)->get(route('panel.vehicles'))->assertOk()->assertSee('Patio de vehículos');
    $this->actingAs($this->duena)->get(route('panel.vehicle-deals'))->assertOk()->assertSee('Ventas y apartados');
    $this->actingAs($this->duena)->get(route('panel.vehicle-jobs'))->assertOk()->assertSee('Taller');
});

// ------------------------------------------------------------------ Sin las tablas

it('la pantalla se pinta y explica qué falta SIN las tablas', function (): void {
    /*
     * Aquí las migraciones se aplican a mano y el despliegue no las corre: entre que sale el código y
     * alguien migra pasan horas. En ese hueco la pantalla tiene que decir qué falta, no devolver un
     * 500. Es el fallo exacto que ya tumbó Redes sociales en producción.
     */
    Schema::drop('vehicle_deal_payments');
    Schema::drop('vehicle_installments');
    Schema::drop('vehicle_deals');
    Schema::drop('vehicle_jobs');
    Schema::drop('vehicles');
    DbTable::olvidar();

    $this->actingAs($this->duena)->get(route('panel.vehicles'))
        ->assertOk()
        ->assertSee('Falta preparar la base de datos');

    $this->actingAs($this->duena)->get(route('panel.vehicle-deals'))->assertOk();
    $this->actingAs($this->duena)->get(route('panel.vehicle-jobs'))->assertOk();

    // Y la rejilla no revienta: devuelve vacío diciendo por qué.
    $this->actingAs($this->duena)->getJson(route('panel.vehicles.data'))
        ->assertOk()
        ->assertJson(['falta_migrar' => true]);
});

// ------------------------------------------------------------------ El alta

it('registra la unidad con código propio de la empresa y el chasis en mayúsculas', function (): void {
    // El chasis se copia de una chapa a mano: llega con espacios y en cualquier caja. Sin normalizar,
    // buscar el que se guardó como «1hgcm…» no encontraría nada.
    $this->actingAs($this->duena)->post(route('panel.vehicles.store'), [
        'make' => 'Honda', 'model' => 'CR-V', 'year' => 2020,
        'vin' => '  1hgcm82633a004352 ', 'asking_price' => '650000',
    ])->assertSessionHasNoErrors();

    $carro = Vehicle::query()->firstOrFail();

    expect($carro->code)->toBe('VH-000001')
        ->and($carro->vin)->toBe('1HGCM82633A004352')
        ->and($carro->status)->toBe(VehicleStatus::Available);
});

it('no admite dos vehículos con el mismo chasis en la misma empresa', function (): void {
    $this->actingAs($this->duena)->post(route('panel.vehicles.store'), [
        'make' => 'Honda', 'model' => 'CR-V', 'vin' => '1HGCM82633A004352',
    ])->assertSessionHasNoErrors();

    $this->actingAs($this->duena)->post(route('panel.vehicles.store'), [
        'make' => 'Honda', 'model' => 'Civic', 'vin' => '1hgcm82633a004352',
    ])->assertSessionHasErrors('vin');
});
