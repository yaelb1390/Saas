<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Models\ErrorEvent;
use App\Modules\Core\Models\Incident;
use App\Modules\Core\Models\IncidentLink;
use App\Modules\Core\Models\SystemEvent;
use App\Modules\Core\Monitoring\Incidents\IncidentService;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Support\DbTable;
use App\Modules\Core\Tenancy\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

/*
 * Incidentes (Fase 2): cuándo un grupo de errores basta para abrir uno por sí solo, y qué se puede
 * hacer con él después.
 *
 * Dos disparadores, con su propia ventana: una RACHA (25 veces en 15 minutos, de fábrica) o que el
 * mismo grupo afecte a 3 empresas distintas en 30 minutos. Los dos apuntan a la misma clave de
 * deduplicación (`error:{huella}`): mientras el incidente siga activo, lo que llega se le SUMA; si
 * se resuelve y el problema vuelve, es un incidente NUEVO con su propio código —no hay
 * autorresolución de errores que pueda decidir que seguía siendo «lo mismo»—.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutVite();
    app(CurrentCompany::class)->forget();

    $this->primera = app(CompanyService::class)->create(new CreateCompanyData(name: 'Primera'));
    $this->segunda = app(CompanyService::class)->create(new CreateCompanyData(name: 'Segunda'));
    $this->tercera = app(CompanyService::class)->create(new CreateCompanyData(name: 'Tercera'));

    app(CurrentCompany::class)->set($this->primera->id);

    $this->super = User::create([
        'company_id' => $this->primera->id, 'name' => 'Operador',
        'email' => 'super@incidentes.test', 'password' => 'secret-password',
        'is_super_admin' => true,
    ]);

    $this->duena = withRole(User::create([
        'company_id' => $this->primera->id, 'name' => 'Dueña',
        'email' => 'duena@incidentes.test', 'password' => 'secret-password',
    ]), 'owner');
});

// ------------------------------------------------------------------------- La auto-apertura

it('una racha de 25 en 15 minutos abre un incidente solo', function (): void {
    $e = new RuntimeException('el mismo fallo, otra vez');

    // 24 no bastan: sigue sin incidente.
    for ($i = 0; $i < 24; $i++) {
        ErrorEvent::anotar($e, ['a.php:1'], null, null, null);
    }

    expect(Incident::query()->count())->toBe(0);

    // La 25.ª sí.
    ErrorEvent::anotar($e, ['a.php:1'], null, null, null);

    $incidente = Incident::query()->firstOrFail();

    expect($incidente->status)->toBe(Incident::OPEN)
        ->and($incidente->source)->toBe(Incident::FUENTE_AUTO)
        ->and($incidente->occurrences)->toBe(1)
        ->and($incidente->dedupe_key)->toStartWith('error:');
});

it('el mismo grupo en 3 empresas distintas abre un incidente, aunque cada una lo sufra pocas veces', function (): void {
    $e = new RuntimeException('fallo repartido entre empresas');

    ErrorEvent::anotar($e, ['b.php:1'], null, $this->primera->id, null);
    expect(Incident::query()->count())->toBe(0); // una sola empresa no basta

    ErrorEvent::anotar($e, ['b.php:1'], null, $this->segunda->id, null);
    expect(Incident::query()->count())->toBe(0); // dos tampoco

    ErrorEvent::anotar($e, ['b.php:1'], null, $this->tercera->id, null);

    $incidente = Incident::query()->firstOrFail();

    expect($incidente->companies_count)->toBe(3)
        ->and($incidente->companies()->pluck('company_id')->sort()->values()->all())
        ->toBe(collect([$this->primera->id, $this->segunda->id, $this->tercera->id])->sort()->values()->all());
});

it('mientras el incidente sigue activo, lo que llega se SUMA y no abre uno nuevo', function (): void {
    $e = new RuntimeException('racha que sigue');

    for ($i = 0; $i < 25; $i++) {
        ErrorEvent::anotar($e, ['c.php:1'], null, null, null);
    }

    expect(Incident::query()->count())->toBe(1);

    for ($i = 0; $i < 5; $i++) {
        ErrorEvent::anotar($e, ['c.php:1'], null, null, null);
    }

    expect(Incident::query()->count())->toBe(1)
        ->and(Incident::query()->firstOrFail()->occurrences)->toBe(6); // la que abrió + 5 más
});

it('reabre incidente NUEVO si el mismo problema reaparece tras resolverse: sin autorresolución', function (): void {
    $e = new RuntimeException('reaparece tras resolverse');

    for ($i = 0; $i < 25; $i++) {
        ErrorEvent::anotar($e, ['d.php:1'], null, null, null);
    }

    $primero = Incident::query()->firstOrFail();
    app(IncidentService::class)->cambiarEstado($primero, 'resolve', $this->super->id);

    for ($i = 0; $i < 25; $i++) {
        ErrorEvent::anotar($e, ['d.php:1'], null, null, null);
    }

    expect(Incident::query()->count())->toBe(2);

    $segundo = Incident::query()->where('id', '!=', $primero->id)->firstOrFail();

    expect($segundo->code)->not->toBe($primero->code)
        ->and($segundo->status)->toBe(Incident::OPEN);
});

it('un error que se queda corto en las dos ventanas no abre nada', function (): void {
    ErrorEvent::anotar(new RuntimeException('fallo tranquilo'), ['e.php:1'], null, $this->primera->id, null);
    ErrorEvent::anotar(new RuntimeException('fallo tranquilo'), ['e.php:1'], null, $this->segunda->id, null);

    expect(Incident::query()->count())->toBe(0);
});

// ------------------------------------------------------------------------- El código

it('el código es INC-{año}-{número}, correlativo por año', function (): void {
    $primero = app(IncidentService::class)->detectarOAbrir('error:a', 'Uno', null, 'high', IncidentLink::ERROR_EVENT, 1, []);
    $segundo = app(IncidentService::class)->detectarOAbrir('error:b', 'Dos', null, 'high', IncidentLink::ERROR_EVENT, 2, []);

    $anio = now()->format('Y');

    expect($primero->code)->toBe("INC-{$anio}-0001")
        ->and($segundo->code)->toBe("INC-{$anio}-0002");
});

// ------------------------------------------------------------------------- Las acciones

it('resolver anota quién, cuándo, y la duración queda calculada', function (): void {
    $incidente = app(IncidentService::class)->detectarOAbrir('error:res', 'A resolver', 'app', 'high', IncidentLink::ERROR_EVENT, 1, []);

    $this->travel(2)->hours();

    app(IncidentService::class)->cambiarEstado($incidente, 'resolve', $this->super->id);
    $incidente->refresh();

    expect($incidente->status)->toBe(Incident::RESOLVED)
        ->and($incidente->resolved_by)->toBe($this->super->id)
        ->and($incidente->resolved_at)->not->toBeNull()
        ->and($incidente->duracion()?->totalHours)->toBeGreaterThanOrEqual(2.0);

    $suceso = SystemEvent::query()->where('type', 'incident.status_changed')->latest('id')->first();
    expect($suceso)->not->toBeNull()->and($suceso->level)->toBe(SystemEvent::INFO);
});

it('ignorar y reabrir cambian el estado y limpian resolved_at al reabrir', function (): void {
    $incidente = app(IncidentService::class)->detectarOAbrir('error:ign', 'A ignorar', null, 'medium', IncidentLink::ERROR_EVENT, 1, []);

    app(IncidentService::class)->cambiarEstado($incidente, 'ignore', $this->super->id);
    $incidente->refresh();
    expect($incidente->status)->toBe(Incident::IGNORED)->and($incidente->resolved_at)->not->toBeNull();

    app(IncidentService::class)->cambiarEstado($incidente, 'reopen', $this->super->id);
    $incidente->refresh();
    expect($incidente->status)->toBe(Incident::OPEN)
        ->and($incidente->resolved_at)->toBeNull()
        ->and($incidente->resolved_by)->toBeNull();
});

it('abrir un incidente escribe un SystemEvent de la familia incident', function (): void {
    // Sin usuario autenticado ni empresa activa: es el caso de un job o un webhook, y ahí
    // `TenantAttribution` no tiene ninguna que atribuir (regla 3, sin `CurrentCompany::set()`).
    app(CurrentCompany::class)->forget();

    app(IncidentService::class)->detectarOAbrir('error:sys', 'Con suceso', 'polar', 'critical', IncidentLink::ERROR_EVENT, 1, []);

    $suceso = SystemEvent::query()->where('type', 'incident.opened')->first();

    expect($suceso)->not->toBeNull()
        ->and($suceso->level)->toBe(SystemEvent::GRAVE)
        ->and($suceso->company_id)->toBeNull();
});

// ------------------------------------------------------------------------- La pantalla

it('solo el operador de la plataforma ve el detalle de un incidente', function (): void {
    $incidente = app(IncidentService::class)->detectarOAbrir('error:perm', 'Permisos', null, 'high', IncidentLink::ERROR_EVENT, 1, []);

    $this->actingAs($this->duena)->get(route('platform.monitoring.incidents.show', $incidente))->assertForbidden();
    $this->actingAs($this->super)->get(route('platform.monitoring.incidents.show', $incidente))->assertOk();
});

it('solo el operador de la plataforma puede cambiar el estado de un incidente', function (): void {
    $incidente = app(IncidentService::class)->detectarOAbrir('error:perm2', 'Permisos', null, 'high', IncidentLink::ERROR_EVENT, 1, []);

    $this->actingAs($this->duena)
        ->post(route('platform.monitoring.incidents.status', $incidente), ['accion' => 'resolve'])
        ->assertForbidden();
});

it('la pestaña Incidentes enseña el código, el título y a cuántas empresas afectó', function (): void {
    app(IncidentService::class)->detectarOAbrir(
        'error:pantalla', 'Fallo visible en pantalla', 'evolution', 'high',
        IncidentLink::ERROR_EVENT, 1, [$this->primera->id, $this->segunda->id],
    );

    $this->actingAs($this->super)
        ->get(route('platform.monitoring', ['pestana' => 'incidentes']))
        ->assertOk()
        ->assertSee('Fallo visible en pantalla')
        ->assertSee('2 empresas');
});

it('el detalle enseña el desglose por empresa y de dónde salió', function (): void {
    $e = new RuntimeException('fallo con desglose');
    for ($i = 0; $i < 25; $i++) {
        ErrorEvent::anotar($e, ['f.php:1'], null, $this->primera->id, null);
    }

    $incidente = Incident::query()->firstOrFail();

    $this->actingAs($this->super)->get(route('platform.monitoring.incidents.show', $incidente))
        ->assertOk()
        ->assertSee('Primera')
        ->assertSee('Grupo de error');
});

it('contador real: MonitoringCounters cuenta los incidentes activos, no null', function (): void {
    app(IncidentService::class)->detectarOAbrir('error:cnt1', 'Uno', null, 'high', IncidentLink::ERROR_EVENT, 1, []);
    $resuelto = app(IncidentService::class)->detectarOAbrir('error:cnt2', 'Dos', null, 'high', IncidentLink::ERROR_EVENT, 2, []);
    app(IncidentService::class)->cambiarEstado($resuelto, 'resolve', $this->super->id);

    expect(app(App\Modules\Core\Monitoring\Counters\MonitoringCounters::class)->calcular()['incidentes_activos'])->toBe(1);
});

it('sin la tabla de incidentes, el registro de errores sigue funcionando y no abre nada', function (): void {
    Schema::drop('incident_companies');
    Schema::drop('incident_links');
    Schema::drop('incidents');
    DbTable::olvidar();

    ErrorEvent::anotar(new RuntimeException('sin fase 2 migrada'), ['g.php:1'], null, null, null);

    expect(ErrorEvent::query()->count())->toBe(1);
});
