<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Monitoring\Metrics\MetricsRecorder;
use App\Modules\Core\Monitoring\Metrics\Observation;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Support\DbTable;
use App\Modules\Core\Tenancy\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(CurrentCompany::class)->forget();
    config(['bmos.monitoreo.metricas_http.activo' => true, 'bmos.monitoreo.metricas_http.uno_de_cada' => 1]);
});

it('con el interruptor apagado, no se escribe ni una fila', function (): void {
    config(['bmos.monitoreo.metricas_http.activo' => false]);
    Route::get('/prueba-metricas-apagado', fn () => 'ok')->name('prueba.apagado');
    $this->get('/prueba-metricas-apagado');
    expect(DB::table('metric_buckets')->where('kind', 'http')->count())->toBe(0);
});

it('una petición normal deja UNA fila, con peso 1 cuando el muestreo es de 1 de 1', function (): void {
    Route::get('/prueba-metricas-normal', fn () => 'ok')->name('prueba.normal');
    $this->get('/prueba-metricas-normal');
    $fila = DB::table('metric_buckets')->where('kind', 'http')->where('name', 'prueba.normal')->first();
    expect($fila)->not->toBeNull()->and($fila->total)->toBe(1)->and($fila->errors)->toBe(0)->and($fila->method)->toBe('GET');
});

it('un 5xx se mide SIEMPRE, aunque el muestreo esté puesto muy alto', function (): void {
    config(['bmos.monitoreo.metricas_http.uno_de_cada' => 1000]);
    Route::get('/prueba-metricas-500', fn () => response('', 500))->name('prueba.500');
    $this->get('/prueba-metricas-500');
    $fila = DB::table('metric_buckets')->where('kind', 'http')->where('name', 'prueba.500')->first();
    expect($fila)->not->toBeNull()->and($fila->total)->toBe(1)->and($fila->errors)->toBe(1);
});

it('una petición lenta se mide SIEMPRE y pesa como aviso, no como error', function (): void {
    config(['bmos.monitoreo.metricas_http.uno_de_cada' => 1000, 'bmos.monitoreo.metricas_http.lento_ms' => 0]);
    Route::get('/prueba-metricas-lenta', fn () => 'ok')->name('prueba.lenta');
    $this->get('/prueba-metricas-lenta');
    $fila = DB::table('metric_buckets')->where('kind', 'http')->where('name', 'prueba.lenta')->first();
    expect($fila)->not->toBeNull()->and($fila->total)->toBe(1)->and($fila->warnings)->toBe(1)->and($fila->errors)->toBe(0);
});

it('/up no se mide: es infraestructura, no la aplicación', function (): void {
    $this->get('/up');
    expect(DB::table('metric_buckets')->where('kind', 'http')->where('name', 'up')->exists())->toBeFalse();
});

it('una URL que no casa con ninguna ruta se guarda como «unmatched», nunca la URL real', function (): void {
    $this->get('/esto-no-existe-en-ninguna-parte-del-todo');
    $fila = DB::table('metric_buckets')->where('kind', 'http')->where('name', 'unmatched')->first();
    expect($fila)->not->toBeNull()->and($fila->total)->toBe(1);
});

it('lo del operador de la plataforma no se atribuye a ninguna empresa', function (): void {
    $company = app(CompanyService::class)->create(new CreateCompanyData(name: 'Primera'));
    app(CurrentCompany::class)->set($company->id);
    $superadmin = User::create(['company_id' => $company->id, 'name' => 'Operador', 'email' => 'op@metricas.test', 'password' => 'secret-password', 'is_super_admin' => true]);
    Route::middleware('web')->get('/prueba-metricas-operador', fn () => 'ok')->name('prueba.operador');
    $this->actingAs($superadmin)->get('/prueba-metricas-operador');
    $fila = DB::table('metric_buckets')->where('kind', 'http')->where('name', 'prueba.operador')->first();
    expect($fila)->not->toBeNull()->and($fila->company_id)->toBe(0);
});

it('un usuario de empresa sí queda atribuido a la suya', function (): void {
    $company = app(CompanyService::class)->create(new CreateCompanyData(name: 'Con dueña'));
    app(CurrentCompany::class)->set($company->id);
    $duena = withRole(User::create(['company_id' => $company->id, 'name' => 'Dueña', 'email' => 'duena@metricas.test', 'password' => 'secret-password']), 'owner');
    Route::middleware('web')->get('/prueba-metricas-empresa', fn () => 'ok')->name('prueba.empresa');
    $this->actingAs($duena)->get('/prueba-metricas-empresa');
    $fila = DB::table('metric_buckets')->where('kind', 'http')->where('name', 'prueba.empresa')->first();
    expect($fila)->not->toBeNull()->and($fila->company_id)->toBe($company->id);
});

it('sin la tabla de métricas, la petición sigue respondiendo bien', function (): void {
    Schema::drop('metric_buckets');
    DbTable::olvidar();
    Route::get('/prueba-metricas-sin-tabla', fn () => 'ok')->name('prueba.sin-tabla');
    $this->get('/prueba-metricas-sin-tabla')->assertOk();
});

it('un archivo estático no se mide aunque llegara a pasar por Laravel', function (): void {
    Route::get('/build/algo.css', fn () => response('', 200))->name('prueba.estatico');
    $this->get('/build/algo.css');
    expect(DB::table('metric_buckets')->where('kind', 'http')->where('name', 'prueba.estatico')->exists())->toBeFalse();
});

it('una observación muestreada pesa como las que representa, salvo el máximo: eso no se suma', function (): void {
    // Va directo al `MetricsRecorder` y no por HTTP: el peso lo decide el muestreo (aleatorio) del
    // middleware, pero LO QUE HACE con un peso dado —sumar, no promediar— es determinista, y así se
    // prueba sin depender de que un sorteo «gane» dentro del test.
    app(MetricsRecorder::class)->anotar(
        kind: Observation::HTTP,
        name: 'prueba.peso.directo',
        method: 'GET',
        module: null,
        companyId: null,
        durationMs: 42.0,
        weight: 10,
    );

    $fila = DB::table('metric_buckets')->where('kind', 'http')->where('name', 'prueba.peso.directo')->first();
    expect($fila)->not->toBeNull()
        ->and($fila->total)->toBe(10)
        ->and($fila->sum_ms)->toBe(420)
        ->and($fila->max_ms)->toBe(42)
        ->and($fila->h0)->toBe(10);
});
