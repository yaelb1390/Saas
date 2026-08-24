<?php

declare(strict_types=1);

use App\Modules\Core\Models\SystemEvent;
use App\Modules\Core\Services\PlatformHealthService;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * Las 24 horas anteriores del pulso de Monitoreo.
 *
 * Comparar dos períodos tiene un error clásico y silencioso: que las ventanas se solapen o dejen un
 * hueco entre ellas. Un suceso contado dos veces, o ninguna, no rompe nada —sale un número normal—
 * pero mueve el porcentaje, y esta pantalla existe justo para decidir si algo va peor que ayer.
 *
 * Por eso lo que se fija aquí es el borde: dónde acaba una ventana y empieza la otra.
 */

uses(RefreshDatabase::class);

/** Un suceso con fecha exacta, que es lo único que estas pruebas miran. */
function sucesoHace(float $horas, string $type = 'auth.login', string $level = SystemEvent::INFO): SystemEvent
{
    $evento = SystemEvent::create(['type' => $type, 'level' => $level, 'message' => 'prueba']);
    $evento->forceFill(['created_at' => now()->subMinutes((int) round($horas * 60))])->saveQuietly();

    return $evento;
}

beforeEach(function (): void {
    SystemEvent::olvidarSiHayTabla();
});

it('separa las últimas 24 horas de las 24 anteriores sin solapar ni dejar hueco', function (): void {
    sucesoHace(1);    // dentro de las últimas 24
    sucesoHace(23);   // dentro de las últimas 24, casi en el borde
    sucesoHace(25);   // en las 24 anteriores, justo pasado el borde
    sucesoHace(47);   // en las 24 anteriores, casi en el borde de fuera
    sucesoHace(49);   // fuera de las dos: demasiado viejo

    $pulso = app(PlatformHealthService::class)->pulso();

    expect($pulso['dia']['accesos'])->toBe(2)
        ->and($pulso['antes']['accesos'])->toBe(2)
        // Y el de hace 49 horas no está en ninguna: cinco sucesos, cuatro contados.
        ->and($pulso['dia']['sucesos'] + $pulso['antes']['sucesos'])->toBe(4);
});

it('distingue los avisos de los accesos también en la ventana anterior', function (): void {
    sucesoHace(30, 'app.error', SystemEvent::GRAVE);
    sucesoHace(30, 'auth.failed', SystemEvent::AVISO);
    sucesoHace(2, 'auth.failed', SystemEvent::AVISO);

    $pulso = app(PlatformHealthService::class)->pulso();

    expect($pulso['antes']['problemas'])->toBe(2)
        ->and($pulso['antes']['fallidos'])->toBe(1)
        ->and($pulso['dia']['fallidos'])->toBe(1);
});

it('sin nada ayer, la ventana anterior es cero y no falta', function (): void {
    // La vista lee $pulso['antes'] siempre; si la clave no estuviera, Monitoreo se caería en una
    // instalación recién puesta en marcha, que es cuando menos se puede permitir.
    sucesoHace(2);

    $pulso = app(PlatformHealthService::class)->pulso();

    expect($pulso['antes'])->toBe(['sucesos' => 0, 'problemas' => 0, 'accesos' => 0, 'fallidos' => 0]);
});
