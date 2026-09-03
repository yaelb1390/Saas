<?php

declare(strict_types=1);

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

/*
 * Los endpoints de mantenimiento que dispara el cron de Vercel.
 *
 * En serverless no hay un proceso que viva entre peticiones, así que lo que en un servidor normal
 * haría el `scheduler` —y el trabajador de la cola— aquí lo tiene que provocar una llamada HTTP. Eso
 * significa que estas direcciones EJECUTAN trabajo real sin sesión de por medio: lo único que las
 * separa de cualquiera que las encuentre es el secreto compartido. Por eso lo primero que se prueba
 * de cada una es que sin el secreto no hacen nada.
 */

uses(RefreshDatabase::class);

/** Un trabajo de mentira que deja constancia de haberse ejecutado. */
final class TrabajoDePrueba implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        cache()->forever('trabajo-de-prueba', 'corrio');
    }
}

it('el drenaje de la cola exige el secreto', function () {
    config(['services.cron.secret' => 'topsecret']);

    $this->get('/tareas/drenar-cola')->assertForbidden();
    $this->withHeader('Authorization', 'Bearer incorrecto')->get('/tareas/drenar-cola')->assertForbidden();
});

it('la purga de registros exige el secreto', function () {
    config(['services.cron.secret' => 'topsecret']);

    $this->get('/tareas/purgar-registros')->assertForbidden();
    $this->withHeader('Authorization', 'Bearer incorrecto')->get('/tareas/purgar-registros')->assertForbidden();
});

it('sin secreto configurado, las tareas nuevas se bloquean', function () {
    config(['services.cron.secret' => null]);

    $this->withHeader('Authorization', 'Bearer loquesea')->get('/tareas/drenar-cola')->assertForbidden();
    $this->withHeader('Authorization', 'Bearer loquesea')->get('/tareas/purgar-registros')->assertForbidden();
});

it('la purga de registros borra de verdad los sucesos viejos', function () {
    config(['services.cron.secret' => 'topsecret']);

    DB::table('system_events')->insert([
        'type' => 'task.run',
        'level' => 'info',
        'message' => 'viejo',
        'created_at' => now()->subDays(200)->toDateTimeString(),
    ]);

    $this->withHeader('Authorization', 'Bearer topsecret')
        ->get('/tareas/purgar-registros')
        ->assertOk()
        ->assertJson(['ok' => true]);

    // El propio endpoint deja un suceso de que corrió, así que no se cuenta a cero: se comprueba que
    // el viejo ya no está.
    expect(DB::table('system_events')->where('message', 'viejo')->count())->toBe(0);
});

it('con la cola vacia el drenaje contesta sin hacer nada', function () {
    config(['services.cron.secret' => 'topsecret', 'queue.default' => 'database']);

    $this->withHeader('Authorization', 'Bearer topsecret')
        ->get('/tareas/drenar-cola')
        ->assertOk()
        ->assertJson(['ok' => true]);
});

/*
 * LA PRUEBA QUE JUSTIFICA EL ENDPOINT. Hoy la cola va en `sync` y los trabajos —mandar el WhatsApp,
 * contestar con IA, transcribir un audio— corren DENTRO de la petición, con el cliente esperando. El
 * día que se pase a `database`, lo único que hará que esos trabajos lleguen a ejecutarse es esta
 * dirección. Si deja de vaciar la cola, los mensajes no se envían y nadie se entera.
 */
it('el drenaje ejecuta los trabajos que estaban en cola', function () {
    config(['services.cron.secret' => 'topsecret', 'queue.default' => 'database']);
    cache()->forget('trabajo-de-prueba');

    TrabajoDePrueba::dispatch();
    expect(DB::table('jobs')->count())->toBe(1);

    $this->withHeader('Authorization', 'Bearer topsecret')->get('/tareas/drenar-cola')->assertOk();

    expect(cache()->get('trabajo-de-prueba'))->toBe('corrio')
        ->and(DB::table('jobs')->count())->toBe(0);
});
