<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Models\ErrorEvent;
use App\Modules\Core\Models\SystemEvent;
use App\Modules\Core\Monitoring\Errors\ErrorGroupBackfill;
use App\Modules\Core\Services\CompanyEraser;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Support\DbTable;
use App\Modules\Core\Tenancy\CurrentCompany;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Lo que rodea al agrupado por empresa: el hueco de la migración, los datos que ya existían, borrar una
 * empresa y podar lo viejo.
 *
 * Tres de estas cosas son las que dan miedo en producción, y por eso se prueban a propósito:
 *
 *  · El código sale ANTES que la migración (aquí se aplica a mano). Ese hueco no puede dejar de guardar
 *    errores, y menos aún tumbar nada.
 *  · Borrar una empresa que compartía un error con otras NO puede llevarse los errores de las otras.
 *  · El backfill corre una sola vez, a mano, en producción: tiene que ser idempotente.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(CurrentCompany::class)->forget();

    $this->abc = app(CompanyService::class)->create(new CreateCompanyData(name: 'Empresa ABC'));
    $this->xyz = app(CompanyService::class)->create(new CreateCompanyData(name: 'Empresa XYZ'));

    $this->usuario = fn (object $empresa, string $nombre): User => User::create([
        'company_id' => $empresa->id, 'name' => $nombre,
        'email' => strtolower($nombre).'@monitor.test', 'password' => 'secret-password',
    ]);

    $this->lanzar = fn (string $mensaje): Throwable => new RuntimeException($mensaje);

    $this->fallo = function (?int $empresa = null, ?int $usuario = null, string $mensaje = 'Connection timeout en Evolution API'): void {
        ErrorEvent::anotar(($this->lanzar)($mensaje), ['F.php:1'], 'http://localhost/x', $empresa, $usuario);
    };

    // Un grupo de los que había ANTES del desglose: huella sha1, sin filas hijas, versión 1.
    $this->grupoAntiguo = fn (array $datos = []): int => (int) DB::table('error_events')->insertGetId($datos + [
        'fingerprint' => sha1(uniqid('', true)),
        'class' => RuntimeException::class,
        'message' => 'un error de antes',
        'origin' => 'Foo.php:10',
        'hits' => 5,
        'first_seen_at' => now()->subDays(3),
        'last_seen_at' => now()->subDay(),
        'created_at' => now()->subDays(3),
        'updated_at' => now()->subDay(),
    ]);
});

// ------------------------------------------------------------------ El hueco de la migración

it('sin las tablas de desglose sigue guardando errores como hasta ahora', function (): void {
    // El código sale antes que la migración. Hasta el día en que alguien migra, el registro tiene que
    // seguir funcionando: no dejar de guardar errores y, desde luego, no dar uno nuevo.
    Schema::drop('error_event_users');
    Schema::drop('error_event_companies');
    DbTable::olvidar();

    $e = new RuntimeException('falló en el modo antiguo');

    ErrorEvent::anotar($e, ['F.php:1'], 'http://localhost/x?secret=abc123', $this->abc->id, null);
    ErrorEvent::anotar($e, ['F.php:1'], 'http://localhost/x?secret=abc123', $this->xyz->id, null);

    $grupo = ErrorEvent::query()->sole();

    expect($grupo->hits)->toBe(2)
        // La huella de siempre: 40 hexadecimales, sin prefijo.
        ->and($grupo->fingerprint)->toMatch('/^[0-9a-f]{40}$/')
        // «La última empresa vista», como antes.
        ->and($grupo->company_id)->toBe($this->xyz->id)
        // Lo único que mejora: la dirección ya no lleva el secreto.
        ->and((string) $grupo->url)->not->toContain('abc123');
});

it('con solo UNA de las dos tablas tampoco se usa el modo nuevo', function (): void {
    // Una migración a medias no puede hacer que el registro escriba en una tabla que no existe.
    Schema::drop('error_event_users');
    DbTable::olvidar();

    ($this->fallo)($this->abc->id);
    ($this->fallo)($this->abc->id);

    expect(ErrorEvent::query()->sole()->hits)->toBe(2)
        ->and(DB::table('error_event_companies')->count())->toBe(0);
});

it('registrar un suceso sigue funcionando sin la columna nueva del servicio', function (): void {
    // Es lo que impide que nadie pueda iniciar sesión entre el despliegue y la migración.
    Schema::table('system_events', fn ($tabla) => $tabla->dropIndex(['service', 'created_at']));
    Schema::table('system_events', fn ($tabla) => $tabla->dropColumn('service'));
    DbTable::olvidar();

    SystemEvent::registrar('auth.login', 'Inició sesión');

    expect(DB::table('system_events')->where('type', 'auth.login')->count())->toBe(1);
});

// ------------------------------------------------------------------ Los datos que ya existían

it('el backfill lleva la empresa y el usuario de un grupo antiguo a las tablas de desglose', function (): void {
    $ana = ($this->usuario)($this->abc, 'Ana');

    $grupo = ($this->grupoAntiguo)(['company_id' => $this->abc->id, 'user_id' => $ana->id, 'hits' => 5]);

    $resultado = ErrorGroupBackfill::run();

    $desglose = DB::table('error_event_companies')->where('error_event_id', $grupo)->first();
    $usuarios = DB::table('error_event_users')->where('error_event_id', $grupo)->first();
    $fila = DB::table('error_events')->find($grupo);

    expect($resultado['companies'])->toBe(1)
        ->and($resultado['users'])->toBe(1)
        ->and($desglose->company_id)->toBe($this->abc->id)
        // Se le atribuyen todas sus veces: es una aproximación, y la pantalla lo marca como histórico.
        ->and($desglose->hits)->toBe(5)
        ->and($usuarios->user_id)->toBe($ana->id)
        ->and($usuarios->company_id)->toBe($this->abc->id)
        ->and($fila->companies_count)->toBe(1)
        ->and($fila->users_count)->toBe(1)
        // No toca lo que ya existía.
        ->and($fila->hits)->toBe(5)
        ->and($fila->fingerprint_version)->toBe(1)
        ->and($fila->message)->toBe('un error de antes');
});

it('el backfill puede correr dos veces sin duplicar ni contar de más', function (): void {
    $grupo = ($this->grupoAntiguo)(['company_id' => $this->abc->id, 'hits' => 5]);

    ErrorGroupBackfill::run();
    $segunda = ErrorGroupBackfill::run();

    expect($segunda)->toBe(['companies' => 0, 'users' => 0, 'services' => 0])
        ->and(DB::table('error_event_companies')->where('error_event_id', $grupo)->count())->toBe(1)
        ->and(DB::table('error_events')->find($grupo)->companies_count)->toBe(1);
});

it('el backfill rellena el servicio con lo que hay guardado', function (): void {
    $sql = ($this->grupoAntiguo)(['class' => QueryException::class]);
    $polar = ($this->grupoAntiguo)(['message' => 'cURL error 28: timed out for https://api.polar.sh/v1/x']);
    $otro = ($this->grupoAntiguo)(['message' => 'algo sin dirección']);

    ErrorGroupBackfill::run();

    expect(DB::table('error_events')->find($sql)->service)->toBe('database')
        ->and(DB::table('error_events')->find($polar)->service)->toBe('polar')
        ->and(DB::table('error_events')->find($otro)->service)->toBe('app');
});

it('un grupo sin empresa no genera desglose', function (): void {
    ($this->grupoAntiguo)(['company_id' => null, 'user_id' => null]);

    expect(ErrorGroupBackfill::run()['companies'])->toBe(0)
        ->and(DB::table('error_event_companies')->count())->toBe(0);
});

it('el backfill no hace nada si las tablas nuevas todavía no existen', function (): void {
    Schema::drop('error_event_users');
    Schema::drop('error_event_companies');
    DbTable::olvidar();

    expect(ErrorGroupBackfill::run())->toBe(['companies' => 0, 'users' => 0, 'services' => 0]);
});

// ------------------------------------------------------------------ Borrar una empresa

it('borrar una empresa NO se lleva los errores de las demás', function (): void {
    // El peligro que traían los grupos compartidos: el borrado era `DELETE ... WHERE company_id = X`,
    // que ahora se llevaría un error que ABC compartía con XYZ.
    ($this->fallo)($this->xyz->id);
    ($this->fallo)($this->xyz->id);
    ($this->fallo)($this->abc->id);
    ($this->fallo)($this->abc->id);
    ($this->fallo)($this->abc->id);

    app(CompanyEraser::class)->erase($this->abc);

    $grupo = ErrorEvent::query()->sole();

    expect($grupo->hits)->toBe(2)
        ->and($grupo->companies_count)->toBe(1)
        ->and($grupo->companies()->pluck('company_id')->all())->toBe([$this->xyz->id])
        // El «último conocido» era ABC, que ya no existe.
        ->and($grupo->company_id)->toBeNull();
});

it('borrar una empresa se lleva los errores que eran solo suyos', function (): void {
    ($this->fallo)($this->abc->id, null, 'Un error que solo sufre ABC');
    ($this->fallo)($this->xyz->id, null, 'Otro error distinto que solo sufre XYZ');

    app(CompanyEraser::class)->erase($this->abc);

    expect(ErrorEvent::query()->pluck('message')->all())->toBe(['Otro error distinto que solo sufre XYZ']);
});

it('borrar una empresa se lleva sus usuarios del desglose y corrige el total', function (): void {
    $ana = ($this->usuario)($this->abc, 'Ana');
    $luis = ($this->usuario)($this->xyz, 'Luis');

    ($this->fallo)($this->abc->id, $ana->id);
    ($this->fallo)($this->xyz->id, $luis->id);

    app(CompanyEraser::class)->erase($this->abc);

    $grupo = ErrorEvent::query()->sole();

    expect($grupo->users_count)->toBe(1)
        ->and(DB::table('error_event_users')->pluck('user_id')->all())->toBe([$luis->id]);
});

it('borrar una empresa se lleva sus errores antiguos sin desglose, y solo los suyos', function (): void {
    $deAbc = ($this->grupoAntiguo)(['company_id' => $this->abc->id]);
    $deXyz = ($this->grupoAntiguo)(['company_id' => $this->xyz->id]);

    app(CompanyEraser::class)->erase($this->abc);

    expect(DB::table('error_events')->where('id', $deAbc)->exists())->toBeFalse()
        ->and(DB::table('error_events')->where('id', $deXyz)->exists())->toBeTrue();
});

it('borrar una empresa sin las tablas de desglose se lleva sus errores como siempre', function (): void {
    Schema::drop('error_event_users');
    Schema::drop('error_event_companies');
    DbTable::olvidar();

    $deAbc = ($this->grupoAntiguo)(['company_id' => $this->abc->id]);
    $deXyz = ($this->grupoAntiguo)(['company_id' => $this->xyz->id]);

    app(CompanyEraser::class)->erase($this->abc);

    expect(DB::table('error_events')->where('id', $deAbc)->exists())->toBeFalse()
        ->and(DB::table('error_events')->where('id', $deXyz)->exists())->toBeTrue();
});

// ------------------------------------------------------------------ Poda

it('la poda borra los errores que no se repiten desde hace meses, con su desglose', function (): void {
    ($this->fallo)($this->abc->id, null, 'Un error viejo que ya no se repite');
    ($this->fallo)($this->abc->id, null, 'Un error que sigue pasando hoy');

    $viejo = ErrorEvent::query()->where('message', 'like', '%viejo%')->sole();
    $viejo->forceFill(['last_seen_at' => now()->subDays(120)])->save();

    $this->artisan('registros:purgar')->assertSuccessful();

    expect(ErrorEvent::query()->pluck('message')->all())->toBe(['Un error que sigue pasando hoy'])
        // Sus filas de desglose se van con él.
        ->and(DB::table('error_event_companies')->where('error_event_id', $viejo->id)->count())->toBe(0);
});

it('un error que sigue ocurriendo no es viejo por haber nacido hace meses', function (): void {
    ($this->fallo)($this->abc->id);

    // Nació hace 200 días, pero ocurrió ayer: la poda mira la ÚLTIMA vez.
    ErrorEvent::query()->sole()->forceFill(['first_seen_at' => now()->subDays(200), 'last_seen_at' => now()->subDay()])->save();

    $this->artisan('registros:purgar')->assertSuccessful();

    expect(ErrorEvent::query()->count())->toBe(1);
});

it('con cero días la poda de errores está apagada', function (): void {
    ($this->fallo)($this->abc->id);
    ErrorEvent::query()->sole()->forceFill(['last_seen_at' => now()->subDays(500)])->save();

    $this->artisan('registros:purgar', ['--errores' => 0])->assertSuccessful();

    expect(ErrorEvent::query()->count())->toBe(1);
});

it('el simulacro cuenta los errores viejos sin borrarlos', function (): void {
    ($this->fallo)($this->abc->id);
    ErrorEvent::query()->sole()->forceFill(['last_seen_at' => now()->subDays(120)])->save();

    $this->artisan('registros:purgar', ['--simular' => true])->assertSuccessful();

    expect(ErrorEvent::query()->count())->toBe(1);
});
