<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Models\ErrorEvent;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Support\DbTable;
use App\Modules\Core\Tenancy\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

/*
 * El detalle de UN grupo de errores: a quién afectó y qué hacer con él.
 *
 * MonitoringTest ya prueba que la lista agrupada llega a la pantalla de Errores; este archivo cubre lo
 * que es propio de esta pantalla aparte: el desglose por empresa y por usuario (no solo «la última»
 * que guarda el grupo), lo que no se pudo atribuir a ninguna empresa, el aviso de los grupos de antes
 * del desglose, y las tres acciones sobre un grupo —resolver, ignorar, reabrir— con quién y cuándo
 * quedan escritos.
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
        'email' => 'super@detalle.test', 'password' => 'secret-password',
        'is_super_admin' => true,
    ]);

    $this->duena = withRole(User::create([
        'company_id' => $this->primera->id, 'name' => 'Dueña',
        'email' => 'duena@detalle.test', 'password' => 'secret-password',
    ]), 'owner');
});

// ------------------------------------------------------------------------- Quién entra

it('solo el operador de la plataforma ve el detalle de un error', function (): void {
    ErrorEvent::anotar(new RuntimeException('un fallo cualquiera'), ['x.php:1'], null, null, null);
    $error = ErrorEvent::query()->firstOrFail();

    $this->actingAs($this->duena)->get(route('platform.monitoring.error', $error))->assertForbidden();
    $this->actingAs($this->super)->get(route('platform.monitoring.error', $error))->assertOk();
});

it('solo el operador de la plataforma puede cambiar el estado de un grupo', function (): void {
    ErrorEvent::anotar(new RuntimeException('para probar permisos'), ['x.php:1'], null, null, null);
    $error = ErrorEvent::query()->firstOrFail();

    $this->actingAs($this->duena)
        ->post(route('platform.monitoring.error.status', $error), ['accion' => 'resolve'])
        ->assertForbidden();
});

// ------------------------------------------------------------------------- El desglose

it('enseña a qué empresas y usuarios afectó, y lo que quedó sin atribuir', function (): void {
    $afectado = User::create([
        'company_id' => $this->primera->id, 'name' => 'Cliente Afectado',
        'email' => 'afectado@detalle.test', 'password' => 'secret-password',
    ]);

    $e = new RuntimeException('fallo con desglose completo');
    ErrorEvent::anotar($e, ['x.php:1'], null, $this->primera->id, $afectado->id);
    ErrorEvent::anotar($e, ['x.php:1'], null, $this->segunda->id, null);
    ErrorEvent::anotar($e, ['x.php:1'], null, null, null); // ni empresa ni usuario: sin atribuir

    $error = ErrorEvent::query()->where('message', 'fallo con desglose completo')->firstOrFail();

    $this->actingAs($this->super)->get(route('platform.monitoring.error', $error))
        ->assertOk()
        ->assertSee('Primera')
        ->assertSee('Segunda')
        ->assertSee('Cliente Afectado')
        ->assertSee('Sin empresa (plataforma, consola, sin sesión)');
});

it('un grupo de antes del desglose se avisa como histórico', function (): void {
    $error = ErrorEvent::query()->create([
        'fingerprint' => 'v1_manual_historico',
        'class' => RuntimeException::class,
        'message' => 'error de la versión vieja',
        'origin' => 'x.php:1',
        'hits' => 1,
        'status' => ErrorEvent::ACTIVO,
        'fingerprint_version' => 1,
        'companies_count' => 0,
        'users_count' => 0,
        'first_seen_at' => now(),
        'last_seen_at' => now(),
    ]);

    $this->actingAs($this->super)->get(route('platform.monitoring.error', $error))
        ->assertOk()
        ->assertSee('histórico v1');
});

// ------------------------------------------------------------------------- Las acciones

it('resuelve un grupo y anota quién y cuándo', function (): void {
    ErrorEvent::anotar(new RuntimeException('para resolver'), ['x.php:1'], null, null, null);
    $error = ErrorEvent::query()->firstOrFail();

    $this->actingAs($this->super)
        ->post(route('platform.monitoring.error.status', $error), ['accion' => 'resolve'])
        ->assertRedirect()
        ->assertSessionHas('panel_ok');

    $error->refresh();

    expect($error->status)->toBe(ErrorEvent::RESUELTO)
        ->and($error->resolved_by)->toBe($this->super->id)
        ->and($error->resolved_at)->not->toBeNull();
});

it('ignora un grupo y anota quién y cuándo', function (): void {
    ErrorEvent::anotar(new RuntimeException('para ignorar'), ['x.php:1'], null, null, null);
    $error = ErrorEvent::query()->firstOrFail();

    $this->actingAs($this->super)
        ->post(route('platform.monitoring.error.status', $error), ['accion' => 'ignore'])
        ->assertRedirect();

    $error->refresh();

    expect($error->status)->toBe(ErrorEvent::IGNORADO)
        ->and($error->resolved_by)->toBe($this->super->id);
});

it('reabrir limpia quién y cuándo se había resuelto', function (): void {
    ErrorEvent::anotar(new RuntimeException('para reabrir'), ['x.php:1'], null, null, null);
    $error = ErrorEvent::query()->firstOrFail();
    $error->update(['status' => ErrorEvent::RESUELTO, 'resolved_at' => now(), 'resolved_by' => $this->super->id]);

    $this->actingAs($this->super)
        ->post(route('platform.monitoring.error.status', $error), ['accion' => 'reopen'])
        ->assertRedirect();

    $error->refresh();

    expect($error->status)->toBe(ErrorEvent::ACTIVO)
        ->and($error->resolved_by)->toBeNull()
        ->and($error->resolved_at)->toBeNull();
});

it('rechaza una acción que no está en la lista', function (): void {
    ErrorEvent::anotar(new RuntimeException('para probar validación'), ['x.php:1'], null, null, null);
    $error = ErrorEvent::query()->firstOrFail();

    $this->actingAs($this->super)
        ->post(route('platform.monitoring.error.status', $error), ['accion' => 'borrar-todo'])
        ->assertSessionHasErrors('accion');

    expect($error->fresh()->status)->toBe(ErrorEvent::ACTIVO);
});

it('sin la migración del desglose, avisa en vez de fallar', function (): void {
    ErrorEvent::anotar(new RuntimeException('antes de migrar'), ['x.php:1'], null, null, null);
    $error = ErrorEvent::query()->firstOrFail();

    // Con solo esta tabla ausente, `MonitoringSchema::erroresConDesglose()` ya da por no aplicada la
    // migración: es la misma comprobación que usa el registrador de errores.
    Schema::drop('error_event_companies');
    DbTable::olvidar();

    $this->actingAs($this->super)
        ->post(route('platform.monitoring.error.status', $error), ['accion' => 'resolve'])
        ->assertRedirect()
        ->assertSessionHas('panel_error');

    expect($error->fresh()->status)->toBe(ErrorEvent::ACTIVO);
});
