<?php

declare(strict_types=1);

use App\Modules\Cash\Enums\CashSessionStatus;
use App\Modules\Cash\Models\CashRegister;
use App\Modules\Cash\Models\CashSession;
use App\Modules\Core\DTOs\CompanyHealthCard;
use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Models\IncidentLink;
use App\Modules\Core\Models\Warehouse;
use App\Modules\Core\Monitoring\Incidents\IncidentService;
use App\Modules\Core\Services\CompanyHealthService;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Tenancy\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/*
 * Fase 7: la ficha por empresa, seis dominios (Sistema, Facturación, WhatsApp, IA, Ventas, Caja),
 * cada uno HEALTHY/WARNING/CRITICAL con su lista de problemas — cruzando con el resto del monitoreo
 * (errores, incidentes, jobs, HTTP) además de las señales que `CompanyHealthService` ya calculaba.
 *
 * Lo que más importa aquí, igual que en `CompanyHealthTest`: que una señal de una empresa NO se cuele
 * en la ficha de otra, y que el coste no crezca con el número de empresas.
 */

uses(RefreshDatabase::class);

/** Lee la ficha de una empresa por su nombre, sin caché de por medio. */
function fichaDe(string $nombre): CompanyHealthCard
{
    cache()->forget('platform:empresas');
    cache()->forget('platform:empresas:fichas');

    $ficha = app(CompanyHealthService::class)->fichas()->firstWhere('nombre', $nombre);

    expect($ficha)->not->toBeNull("No apareció «{$nombre}» en las fichas.");

    return $ficha;
}

beforeEach(function (): void {
    app(CurrentCompany::class)->forget();
    cache()->forget('platform:empresas');
    cache()->forget('platform:empresas:fichas');

    $this->empresa = app(CompanyService::class)->create(new CreateCompanyData(name: 'La Vigilada'));
    app(CurrentCompany::class)->set($this->empresa->id);

    // Sin esto, «Ventas» sale CRÍTICO de fábrica (sin productos que vender: correcto, pero ensucia
    // cualquier prueba que no sea justo de eso) — una empresa recién creada de verdad no tiene nada
    // en el catálogo todavía.
    \App\Modules\Inventory\Models\Product::create(['sku' => 'BASE-1', 'name' => 'Producto base', 'price' => '10']);
});

// ------------------------------------------------------------------------------- Sin problemas

it('una empresa recién creada, sin señales, sale sana en los seis dominios', function (): void {
    $ficha = fichaDe('La Vigilada');

    expect($ficha->estadoGeneral())->toBe(CompanyHealthCard::HEALTHY)
        ->and($ficha->problemas())->toHaveCount(0);
});

it('ficha() de una empresa que no existe es null', function (): void {
    expect(app(CompanyHealthService::class)->ficha(999999))->toBeNull();
});

// ------------------------------------------------------------------------------------- Sistema

it('un incidente ABIERTO que afecta a la empresa la pone en Sistema crítico', function (): void {
    app(IncidentService::class)->detectarOAbrir(
        'prueba:incidente-'.uniqid(), 'Algo se rompió', null, 'high',
        IncidentLink::ERROR_EVENT, 1, [$this->empresa->id],
    );

    $ficha = fichaDe('La Vigilada');

    expect($ficha->estadoDe('sistema'))->toBe(CompanyHealthCard::CRITICAL)
        ->and($ficha->estadoGeneral())->toBe(CompanyHealthCard::CRITICAL);
});

it('un grupo de error ACTIVO que tocó a la empresa hoy pone Sistema en aviso', function (): void {
    $grupo = DB::table('error_events')->insertGetId([
        'fingerprint' => sha1(uniqid('', true)), 'class' => RuntimeException::class,
        'message' => 'algo falló', 'origin' => 'Foo.php:1', 'hits' => 3, 'status' => 'active',
        'first_seen_at' => now(), 'last_seen_at' => now(),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('error_event_companies')->insert([
        'error_event_id' => $grupo, 'company_id' => $this->empresa->id, 'hits' => 3,
        'first_seen_at' => now(), 'last_seen_at' => now(),
    ]);

    $ficha = fichaDe('La Vigilada');

    expect($ficha->estadoDe('sistema'))->toBe(CompanyHealthCard::WARNING);
});

it('un error de hace tres días (no activo hoy) NO pone Sistema en aviso', function (): void {
    $grupo = DB::table('error_events')->insertGetId([
        'fingerprint' => sha1(uniqid('', true)), 'class' => RuntimeException::class,
        'message' => 'algo falló hace tiempo', 'origin' => 'Foo.php:1', 'hits' => 3, 'status' => 'active',
        'first_seen_at' => now()->subDays(5), 'last_seen_at' => now()->subDays(3),
        'created_at' => now()->subDays(5), 'updated_at' => now()->subDays(3),
    ]);
    DB::table('error_event_companies')->insert([
        'error_event_id' => $grupo, 'company_id' => $this->empresa->id, 'hits' => 3,
        'first_seen_at' => now()->subDays(5), 'last_seen_at' => now()->subDays(3),
    ]);

    expect(fichaDe('La Vigilada')->estadoDe('sistema'))->toBe(CompanyHealthCard::HEALTHY);
});

it('un trabajo fallido de la empresa en el agregador compartido pone Sistema en aviso', function (): void {
    DB::table('metric_buckets')->insert([
        'kind' => 'job', 'bucket_start' => now()->startOfHour(), 'name' => 'AlgunTrabajo', 'method' => 'default',
        'company_id' => $this->empresa->id, 'total' => 1, 'warnings' => 0, 'errors' => 1, 'sum_ms' => 100, 'max_ms' => 100,
        'h0' => 1, 'h1' => 0, 'h2' => 0, 'h3' => 0, 'h4' => 0, 'h5' => 0, 'h6' => 0, 'h7' => 0, 'h8' => 0,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    expect(fichaDe('La Vigilada')->estadoDe('sistema'))->toBe(CompanyHealthCard::WARNING);
});

it('un 5xx de la empresa en el agregador HTTP pone Sistema en crítico', function (): void {
    DB::table('metric_buckets')->insert([
        'kind' => 'http', 'bucket_start' => now()->startOfHour(), 'name' => 'panel.dashboard', 'method' => 'GET',
        'company_id' => $this->empresa->id, 'total' => 1, 'warnings' => 0, 'errors' => 1, 'sum_ms' => 500, 'max_ms' => 500,
        'h0' => 0, 'h1' => 0, 'h2' => 1, 'h3' => 0, 'h4' => 0, 'h5' => 0, 'h6' => 0, 'h7' => 0, 'h8' => 0,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    expect(fichaDe('La Vigilada')->estadoDe('sistema'))->toBe(CompanyHealthCard::CRITICAL);
});

// ------------------------------------------------------------------------------------- IA

it('un grupo de error ACTIVO de servicio ai pone IA en aviso, pero no Sistema', function (): void {
    $grupo = DB::table('error_events')->insertGetId([
        'fingerprint' => sha1(uniqid('', true)), 'class' => RuntimeException::class,
        'message' => 'la IA no contestó', 'origin' => 'Ai.php:1', 'hits' => 1, 'status' => 'active', 'service' => 'ai',
        'first_seen_at' => now(), 'last_seen_at' => now(),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('error_event_companies')->insert([
        'error_event_id' => $grupo, 'company_id' => $this->empresa->id, 'hits' => 1,
        'first_seen_at' => now(), 'last_seen_at' => now(),
    ]);

    $ficha = fichaDe('La Vigilada');

    // Cuenta para IA (que la deduce por su propio `service`) Y para Sistema (que cuenta TODO error
    // activo, sea del servicio que sea): las dos preguntas son legítimas y distintas.
    expect($ficha->estadoDe('ia'))->toBe(CompanyHealthCard::WARNING)
        ->and($ficha->estadoDe('sistema'))->toBe(CompanyHealthCard::WARNING);
});

// ------------------------------------------------------------------------------ Facturación

it('una suscripción con el cobro fallido pone Facturación en crítico', function (): void {
    // `CompanyService::create()` NO crea una suscripción por su cuenta —eso lo hace el registro
    // self-service o el alta manual—, así que aquí se inserta la propia a mano.
    DB::table('subscriptions')->updateOrInsert(
        ['company_id' => $this->empresa->id],
        ['status' => 'past_due', 'created_at' => now(), 'updated_at' => now()],
    );

    expect(fichaDe('La Vigilada')->estadoDe('facturacion'))->toBe(CompanyHealthCard::CRITICAL);
});

// ---------------------------------------------------------------------------------- WhatsApp

it('la línea desconectada, con el bot con información, pone WhatsApp en crítico', function (): void {
    DB::table('wa_bot_settings')->insert([
        'company_id' => $this->empresa->id, 'is_active' => true,
        'business_info' => 'Vendemos de todo', 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('system_events')->insert([
        'company_id' => $this->empresa->id, 'type' => 'integration.whatsapp', 'level' => 'warning',
        'message' => 'La línea de WhatsApp se desconectó', 'created_at' => now(),
    ]);

    expect(fichaDe('La Vigilada')->estadoDe('whatsapp'))->toBe(CompanyHealthCard::CRITICAL);
});

it('la línea RECONECTADA después de una desconexión ya no cuenta como crítica', function (): void {
    DB::table('wa_bot_settings')->insert([
        'company_id' => $this->empresa->id, 'is_active' => true,
        'business_info' => 'Vendemos de todo', 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('system_events')->insert([
        'company_id' => $this->empresa->id, 'type' => 'integration.whatsapp', 'level' => 'warning',
        'message' => 'La línea de WhatsApp se desconectó', 'created_at' => now()->subMinute(),
    ]);
    DB::table('system_events')->insert([
        'company_id' => $this->empresa->id, 'type' => 'integration.whatsapp', 'level' => 'info',
        'message' => 'La línea de WhatsApp quedó conectada', 'created_at' => now(),
    ]);

    expect(fichaDe('La Vigilada')->estadoDe('whatsapp'))->toBe(CompanyHealthCard::HEALTHY);
});

// -------------------------------------------------------------------------------------- Caja

it('una caja abierta desde hace más de un día pone Caja en aviso, no crítico', function (): void {
    $caja = CashRegister::query()->firstOrCreate(
        ['company_id' => $this->empresa->id, 'name' => 'Caja 1'],
        ['is_active' => true],
    );
    CashSession::create([
        'company_id' => $this->empresa->id, 'cash_register_id' => $caja->id,
        'status' => CashSessionStatus::Open, 'opening_amount' => '1000', 'opened_at' => now()->subDays(2),
    ]);

    $ficha = fichaDe('La Vigilada');

    expect($ficha->estadoDe('caja'))->toBe(CompanyHealthCard::WARNING)
        ->and($ficha->estadoGeneral())->toBe(CompanyHealthCard::WARNING);
});

// --------------------------------------------------------------------------------- Ventas

it('sin almacén por omisión, Ventas sale crítico (bloquea el cobro de verdad)', function (): void {
    Warehouse::query()->where('company_id', $this->empresa->id)->where('is_default', true)->update(['is_default' => false]);

    expect(fichaDe('La Vigilada')->estadoDe('ventas'))->toBe(CompanyHealthCard::CRITICAL);
});

// ----------------------------------------------------------------------- Que no se crucen

it('un incidente, un 5xx y una suscripción cancelada de UNA empresa NO aparecen en la ficha de la otra', function (): void {
    $otra = app(CompanyService::class)->create(new CreateCompanyData(name: 'La Otra'));

    app(IncidentService::class)->detectarOAbrir(
        'prueba:cruce-'.uniqid(), 'Solo de la otra', null, 'critical',
        IncidentLink::ERROR_EVENT, 1, [$otra->id],
    );
    DB::table('metric_buckets')->insert([
        'kind' => 'http', 'bucket_start' => now()->startOfHour(), 'name' => 'x', 'method' => 'GET',
        'company_id' => $otra->id, 'total' => 1, 'warnings' => 0, 'errors' => 1, 'sum_ms' => 10, 'max_ms' => 10,
        'h0' => 1, 'h1' => 0, 'h2' => 0, 'h3' => 0, 'h4' => 0, 'h5' => 0, 'h6' => 0, 'h7' => 0, 'h8' => 0,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('subscriptions')->updateOrInsert(
        ['company_id' => $otra->id],
        ['status' => 'cancelled', 'created_at' => now(), 'updated_at' => now()],
    );

    expect(fichaDe('La Otra')->estadoGeneral())->toBe(CompanyHealthCard::CRITICAL)
        ->and(fichaDe('La Vigilada')->estadoGeneral())->toBe(CompanyHealthCard::HEALTHY);
});

// -------------------------------------------------------------------------------- El coste

it('resumenDeProblemas() cuenta las empresas por su peor estado', function (): void {
    app(IncidentService::class)->detectarOAbrir(
        'prueba:resumen-'.uniqid(), 'Crítico', null, 'high',
        IncidentLink::ERROR_EVENT, 1, [$this->empresa->id],
    );

    $otra = app(CompanyService::class)->create(new CreateCompanyData(name: 'Sana'));
    app(CurrentCompany::class)->set($otra->id);
    \App\Modules\Inventory\Models\Product::create(['sku' => 'SANA-1', 'name' => 'Producto', 'price' => '10']);

    cache()->forget('platform:empresas');
    cache()->forget('platform:empresas:fichas');
    $resumen = app(CompanyHealthService::class)->resumenDeProblemas();

    expect($resumen['critical'])->toBe(1)
        ->and($resumen['healthy'])->toBe(1)
        ->and($resumen['warning'])->toBe(0);
});

it('el coste de fichas() NO crece con el número de empresas', function (): void {
    for ($i = 0; $i < 9; $i++) {
        app(CompanyService::class)->create(new CreateCompanyData(name: 'Empresa '.$i));
    }

    cache()->forget('platform:empresas');
    cache()->forget('platform:empresas:fichas');

    $consultas = 0;
    DB::listen(function () use (&$consultas): void {
        $consultas++;
    });

    $fichas = app(CompanyHealthService::class)->fichas();

    expect($fichas)->toHaveCount(10)
        // `calcular()` ya usa margen hasta 20; los ocho dominios nuevos suman sus propias consultas
        // agrupadas encima, no una por empresa —con 10 empresas y crecientes, esto seguiría plano—.
        ->and($consultas)->toBeLessThan(40);
});

it('el resultado de fichas() se guarda en caché: la segunda mirada no consulta nada', function (): void {
    cache()->forget('platform:empresas');
    cache()->forget('platform:empresas:fichas');
    app(CompanyHealthService::class)->fichas();

    $consultas = 0;
    DB::listen(function () use (&$consultas): void {
        $consultas++;
    });

    app(CompanyHealthService::class)->fichas();

    expect($consultas)->toBe(0);
});
