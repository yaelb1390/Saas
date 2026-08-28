<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Cash\Enums\CashSessionStatus;
use App\Modules\Cash\Models\CashRegister;
use App\Modules\Cash\Models\CashSession;
use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Models\Warehouse;
use App\Modules\Core\Services\CompanyHealthService;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\Inventory\Models\Product;
use App\Modules\Sales\Models\Sale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/*
 * El estado de CADA empresa creada.
 *
 * El monitoreo respondía solo «¿cómo está la plataforma?»: contadores globales y dos registros de
 * sucesos. Para saber si a un cliente concreto le iba bien había que ir a mirar sus datos uno por
 * uno, y para enterarse de que se estaba yendo, esperar a que cancelara.
 *
 * Lo que más se vigila aquí son dos cosas: que una señal NO se cuele de una empresa a otra —es un
 * servicio que consulta a propósito sin el aislamiento por empresa— y que el coste no crezca con el
 * número de empresas.
 */

uses(RefreshDatabase::class);

/** Lee el estado de una empresa por su nombre, sin caché de por medio. */
function estadoDe(string $nombre): array
{
    cache()->forget('platform:empresas');

    $fila = app(CompanyHealthService::class)->porEmpresa()->firstWhere('nombre', $nombre);

    expect($fila)->not->toBeNull("No apareció «{$nombre}» en el estado por empresa.");

    return $fila;
}

beforeEach(function (): void {
    app(CurrentCompany::class)->forget();
    cache()->forget('platform:empresas');

    $this->empresa = app(CompanyService::class)->create(new CreateCompanyData(name: 'La Vigilada'));
    app(CurrentCompany::class)->set($this->empresa->id);
});

// ------------------------------------------------------------------ Lo que impide vender hoy

it('marca la empresa que se quedó sin almacén por omisión', function (): void {
    /*
     * Es el aviso que más urge: sin almacén por omisión, el cobro del punto de venta y el mostrador
     * de repuestos caen con «No hay un almacén configurado», y eso no aparecía en ninguna pantalla
     * hasta que fallaba con un cliente delante.
     */
    expect(estadoDe('La Vigilada')['sin_almacen'])->toBeFalse();

    Warehouse::query()->where('is_default', true)->update(['is_default' => false]);

    expect(estadoDe('La Vigilada')['sin_almacen'])->toBeTrue();
});

it('marca la empresa que se quedó sin comprobantes que emitir', function (): void {
    // Con números disponibles no pasa nada; agotada, sí. Son las mismas reglas del modelo.
    DB::table('fiscal_sequences')->insert([
        'company_id' => $this->empresa->id, 'type' => 'B02',
        'next_number' => 5, 'range_from' => 1, 'range_to' => 100, 'number_length' => 8,
        'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);

    expect(estadoDe('La Vigilada')['sin_ncf'])->toBeFalse();

    // Se pasó del final del rango: no queda ni un número.
    DB::table('fiscal_sequences')->where('company_id', $this->empresa->id)->update(['next_number' => 101]);

    expect(estadoDe('La Vigilada')['sin_ncf'])->toBeTrue();
});

it('una secuencia VENCIDA cuenta igual que una agotada', function (): void {
    // Un rango con números pero caducado tampoco sirve: la DGII no lo acepta.
    DB::table('fiscal_sequences')->insert([
        'company_id' => $this->empresa->id, 'type' => 'B02',
        'next_number' => 5, 'range_from' => 1, 'range_to' => 100, 'number_length' => 8,
        'expires_at' => now()->subDay()->toDateString(),
        'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);

    expect(estadoDe('La Vigilada')['sin_ncf'])->toBeTrue();
});

it('una empresa sin secuencias NO se marca: es que no usa NCF', function (): void {
    // Marcar a quien no factura con NCF llenaría la pantalla de avisos falsos, y un panel con avisos
    // que no importan se deja de mirar.
    expect(estadoDe('La Vigilada')['sin_ncf'])->toBeFalse();
});

it('marca la caja que lleva más de un día abierta', function (): void {
    $caja = CashRegister::query()->firstOrCreate(
        ['company_id' => $this->empresa->id, 'name' => 'Caja 1'],
        ['is_active' => true],
    );

    $sesion = CashSession::create([
        'company_id' => $this->empresa->id, 'cash_register_id' => $caja->id,
        'status' => CashSessionStatus::Open, 'opening_amount' => '1000', 'opened_at' => now(),
    ]);

    // Abierta hoy: es una jornada, no un descuido.
    expect(estadoDe('La Vigilada')['caja_abierta'])->toBeFalse();

    $sesion->forceFill(['opened_at' => now()->subDays(3)])->save();

    expect(estadoDe('La Vigilada')['caja_abierta'])->toBeTrue();
});

// ------------------------------------------------------------------ Si está dejando de usarlo

it('una empresa recién creada sin ventas NO cuenta como abandonada', function (): void {
    /*
     * Es la diferencia entre «acaba de empezar» y «no arrancó nunca». Sin ella, cada empresa nueva
     * saldría marcada el primer día y el aviso dejaría de significar algo.
     */
    expect(estadoDe('La Vigilada')['nunca_vendio'])->toBeFalse()
        ->and(estadoDe('La Vigilada')['ultima_venta'])->toBeNull();
});

it('una empresa de hace un mes sin una sola venta SÍ se marca', function (): void {
    $this->empresa->forceFill(['created_at' => now()->subMonth()])->save();

    expect(estadoDe('La Vigilada')['nunca_vendio'])->toBeTrue();
});

// ------------------------------------------------------------------ El plan

it('marca a quien tiene más usuarios de los que su plan permite, y NO le bloquea nada', function (): void {
    /*
     * `max_users` se guarda al crear el plan y no se comprueba en ninguna parte de la aplicación.
     * Una empresa con plan de tres usuarios puede tener quince y nadie se entera: es una fuga de
     * ingresos invisible.
     *
     * Esto INFORMA. Cortarle el acceso a una empresa que lleva meses pasada, y hacerlo desde un
     * cambio de monitoreo, sería una sorpresa muy desagradable.
     */
    $plan = DB::table('plans')->insertGetId([
        'name' => 'Mini', 'slug' => 'mini-'.uniqid(), 'price' => '100', 'billing_cycle' => 'monthly',
        'max_users' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);

    DB::table('subscriptions')->where('company_id', $this->empresa->id)->delete();
    DB::table('subscriptions')->insert([
        'company_id' => $this->empresa->id, 'plan_id' => $plan, 'status' => 'active',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    foreach (['uno', 'dos'] as $n) {
        User::create([
            'company_id' => $this->empresa->id, 'name' => $n,
            'email' => $n.'@vigilada.test', 'password' => 'secret-password',
        ]);
    }

    $estado = estadoDe('La Vigilada');

    expect($estado['pasada_de_plan'])->toBeTrue()
        ->and($estado['usuarios'])->toBe(2)
        ->and($estado['limite_usuarios'])->toBe(1)
        // Y la empresa sigue activa: informar no es bloquear.
        ->and($this->empresa->fresh()->is_active)->toBeTrue();
});

// ------------------------------------------------------------------ Que no se crucen las empresas

it('las señales de una empresa NO aparecen en la otra', function (): void {
    /*
     * ESTE ES EL TEST QUE MÁS IMPORTA.
     *
     * Este servicio consulta a propósito SIN el aislamiento por empresa —tiene que ver todas—, así
     * que es justo donde un olvido mezcla los datos de dos negocios sin dar ningún error. Se vería
     * como un aviso en la empresa equivocada, y nadie lo notaría.
     */
    $otra = app(CompanyService::class)->create(new CreateCompanyData(name: 'La Otra'));

    // A la otra se le rompe el almacén; a la vigilada no se le toca.
    Warehouse::withoutGlobalScopes()
        ->where('company_id', $otra->id)->update(['is_default' => false]);

    expect(estadoDe('La Otra')['sin_almacen'])->toBeTrue()
        ->and(estadoDe('La Vigilada')['sin_almacen'])->toBeFalse();

    // Y los productos de una no cuentan para la otra.
    app(CurrentCompany::class)->set($this->empresa->id);
    Product::create(['sku' => 'V-1', 'name' => 'De la vigilada', 'price' => '10']);

    expect(estadoDe('La Vigilada')['sin_productos'])->toBeFalse()
        ->and(estadoDe('La Otra')['sin_productos'])->toBeTrue();
});

// ------------------------------------------------------------------ Lo que cuesta

it('el coste NO crece con el número de empresas', function (): void {
    /*
     * Una consulta por señal, no una por empresa. Con diez señales y treinta empresas, preguntar una
     * a una serían trescientas consultas en la pantalla que se abre justo cuando algo va mal.
     *
     * Se mide con DIEZ empresas y se fija un número: es lo único que impide que alguien meta un
     * `foreach` con una consulta dentro y nadie se entere hasta que la plataforma crezca.
     */
    for ($i = 0; $i < 9; $i++) {
        app(CompanyService::class)->create(new CreateCompanyData(name: 'Empresa '.$i));
    }

    cache()->forget('platform:empresas');

    $consultas = 0;
    DB::listen(function () use (&$consultas): void {
        $consultas++;
    });

    $filas = app(CompanyHealthService::class)->porEmpresa();

    expect($filas)->toHaveCount(10)
        // Trece señales más la de empresas. El margen deja sitio a lo que haga el marco sin permitir
        // que vuelva una consulta por empresa, que serían más de cien.
        ->and($consultas)->toBeLessThan(20);
});

it('el resultado se guarda en caché: la segunda mirada no consulta nada', function (): void {
    // Esta pantalla la abre el operador y la deja abierta. Sin caché, cada refresco recalcularía
    // trece agregados sobre tablas que crecen todos los días.
    cache()->forget('platform:empresas');
    app(CompanyHealthService::class)->porEmpresa();

    $consultas = 0;
    DB::listen(function () use (&$consultas): void {
        $consultas++;
    });

    app(CompanyHealthService::class)->porEmpresa();

    expect($consultas)->toBe(0);
});

// ------------------------------------------------------------------ Que cada dato sea de su dueño

it('la última venta y el último acceso son los de ESA empresa, no los de la plataforma', function (): void {
    /*
     * La otra cara del test de arriba. Estas dos señales salen de un `max(created_at)` agrupado, y un
     * agrupado mal escrito devuelve el máximo de TODAS las filas para todo el mundo: la pantalla
     * diría que una empresa parada hace meses vendió hoy, que es exactamente al revés de lo que se
     * quiere saber.
     */
    app(CompanyService::class)->create(new CreateCompanyData(name: 'La Otra'));

    Sale::create([
        'company_id' => $this->empresa->id, 'code' => 'V-1', 'status' => 'completed',
        'warehouse_id' => Warehouse::withoutGlobalScopes()->where('company_id', $this->empresa->id)->value('id'),
        'subtotal' => '100.00', 'tax' => '0.00', 'total' => '100.00', 'paid' => '100.00',
        'change' => '0.00', 'payment_method' => 'cash', 'completed_at' => now(),
    ]);

    DB::table('system_events')->insert([
        'company_id' => $this->empresa->id, 'type' => 'auth.login', 'level' => 'info',
        'message' => 'entró', 'created_at' => now(),
    ]);

    expect(estadoDe('La Vigilada')['ultima_venta'])->not->toBeNull()
        ->and(estadoDe('La Vigilada')['ultimo_acceso'])->not->toBeNull()
        // Y a la de al lado no se le pega ninguna de las dos.
        ->and(estadoDe('La Otra')['ultima_venta'])->toBeNull()
        ->and(estadoDe('La Otra')['ultimo_acceso'])->toBeNull();
});

it('marca el bot encendido y sin información del negocio', function (): void {
    /*
     * Sin información no se queda callado: contesta «esa no te la sé» a todo el mundo y pasa cada
     * conversación a una persona. Desde fuera parece roto; desde el panel, encendido y correcto.
     */
    DB::table('wa_bot_settings')->insert([
        'company_id' => $this->empresa->id, 'is_active' => true,
        'business_info' => null, 'created_at' => now(), 'updated_at' => now(),
    ]);

    expect(estadoDe('La Vigilada')['bot_sin_info'])->toBeTrue();

    DB::table('wa_bot_settings')->where('company_id', $this->empresa->id)
        ->update(['business_info' => 'Vendemos repuestos. Abrimos de 8 a 6.']);

    expect(estadoDe('La Vigilada')['bot_sin_info'])->toBeFalse();
});
