<?php

declare(strict_types=1);

use App\Modules\Core\Monitoring\Queries\PostgresStats;
use App\Modules\Core\Monitoring\Queries\QueryWatcher;
use App\Modules\Core\Support\DbTable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

/*
 * Fase 6: qué tan rápido va PostgreSQL. `QueryWatcher` solo hace aritmética por consulta (nunca
 * escribe una por una: sería contar consultas escribiendo más consultas) y lo vacía todo de una vez
 * en `vaciar()` —ahí, y solo ahí, se normaliza el SQL lento y se escribe—.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['bmos.monitoreo.consultas.activo' => true, 'bmos.monitoreo.consultas.umbral_ms' => 50]);
});

it('con el interruptor apagado, no se anota nada', function (): void {
    config(['bmos.monitoreo.consultas.activo' => false]);

    app(QueryWatcher::class)->observar(new QueryExecuted('select 1', [], 999.0, DB::connection()));
    app(QueryWatcher::class)->vaciar('prueba.apagado', null, null);

    expect(DB::table('metric_buckets')->where('kind', 'db')->count())->toBe(0)
        ->and(DB::table('slow_queries')->count())->toBe(0);
});

it('con el umbral en 50 ms, una consulta de 3 ms cuenta en el agregado pero no es «lenta»', function (): void {
    app(QueryWatcher::class)->observar(new QueryExecuted('select 1', [], 3.0, DB::connection()));
    app(QueryWatcher::class)->vaciar('prueba.rapida', null, null);

    $fila = DB::table('metric_buckets')->where('kind', 'db')->where('name', 'prueba.rapida')->first();
    expect($fila)->not->toBeNull()->and($fila->total)->toBe(1)
        ->and(DB::table('slow_queries')->count())->toBe(0);
});

it('con el umbral en 50 ms, una consulta de 120 ms sí se guarda como lenta', function (): void {
    app(QueryWatcher::class)->observar(new QueryExecuted('select 1', [], 120.0, DB::connection()));
    app(QueryWatcher::class)->vaciar('prueba.lenta', null, null);

    expect(DB::table('slow_queries')->count())->toBe(1);
    $fila = DB::table('slow_queries')->first();
    expect($fila->hits)->toBe(1)->and($fila->max_ms)->toBe(120)->and($fila->last_route)->toBe('prueba.lenta');
});

it('el SQL lento se guarda sin sus bindings', function (): void {
    app(QueryWatcher::class)->observar(new QueryExecuted(
        'select * from users where email = ?', ['secreta@ejemplo.com'], 120.0, DB::connection()
    ));
    app(QueryWatcher::class)->vaciar('prueba.sin-bindings', null, null);

    $muestra = DB::table('slow_queries')->value('sql_sample');
    expect($muestra)->not->toBeNull()->and($muestra)->not->toContain('secreta@ejemplo.com');
});

it('dos consultas iguales con valores distintos son UN solo patrón', function (): void {
    $watcher = app(QueryWatcher::class);
    $watcher->observar(new QueryExecuted('select * from orders where id = 5', [], 100.0, DB::connection()));
    $watcher->observar(new QueryExecuted('select * from orders where id = 42', [], 120.0, DB::connection()));
    $watcher->vaciar('prueba.agrupa', null, null);

    expect(DB::table('slow_queries')->count())->toBe(1);
    $fila = DB::table('slow_queries')->first();
    expect($fila->hits)->toBe(2)
        ->and($fila->sql_sample)->not->toContain('42')
        ->and($fila->total_ms)->toBe(220)
        ->and($fila->max_ms)->toBe(120);
});

it('la reentrada no se cuenta a sí misma: con el umbral en 0, las escrituras del propio vaciado no se anotan como lentas', function (): void {
    // Con el umbral en 0, CUALQUIER consulta lo pasa —incluidas las que el propio `vaciar()` va a
    // ejecutar sobre `metric_buckets` y `slow_queries` un instante después—. Sin la guarda, esas
    // escrituras se observarían a sí mismas y `slow_queries` acabaría con varias filas (una por cada
    // sentencia interna: el UPDATE, el INSERT…), no con la única consulta de verdad.
    config(['bmos.monitoreo.consultas.umbral_ms' => 0]);

    app(QueryWatcher::class)->observar(new QueryExecuted('select 1', [], 1.0, DB::connection()));
    app(QueryWatcher::class)->vaciar('prueba.reentrada', null, null);

    expect(DB::table('slow_queries')->count())->toBe(1)
        ->and(DB::table('slow_queries')->value('hits'))->toBe(1);
});

it('el máximo se guarda exacto aunque varias consultas caigan en el mismo tramo del histograma', function (): void {
    $watcher = app(QueryWatcher::class);
    $watcher->observar(new QueryExecuted('select 1', [], 10.0, DB::connection()));
    $watcher->observar(new QueryExecuted('select 1', [], 55.0, DB::connection()));
    $watcher->observar(new QueryExecuted('select 1', [], 30.0, DB::connection()));
    $watcher->vaciar('prueba.maximo', null, null);

    $fila = DB::table('metric_buckets')->where('kind', 'db')->where('name', 'prueba.maximo')->first();
    expect($fila->total)->toBe(3)->and($fila->max_ms)->toBe(55)->and($fila->h0)->toBe(3);
});

it('sin la tabla de consultas lentas, vaciar() no rompe nada', function (): void {
    Schema::drop('slow_queries');
    DbTable::olvidar();

    app(QueryWatcher::class)->observar(new QueryExecuted('select 1', [], 999.0, DB::connection()));
    app(QueryWatcher::class)->vaciar('prueba.sin-tabla', null, null);

    expect(DB::table('metric_buckets')->where('kind', 'db')->where('name', 'prueba.sin-tabla')->exists())->toBeTrue();
});

it('sin la tabla del agregador, vaciar() tampoco rompe nada', function (): void {
    Schema::drop('metric_buckets');
    DbTable::olvidar();

    app(QueryWatcher::class)->observar(new QueryExecuted('select 1', [], 999.0, DB::connection()));
    app(QueryWatcher::class)->vaciar('prueba.sin-metricas', null, null);

    expect(true)->toBeTrue(); // no lanzó
});

it('en SQLite, PostgresStats no pregunta nada y devuelve null sin lanzar', function (): void {
    expect(app(PostgresStats::class)->leer())->toBeNull();
});

// --------------------------------------------------------------------- De extremo a extremo

it('una petición HTTP de verdad deja también su fila kind=db, con las consultas que hizo', function (): void {
    config(['bmos.monitoreo.metricas_http.activo' => false]); // aislar: solo interesa la parte de BD
    Route::get('/prueba-consultas-http', fn () => DB::table('metric_buckets')->count())->name('prueba.consultas-http');

    $this->get('/prueba-consultas-http');

    expect(DB::table('metric_buckets')->where('kind', 'db')->where('name', 'prueba.consultas-http')->exists())->toBeTrue();
});

it('un trabajo de cola de verdad deja su propia fila kind=db, separada de la petición que lo despachó', function (): void {
    config(['queue.default' => 'sync']);

    TrabajoDeColaConConsultas::dispatch();

    expect(DB::table('metric_buckets')->where('kind', 'db')->where('name', 'TrabajoDeColaConConsultas')->exists())->toBeTrue();
});

/** Un trabajo de mentira que sí toca la base, para probar que `QueueEventSubscriber` vacía las consultas. */
final class TrabajoDeColaConConsultas implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        DB::table('metric_buckets')->count();
    }
}
