<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Models\Audit;
use App\Modules\Core\Models\ErrorEvent;
use App\Modules\Core\Models\SystemEvent;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Support\DbTable;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\CRM\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

/*
 * La búsqueda y los filtros de las tres pestañas: Registro, Errores y Actividad.
 *
 * MonitoringTest ya prueba que la pantalla aísla bien entre empresas y que el buscador del registro
 * baja a minúsculas; este archivo cubre lo que es propio de `MonitoringSearch`: encontrar por PERSONA o
 * EMPRESA (no solo por columnas propias), el desglose de errores multiempresa (no solo «la última
 * empresa» que guarda el grupo), los filtros de estado/orden, la ventana de 7 días por omisión al
 * buscar, que el texto de dentro de un suceso solo se mira con `en_detalle=1`, que un comodín escrito
 * por el usuario se busca como texto y no como comodín, y que ninguna pestaña revienta sin su tabla.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutVite();
    app(CurrentCompany::class)->forget();

    $this->primera = app(CompanyService::class)->create(new CreateCompanyData(name: 'Primera'));
    $this->segunda = app(CompanyService::class)->create(new CreateCompanyData(name: 'Segunda'));

    app(CurrentCompany::class)->set($this->primera->id);

    $this->super = User::create([
        'company_id' => $this->primera->id, 'name' => 'Operador',
        'email' => 'super@buscador.test', 'password' => 'secret-password',
        'is_super_admin' => true,
    ]);
});

// ------------------------------------------------------------------------- El registro

it('encuentra un suceso por el nombre de la empresa, no solo por sus propias columnas', function (): void {
    SystemEvent::olvidarSiHayTabla();
    SystemEvent::create([
        'type' => 'auth.login', 'level' => SystemEvent::INFO, 'message' => 'inicio de sesión',
        'company_id' => $this->segunda->id,
    ]);

    $this->actingAs($this->super)
        ->get(route('platform.monitoring', ['pestana' => 'registro', 'busca' => 'Segunda']))
        ->assertOk()
        ->assertSee('inicio de sesión');
});

it('encuentra un suceso por el nombre o el correo de quien lo hizo', function (): void {
    SystemEvent::olvidarSiHayTabla();
    SystemEvent::create([
        'type' => 'auth.login', 'level' => SystemEvent::INFO, 'message' => 'algo que no lo delata',
        'user_id' => $this->super->id,
    ]);

    $this->actingAs($this->super)
        ->get(route('platform.monitoring', ['pestana' => 'registro', 'busca' => 'super@buscador.test']))
        ->assertOk()
        ->assertSee('algo que no lo delata');
});

it('un comodín escrito por el usuario se busca como texto, no como comodín', function (): void {
    // Si «%» no se neutralizara, «100%» buscaría «cualquier cosa» y el segundo mensaje también saldría.
    SystemEvent::olvidarSiHayTabla();
    SystemEvent::create(['type' => 'auth.failed', 'level' => SystemEvent::AVISO, 'message' => 'intento con 100% de certeza']);
    SystemEvent::create(['type' => 'auth.failed', 'level' => SystemEvent::AVISO, 'message' => 'esto no debería salir']);

    $this->actingAs($this->super)
        ->get(route('platform.monitoring', ['pestana' => 'registro', 'busca' => '100%']))
        ->assertOk()
        ->assertSee('intento con 100% de certeza')
        ->assertDontSee('esto no debería salir');
});

it('sin elegir ventana, buscar solo mira los últimos 7 días', function (): void {
    SystemEvent::olvidarSiHayTabla();
    SystemEvent::create(['type' => 'auth.failed', 'level' => SystemEvent::AVISO, 'message' => 'reciente de verdad']);

    $viejo = SystemEvent::create(['type' => 'auth.failed', 'level' => SystemEvent::AVISO, 'message' => 'reciente de mentira']);
    $viejo->forceFill(['created_at' => now()->subDays(10)])->save();

    $this->actingAs($this->super)
        ->get(route('platform.monitoring', ['pestana' => 'registro', 'busca' => 'reciente']))
        ->assertOk()
        ->assertSee('reciente de verdad')
        ->assertDontSee('reciente de mentira');

    // Con la ventana ampliada a 30 días, el viejo también aparece.
    $this->actingAs($this->super)
        ->get(route('platform.monitoring', ['pestana' => 'registro', 'busca' => 'reciente', 'dias' => '30']))
        ->assertOk()
        ->assertSee('reciente de mentira');
});

it('el detalle de un suceso solo se mira dentro si se pide en_detalle', function (): void {
    SystemEvent::olvidarSiHayTabla();
    SystemEvent::create([
        'type' => 'auth.failed', 'level' => SystemEvent::AVISO, 'message' => 'mensaje normal',
        'context' => ['detalle' => 'palabra-escondida'],
    ]);

    $this->actingAs($this->super)
        ->get(route('platform.monitoring', ['pestana' => 'registro', 'busca' => 'palabra-escondida']))
        ->assertOk()
        ->assertDontSee('mensaje normal');

    $this->actingAs($this->super)
        ->get(route('platform.monitoring', ['pestana' => 'registro', 'busca' => 'palabra-escondida', 'en_detalle' => '1']))
        ->assertOk()
        ->assertSee('mensaje normal');
});

it('sin la tabla del registro, la pestaña se pinta vacía y no revienta', function (): void {
    Schema::drop('system_events');
    DbTable::olvidar();

    $this->actingAs($this->super)
        ->get(route('platform.monitoring', ['pestana' => 'registro']))
        ->assertOk();
});

// ------------------------------------------------------------------------- Los errores

it('un error se encuentra por CUALQUIER empresa a la que afectó, no solo la última', function (): void {
    // La «última empresa» que guarda el grupo es la segunda; se busca por la PRIMERA, a la que
    // también afectó, y tiene que aparecer igual: es justo lo que resuelve el desglose.
    $e = new RuntimeException('fallo compartido entre empresas');
    ErrorEvent::anotar($e, ['a.php:1'], null, $this->primera->id, null);
    ErrorEvent::anotar($e, ['a.php:1'], null, $this->segunda->id, null);

    $this->actingAs($this->super)
        ->get(route('platform.monitoring', ['pestana' => 'errores', 'busca' => 'Primera']))
        ->assertOk()
        ->assertSee('fallo compartido entre empresas');
});

it('filtrar errores por empresa trae los que la afectaron, aunque no sea la última', function (): void {
    $compartido = new RuntimeException('otro fallo compartido');
    ErrorEvent::anotar($compartido, ['b.php:1'], null, $this->primera->id, null);
    ErrorEvent::anotar($compartido, ['b.php:1'], null, $this->segunda->id, null);

    // Uno que solo le pasó a la segunda, para comprobar que el filtro también DESCARTA.
    ErrorEvent::anotar(new RuntimeException('solo de la segunda'), ['g.php:1'], null, $this->segunda->id, null);

    $this->actingAs($this->super)
        ->get(route('platform.monitoring', ['pestana' => 'errores', 'empresa' => $this->primera->id]))
        ->assertOk()
        ->assertSee('otro fallo compartido')
        ->assertDontSee('solo de la segunda');
});

it('filtra los errores por estado', function (): void {
    ErrorEvent::anotar(new RuntimeException('error resuelto ya'), ['c.php:1'], null, null, null);
    ErrorEvent::query()->update(['status' => ErrorEvent::RESUELTO]);

    ErrorEvent::anotar(new RuntimeException('error todavía activo'), ['d.php:1'], null, null, null);

    // Por omisión (activos) no se ve el resuelto.
    $this->actingAs($this->super)->get(route('platform.monitoring', ['pestana' => 'errores']))
        ->assertOk()
        ->assertSee('error todavía activo')
        ->assertDontSee('error resuelto ya');

    // Pidiendo el estado «resolved», sí.
    $this->actingAs($this->super)
        ->get(route('platform.monitoring', ['pestana' => 'errores', 'estado' => 'resolved']))
        ->assertOk()
        ->assertSee('error resuelto ya');
});

it('ordena los errores por más frecuentes', function (): void {
    ErrorEvent::anotar(new RuntimeException('pasó pocas veces'), ['e.php:1'], null, null, null);

    $muchasVeces = new RuntimeException('pasó muchas veces');
    for ($i = 0; $i < 5; $i++) {
        ErrorEvent::anotar($muchasVeces, ['f.php:1'], null, null, null);
    }

    $r = $this->actingAs($this->super)
        ->get(route('platform.monitoring', ['pestana' => 'errores', 'orden' => 'frecuentes']))
        ->assertOk();

    $posicionMuchas = strpos($r->getContent(), 'pasó muchas veces');
    $posicionPocas = strpos($r->getContent(), 'pasó pocas veces');

    expect($posicionMuchas)->not->toBeFalse()->and($posicionPocas)->not->toBeFalse()
        ->and($posicionMuchas)->toBeLessThan($posicionPocas);
});

it('sin la tabla de errores, la pestaña se pinta vacía y no revienta', function (): void {
    Schema::drop('error_events');
    DbTable::olvidar();

    $this->actingAs($this->super)
        ->get(route('platform.monitoring', ['pestana' => 'errores']))
        ->assertOk();
});

// ------------------------------------------------------------------------- La actividad

it('encuentra en la actividad por el número de lo que se tocó', function (): void {
    Customer::create(['company_id' => $this->primera->id, 'name' => 'Cliente de prueba']);
    $registro = Audit::query()->latest('id')->firstOrFail();

    $this->actingAs($this->super)
        ->get(route('platform.monitoring', ['pestana' => 'actividad', 'busca' => (string) $registro->auditable_id]))
        ->assertOk()
        ->assertSee('Cliente');
});

it('filtra la actividad por el tipo de acción', function (): void {
    $cliente = Customer::create(['company_id' => $this->primera->id, 'name' => 'Beatriz']);
    $cliente->update(['name' => 'Beatriz Actualizada']);

    $this->actingAs($this->super)
        ->get(route('platform.monitoring', ['pestana' => 'actividad', 'accion' => 'updated']))
        ->assertOk()
        ->assertSee('modificó')
        ->assertDontSee('creó');
});
