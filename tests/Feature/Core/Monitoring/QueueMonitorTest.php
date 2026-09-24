<?php

declare(strict_types=1);

use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Models\SystemEvent;
use App\Modules\Core\Monitoring\Queues\QueueMonitor;
use App\Modules\Core\Services\CompanyEraser;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Support\DbTable;
use App\Modules\Core\Tenancy\CurrentCompany;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/*
 * Fase 4: colas y trabajos, sin sistema paralelo. Los trabajos de verdad de la aplicación pasan por
 * `QueueEventSubscriber`, que anota cuánto tardaron y si fallaron en `metric_buckets` y en el
 * registro (`queue.failed`/`queue.slow`) sin tocar `CurrentCompany` —la empresa sale del PAYLOAD del
 * propio trabajo, que `Queue::createPayloadUsing` mete al despacharlo—.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(CurrentCompany::class)->forget();

    $this->company = app(CompanyService::class)->create(new CreateCompanyData(name: 'Con trabajos'));
});

// ------------------------------------------------------------------------- El trabajo, en sync

it('en sync: un trabajo que falla anota queue.failed con la empresa del payload, sin tocar CurrentCompany', function (): void {
    config(['queue.default' => 'sync']);
    app(CurrentCompany::class)->set($this->company->id);

    try {
        TrabajoDeColaFallido::dispatch();
    } catch (RuntimeException) {
        // Esperado: sync relanza la excepción del trabajo tras marcarlo como fallido.
    }

    // La empresa activa SIGUE siendo la misma: el listener no la tocó.
    expect(app(CurrentCompany::class)->id())->toBe($this->company->id);

    $suceso = SystemEvent::query()->where('type', 'queue.failed')->first();

    expect($suceso)->not->toBeNull()
        ->and($suceso->level)->toBe(SystemEvent::GRAVE)
        ->and($suceso->company_id)->toBe($this->company->id)
        ->and($suceso->message)->toContain('TrabajoDeColaFallido');

    if (DbTable::existe('metric_buckets')) {
        $fila = DB::table('metric_buckets')->where('kind', 'job')->where('company_id', $this->company->id)->first();
        expect($fila)->not->toBeNull()->and($fila->errors)->toBe(1);
    }
});

it('en sync: un trabajo que tarda de más anota queue.slow, no queue.failed', function (): void {
    config(['queue.default' => 'sync', 'bmos.monitoreo.colas.lento_segundos' => 0]);
    app(CurrentCompany::class)->set($this->company->id);

    TrabajoDeColaExitoso::dispatch();

    expect(SystemEvent::query()->where('type', 'queue.slow')->exists())->toBeTrue()
        ->and(SystemEvent::query()->where('type', 'queue.failed')->exists())->toBeFalse();
});

it('un trabajo que sale bien y rápido no anota ni queue.failed ni queue.slow', function (): void {
    config(['queue.default' => 'sync', 'bmos.monitoreo.colas.lento_segundos' => 60]);

    TrabajoDeColaExitoso::dispatch();

    expect(SystemEvent::query()->whereIn('type', ['queue.failed', 'queue.slow'])->exists())->toBeFalse();
});

// ------------------------------------------------------------------------- QueueMonitor

it('con sync, QueueMonitor no aplica y usa el proxy de mensajes atascados', function (): void {
    config(['queue.default' => 'sync']);

    $snapshot = app(QueueMonitor::class)->snapshot();

    expect($snapshot['aplica'])->toBeFalse()->and($snapshot['driver'])->toBe('sync');
});

it('con database: dispatch + queue:work --once deja constancia en failed_jobs y en el snapshot', function (): void {
    config(['queue.default' => 'database']);

    expect(DB::table('jobs')->count())->toBe(0);

    TrabajoDeColaFallido::dispatch();

    expect(DB::table('jobs')->count())->toBe(1);

    $antes = app(QueueMonitor::class)->snapshot();
    expect($antes['aplica'])->toBeTrue()->and($antes['pendientes'])->toBe(1);

    Artisan::call('queue:work', ['--once' => true, '--tries' => 1]);

    expect(DB::table('jobs')->count())->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe(1);

    $despues = app(QueueMonitor::class)->snapshot();
    expect($despues['pendientes'])->toBe(0);

    $fallidos = app(QueueMonitor::class)->fallidosRecientes();
    expect($fallidos)->toHaveCount(1);
});

it('con database: un trabajo pendiente sin reservar cuenta como pendiente, no como reservado', function (): void {
    config(['queue.default' => 'database']);

    TrabajoDeColaExitoso::dispatch();

    $snapshot = app(QueueMonitor::class)->snapshot();

    expect($snapshot['pendientes'])->toBe(1)->and($snapshot['reservados'])->toBe(0);
});

// ------------------------------------------------------------------------- La poda y el borrado de empresa

it('la poda borra los trabajos fallidos viejos y respeta los recientes', function (): void {
    DB::table('failed_jobs')->insert([
        'uuid' => (string) Str::uuid(), 'connection' => 'database', 'queue' => 'default',
        'payload' => '{}', 'exception' => 'vieja', 'failed_at' => now()->subDays(60),
    ]);
    $reciente = DB::table('failed_jobs')->insertGetId([
        'uuid' => (string) Str::uuid(), 'connection' => 'database', 'queue' => 'default',
        'payload' => '{}', 'exception' => 'reciente', 'failed_at' => now(),
    ]);

    Artisan::call('registros:purgar');

    expect(DB::table('failed_jobs')->count())->toBe(1)
        ->and(DB::table('failed_jobs')->where('id', $reciente)->exists())->toBeTrue();
});

it('la poda de métricas respeta el bucket de este mes y borra el de hace tres', function (): void {
    DB::table('metric_buckets')->insert([
        'kind' => 'job', 'bucket_start' => now()->subDays(90), 'name' => 'Viejo', 'method' => '',
        'company_id' => 0, 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('metric_buckets')->insert([
        'kind' => 'job', 'bucket_start' => now(), 'name' => 'Reciente', 'method' => '',
        'company_id' => 0, 'created_at' => now(), 'updated_at' => now(),
    ]);

    Artisan::call('registros:purgar');

    expect(DB::table('metric_buckets')->count())->toBe(1)
        ->and(DB::table('metric_buckets')->where('name', 'Reciente')->exists())->toBeTrue();
});

it('borrar una empresa por completo se lleva sus métricas, sin tocar las de otra', function (): void {
    $otra = app(CompanyService::class)->create(new CreateCompanyData(name: 'Otra empresa'));

    DB::table('metric_buckets')->insert([
        'kind' => 'job', 'bucket_start' => now(), 'name' => 'De la borrada', 'method' => '',
        'company_id' => $this->company->id, 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('metric_buckets')->insert([
        'kind' => 'job', 'bucket_start' => now(), 'name' => 'De la otra', 'method' => '',
        'company_id' => $otra->id, 'created_at' => now(), 'updated_at' => now(),
    ]);

    app(CompanyEraser::class)->erase($this->company->fresh());

    expect(DB::table('metric_buckets')->where('company_id', $this->company->id)->exists())->toBeFalse()
        ->and(DB::table('metric_buckets')->where('company_id', $otra->id)->exists())->toBeTrue();
});

it('sin la tabla de métricas, un trabajo sigue procesándose sin romperse', function (): void {
    Schema::drop('metric_buckets');
    DbTable::olvidar();

    config(['queue.default' => 'sync']);

    TrabajoDeColaExitoso::dispatch();

    expect(true)->toBeTrue(); // no lanzó
});

/** Un trabajo de mentira que sale bien. */
final class TrabajoDeColaExitoso implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void {}
}

/** Un trabajo de mentira que siempre falla, a la primera (sin reintentos). */
final class TrabajoDeColaFallido implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function handle(): void
    {
        throw new RuntimeException('a propósito, para la prueba');
    }
}
