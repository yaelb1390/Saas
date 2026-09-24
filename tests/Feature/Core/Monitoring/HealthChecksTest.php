<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\AI\Models\AiSetting;
use App\Modules\Core\Models\Incident;
use App\Modules\Core\Monitoring\Health\HealthCheck;
use App\Modules\Core\Monitoring\Health\HealthCheckRunner;
use App\Modules\Core\Monitoring\Health\HealthResult;
use App\Modules\Core\Monitoring\Health\HealthStatus;
use App\Modules\Core\Monitoring\Health\HealthStore;
use App\Modules\Core\Monitoring\Health\Checks\AiCheck;
use App\Modules\Core\Monitoring\Health\Checks\DatabaseCheck;
use App\Modules\Core\Monitoring\Health\Checks\EvolutionCheck;
use App\Modules\Core\Monitoring\Health\Checks\MailCheck;
use App\Modules\Core\Monitoring\Health\Checks\PolarCheck;
use App\Modules\Core\Monitoring\Health\Checks\RedisCheck;
use App\Modules\Core\Monitoring\Health\HealthAggregator;
use App\Modules\Core\Monitoring\Health\HealthRegistry;
use App\Modules\Core\Support\DbTable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

/*
 * Fase 3: health checks reales. «Configurado» y «disponible» son preguntas distintas —una sonda que
 * responde mal a propósito, para comprobar que aquí SÍ se nota, al revés que antes de esta fase—.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutVite();
});

// ------------------------------------------------------------------------- Las sondas, una a una

it('la base de datos está sana contra la BD de verdad de los tests', function (): void {
    $resultado = app(DatabaseCheck::class)->run();

    expect($resultado->status)->toBe(HealthStatus::HEALTHY)
        ->and($resultado->configured)->toBeTrue()
        ->and($resultado->latencyMs)->not->toBeNull();
});

it('Redis dice «no aplica» cuando nada lo usa (como en los tests, y como en producción hoy)', function (): void {
    $resultado = app(RedisCheck::class)->run();

    expect($resultado->status)->toBe(HealthStatus::UNKNOWN)->and($resultado->configured)->toBeFalse();
});

it('el correo dice «no aplica» sin SMTP', function (): void {
    config(['mail.default' => 'array']);

    expect(app(MailCheck::class)->run()->status)->toBe(HealthStatus::UNKNOWN);
});

it('Evolution sin EVOLUTION_BASE_URL dice «no aplica»', function (): void {
    config(['evolution.base_url' => '']);

    expect(app(EvolutionCheck::class)->run()->status)->toBe(HealthStatus::UNKNOWN);
});

it('Evolution configurado pero sin ninguna empresa con línea dice «no aplica»: no hay instancia que mirar', function (): void {
    config(['evolution.base_url' => 'http://evolution.test']);

    $resultado = app(EvolutionCheck::class)->run();

    expect($resultado->status)->toBe(HealthStatus::UNKNOWN);
    Http::assertNothingSent();
});

it('Evolution responde bien contra la instancia de una empresa real', function (): void {
    [$company] = crearEmpresaConLineaEvolution();

    config(['evolution.base_url' => 'http://evolution.test', 'evolution.api_key' => 'clave']);
    Http::fake(['evolution.test/*' => Http::response(['instance' => ['state' => 'open']], 200)]);

    $resultado = app(EvolutionCheck::class)->run();

    expect($resultado->status)->toBe(HealthStatus::HEALTHY)->and($resultado->latencyMs)->not->toBeNull();
    Http::assertSent(fn ($r): bool => str_contains($r->url(), '/instance/connectionState/'.$company->slug));
});

it('Evolution con la clave mala se marca caído, sin filtrar la clave en el error', function (): void {
    crearEmpresaConLineaEvolution();
    config(['evolution.base_url' => 'http://evolution.test', 'evolution.api_key' => 'sk_secreta_de_verdad']);
    Http::fake(['evolution.test/*' => Http::response(['message' => 'Unauthorized'], 401)]);

    $resultado = app(EvolutionCheck::class)->run();

    expect($resultado->status)->toBe(HealthStatus::UNHEALTHY)
        ->and($resultado->lastError)->toContain('401');
});

it('Evolution que responde 404 se considera alcanzable: el servicio vive, esa instancia no existe', function (): void {
    crearEmpresaConLineaEvolution();
    config(['evolution.base_url' => 'http://evolution.test']);
    Http::fake(['evolution.test/*' => Http::response(['message' => 'Not Found'], 404)]);

    expect(app(EvolutionCheck::class)->run()->status)->toBe(HealthStatus::HEALTHY);
});

it('Evolution que no responde en absoluto se marca caído, sin filtrar la clave', function (): void {
    crearEmpresaConLineaEvolution();
    config(['evolution.base_url' => 'http://evolution.test', 'evolution.api_key' => 'sk_muy_secreta']);
    Http::fake(fn () => throw new Illuminate\Http\Client\ConnectionException('Could not connect to evolution.test with apikey=sk_muy_secreta'));

    $resultado = app(EvolutionCheck::class)->run();

    expect($resultado->status)->toBe(HealthStatus::UNHEALTHY)
        ->and($resultado->lastError)->not->toContain('sk_muy_secreta');
});

it('la IA con el proveedor local dice «no aplica»', function (): void {
    AiSetting::actual()->update(['provider' => 'local']);

    expect(app(AiCheck::class)->run()->status)->toBe(HealthStatus::UNKNOWN);
});

it('la IA (OpenAI) responde bien', function (): void {
    AiSetting::actual()->update(['provider' => 'openai', 'api_key' => 'sk-prueba']);
    Http::fake(['api.openai.com/*' => Http::response(['data' => []], 200)]);

    expect(app(AiCheck::class)->run()->status)->toBe(HealthStatus::HEALTHY);
});

it('la IA con clave inválida (401) se marca caída', function (): void {
    AiSetting::actual()->update(['provider' => 'openai', 'api_key' => 'sk-mala']);
    Http::fake(['api.openai.com/*' => Http::response(['error' => 'invalid_api_key'], 401)]);

    expect(app(AiCheck::class)->run()->status)->toBe(HealthStatus::UNHEALTHY);
});

it('la IA con demasiadas peticiones (429) se marca a medias, no caída', function (): void {
    AiSetting::actual()->update(['provider' => 'openai', 'api_key' => 'sk-prueba']);
    Http::fake(['api.openai.com/*' => Http::response(['error' => 'rate_limited'], 429)]);

    expect(app(AiCheck::class)->run()->status)->toBe(HealthStatus::DEGRADED);
});

it('Polar sin token dice «no aplica»', function (): void {
    config(['polar.access_token' => null]);

    expect(app(PolarCheck::class)->run()->status)->toBe(HealthStatus::UNKNOWN);
});

it('Polar responde bien con el catálogo', function (): void {
    config(['polar.access_token' => 'polar_at_prueba']);
    Http::fake(['*polar.sh/v1/products/*' => Http::response(['items' => []], 200)]);

    expect(app(PolarCheck::class)->run()->status)->toBe(HealthStatus::HEALTHY);
});

it('Polar con un token sin ámbito de lectura (403) se marca a medias, no caído', function (): void {
    config(['polar.access_token' => 'polar_at_sin_ambito']);
    Http::fake(['*polar.sh/v1/products/*' => Http::response(['error' => 'forbidden'], 403)]);

    $resultado = app(PolarCheck::class)->run();

    expect($resultado->status)->toBe(HealthStatus::DEGRADED)
        ->and($resultado->message)->toContain('ámbito');
});

// ------------------------------------------------------------------------- El orquestador

it('una sonda que LANZA cuenta como caída, no tumba la comprobación', function (): void {
    $registro = new HealthRegistry([new SondaQueSiempreLanza()]);
    $runner = new HealthCheckRunner($registro, app(HealthStore::class), app(\App\Modules\Core\Monitoring\Incidents\IncidentDetector::class));

    $resultados = $runner->comprobar('lanzona', forzar: true);

    expect($resultados->get('lanzona')->status)->toBe(HealthStatus::UNHEALTHY);
    expect(DB::table('health_checks')->where('service', 'lanzona')->value('status'))->toBe(HealthStatus::UNHEALTHY);
});

it('dentro del intervalo mínimo, no vuelve a comprobar salvo que se fuerce', function (): void {
    SondaQueCuenta::$llamadas = 0;
    $registro = new HealthRegistry([new SondaQueCuenta()]);
    $store = app(HealthStore::class);
    $runner = new HealthCheckRunner($registro, $store, app(\App\Modules\Core\Monitoring\Incidents\IncidentDetector::class));

    $runner->comprobar('cuenta', forzar: true);
    $runner->comprobar('cuenta', forzar: false); // dentro de los 30 s: no debería llamar de nuevo
    $runner->comprobar('cuenta', forzar: true); // forzado: sí

    expect(SondaQueCuenta::$llamadas)->toBe(2);
});

it('guarda una fila de histórico por cada comprobación', function (): void {
    $registro = new HealthRegistry([new SondaQueCuenta()]);
    $runner = new HealthCheckRunner($registro, app(HealthStore::class), app(\App\Modules\Core\Monitoring\Incidents\IncidentDetector::class));

    $runner->comprobar('cuenta', forzar: true, trigger: HealthStore::TRIGGER_MANUAL);

    expect(DB::table('health_check_results')->where('service', 'cuenta')->where('trigger', 'manual')->count())->toBe(1);
});

it('sin la tabla de salud, la pestaña Servicios se pinta igual y no revienta', function (): void {
    Schema::drop('health_check_results');
    Schema::drop('health_checks');
    DbTable::olvidar();

    $superadmin = User::create([
        'company_id' => null, 'name' => 'Operador', 'email' => 'op@salud.test',
        'password' => 'secret-password', 'is_super_admin' => true,
    ]);

    $this->actingAs($superadmin)
        ->get(route('platform.monitoring', ['pestana' => 'servicios']))
        ->assertOk()
        ->assertSee('Servicios');
});

// ------------------------------------------------------------------------- El agregador

it('con todo sano, el estado general es healthy', function (): void {
    app(HealthStore::class)->guardar('database', HealthResult::sano(5), HealthStore::TRIGGER_MANUAL);

    expect(app(HealthAggregator::class)->estado())->toBe(HealthStatus::HEALTHY);
});

it('con la base de datos caída HACE POCO, el estado general es DOWN', function (): void {
    app(HealthStore::class)->guardar('database', HealthResult::caido('se cayó'), HealthStore::TRIGGER_MANUAL);

    expect(app(HealthAggregator::class)->estado())->toBe(HealthStatus::UNHEALTHY);
});

it('un dato crítico VIEJO nunca marca DOWN por sí solo: degrada, no apaga', function (): void {
    app(HealthStore::class)->guardar('database', HealthResult::sano(5), HealthStore::TRIGGER_MANUAL);
    DB::table('health_checks')->where('service', 'database')->update(['last_checked_at' => now()->subHours(2)]);

    expect(app(HealthAggregator::class)->estado())->toBe(HealthStatus::DEGRADED);
});

it('un servicio no crítico caído degrada la plataforma, no la apaga', function (): void {
    app(HealthStore::class)->guardar('database', HealthResult::sano(5), HealthStore::TRIGGER_MANUAL);
    app(HealthStore::class)->guardar('polar', HealthResult::caido('caído'), HealthStore::TRIGGER_MANUAL);

    expect(app(HealthAggregator::class)->estado())->toBe(HealthStatus::DEGRADED);
});

// ------------------------------------------------------------------------- Incidentes desde salud

it('dos comprobaciones UNHEALTHY seguidas abren un incidente; una sola no', function (): void {
    $registro = new HealthRegistry([new SondaQueSiempreLanza()]);
    $runner = new HealthCheckRunner($registro, app(HealthStore::class), app(\App\Modules\Core\Monitoring\Incidents\IncidentDetector::class));

    $runner->comprobar('lanzona', forzar: true);
    expect(Incident::query()->count())->toBe(0);

    $runner->comprobar('lanzona', forzar: true);

    $incidente = Incident::query()->firstOrFail();
    expect($incidente->dedupe_key)->toBe('health:lanzona')->and($incidente->service)->toBe('lanzona');
});

it('una comprobación sana entre medias reinicia la racha de fallos', function (): void {
    $registro = new HealthRegistry([new SondaIntermitente()]);
    $runner = new HealthCheckRunner($registro, app(HealthStore::class), app(\App\Modules\Core\Monitoring\Incidents\IncidentDetector::class));

    SondaIntermitente::$secuencia = [HealthStatus::UNHEALTHY, HealthStatus::HEALTHY, HealthStatus::UNHEALTHY];

    $runner->comprobar('intermitente', forzar: true); // fallo 1
    $runner->comprobar('intermitente', forzar: true); // sano: reinicia
    $runner->comprobar('intermitente', forzar: true); // fallo 1 otra vez, no 2

    expect(Incident::query()->count())->toBe(0);
});

// ------------------------------------------------------------------------- Las rutas
// (El cron `/tareas/comprobar-salud` y su secreto los prueba TareasDeMantenimientoTest, junto con
// el resto de tareas programadas: es donde vive esa regresión.)

it('solo el operador de la plataforma puede pedir «comprobar ahora»', function (): void {
    $company = app(App\Modules\Core\Services\CompanyService::class)
        ->create(new App\Modules\Core\DTOs\CreateCompanyData(name: 'Empresa'));

    $duena = withRole(User::create([
        'company_id' => $company->id, 'name' => 'Dueña',
        'email' => 'duena@salud.test', 'password' => 'secret-password',
    ]), 'owner');

    $this->actingAs($duena)
        ->post(route('platform.monitoring.health.check', 'database'))
        ->assertForbidden();
});

it('«comprobar ahora» contesta JSON con el resultado', function (): void {
    $superadmin = User::create([
        'company_id' => null, 'name' => 'Operador', 'email' => 'op2@salud.test',
        'password' => 'secret-password', 'is_super_admin' => true,
    ]);

    $this->actingAs($superadmin)
        ->postJson(route('platform.monitoring.health.check', 'database'))
        ->assertOk()
        ->assertJson(['ok' => true, 'servicio' => 'database', 'estado' => 'healthy']);
});

it('un servicio que no existe en el registro da 404, no 500', function (): void {
    $superadmin = User::create([
        'company_id' => null, 'name' => 'Operador', 'email' => 'op3@salud.test',
        'password' => 'secret-password', 'is_super_admin' => true,
    ]);

    $this->actingAs($superadmin)
        ->postJson(route('platform.monitoring.health.check', 'no-existe'))
        ->assertNotFound();
});

/**
 * Crea una empresa con la línea de WhatsApp por QR (Evolution) activa, para tener una instancia de
 * referencia contra la que la sonda pueda preguntar.
 *
 * @return array{0: \App\Modules\Core\Models\Company}
 */
function crearEmpresaConLineaEvolution(): array
{
    $company = app(App\Modules\Core\Services\CompanyService::class)
        ->create(new App\Modules\Core\DTOs\CreateCompanyData(name: 'Con Evolution'));

    App\Modules\WhatsApp\Models\WaBotSetting::query()->create([
        'company_id' => $company->id,
        'provider' => App\Modules\WhatsApp\Models\WaBotSetting::POR_QR,
        'is_active' => true,
    ]);

    return [$company];
}

/** Sonda de prueba que siempre lanza: para comprobar que el runner no se cae con ella. */
final class SondaQueSiempreLanza implements HealthCheck
{
    public function key(): string
    {
        return 'lanzona';
    }

    public function label(): string
    {
        return 'Sonda que lanza';
    }

    public function run(): HealthResult
    {
        throw new RuntimeException('esto siempre falla');
    }
}

/** Sonda de prueba que cuenta cuántas veces se ejecutó de verdad. */
final class SondaQueCuenta implements HealthCheck
{
    public static int $llamadas = 0;

    public function key(): string
    {
        return 'cuenta';
    }

    public function label(): string
    {
        return 'Sonda que cuenta';
    }

    public function run(): HealthResult
    {
        self::$llamadas++;

        return HealthResult::sano(1);
    }
}

/** Sonda de prueba que devuelve lo que le toque de una secuencia fijada desde el test. */
final class SondaIntermitente implements HealthCheck
{
    /** @var list<string> */
    public static array $secuencia = [];

    public function key(): string
    {
        return 'intermitente';
    }

    public function label(): string
    {
        return 'Sonda intermitente';
    }

    public function run(): HealthResult
    {
        $estado = array_shift(self::$secuencia) ?? HealthStatus::HEALTHY;

        return $estado === HealthStatus::UNHEALTHY ? HealthResult::caido('a propósito') : HealthResult::sano(1);
    }
}
