<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/*
 * La poda de la auditoría y de los sucesos.
 *
 * Un comando que BORRA tiene que estar mejor probado que uno que lee: aquí el fallo no da un error,
 * da un silencio, y lo que se llevó por delante no vuelve. Lo que más se vigila es lo que NO debe
 * borrar.
 */

uses(RefreshDatabase::class);

function suceso(string $cuando): void
{
    DB::table('system_events')->insert([
        'type' => 'task.run',
        'level' => 'info',
        'message' => 'una tarea corrió',
        'created_at' => $cuando,
    ]);
}

function auditoria(string $cuando): void
{
    DB::table('audits')->insert([
        'event' => 'updated',
        'auditable_type' => 'App\\Models\\Cosa',
        'auditable_id' => 1,
        'created_at' => $cuando,
        'updated_at' => $cuando,
    ]);
}

it('borra los sucesos viejos y deja los recientes', function () {
    suceso(now()->subDays(200)->toDateTimeString());
    suceso(now()->subDays(91)->toDateTimeString());
    suceso(now()->subDays(89)->toDateTimeString());
    suceso(now()->toDateTimeString());

    $this->artisan('registros:purgar')->assertSuccessful();

    expect(DB::table('system_events')->count())->toBe(2);
});

/*
 * LA REGLA QUE MANDA SOBRE TODAS. La auditoría es el rastro del negocio —quién cambió este precio—
 * y se consulta cuando hay una discusión, a veces meses después. Viene apagada: encenderla es una
 * decisión del dueño, no nuestra. Si este test se pone rojo, alguien le puso un valor por omisión y
 * está borrando el historial de gente que no lo pidió.
 */
it('no toca la auditoria mientras nadie encienda su retencion', function () {
    auditoria(now()->subYears(5)->toDateTimeString());
    suceso(now()->subDays(200)->toDateTimeString());

    $this->artisan('registros:purgar')->assertSuccessful();

    expect(DB::table('audits')->count())->toBe(1)
        ->and(DB::table('system_events')->count())->toBe(0);
});

it('poda la auditoria cuando se le pide expresamente', function () {
    auditoria(now()->subDays(40)->toDateTimeString());
    auditoria(now()->subDays(20)->toDateTimeString());

    $this->artisan('registros:purgar', ['--auditoria' => 30])->assertSuccessful();

    expect(DB::table('audits')->count())->toBe(1);
});

it('lee la retencion de la configuracion', function () {
    config(['bmos.retencion.auditoria' => 10]);
    auditoria(now()->subDays(40)->toDateTimeString());

    $this->artisan('registros:purgar')->assertSuccessful();

    expect(DB::table('audits')->count())->toBe(0);
});

it('con simular cuenta pero no borra', function () {
    suceso(now()->subDays(200)->toDateTimeString());
    auditoria(now()->subDays(200)->toDateTimeString());

    $this->artisan('registros:purgar', ['--auditoria' => 30, '--simular' => true])->assertSuccessful();

    expect(DB::table('system_events')->count())->toBe(1)
        ->and(DB::table('audits')->count())->toBe(1);
});

/*
 * Un número de días negativo, restado a hoy, da un corte EN EL FUTURO: no podaría lo viejo, se
 * llevaría la tabla entera incluida la fila escrita hace un minuto. Es el error que más caro sale y
 * el más fácil de colar con un `-1` mal tecleado.
 */
it('un numero de dias negativo no borra nada', function () {
    suceso(now()->subDays(500)->toDateTimeString());
    suceso(now()->toDateTimeString());

    $this->artisan('registros:purgar', ['--sucesos' => -1])->assertSuccessful();

    expect(DB::table('system_events')->count())->toBe(2);
});

/*
 * El borrado va en lotes de mil para no bloquear la tabla. Con exactamente mil filas el bucle se
 * podría parar creyendo que terminó: por eso se prueba con más de un lote y se exige que no quede
 * ninguna.
 */
it('borra tambien lo que pasa de un lote', function () {
    $filas = [];
    $viejo = now()->subDays(200)->toDateTimeString();

    for ($i = 0; $i < 1200; $i++) {
        $filas[] = ['type' => 'task.run', 'level' => 'info', 'message' => 'x', 'created_at' => $viejo];
    }

    foreach (array_chunk($filas, 100) as $trozo) {
        DB::table('system_events')->insert($trozo);
    }

    suceso(now()->toDateTimeString());

    $this->artisan('registros:purgar')->assertSuccessful();

    expect(DB::table('system_events')->count())->toBe(1);
});
