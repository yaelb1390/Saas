<?php

declare(strict_types=1);

use App\Modules\Core\Models\ErrorEvent;
use App\Modules\Core\Models\PolarWebhookEvent;
use App\Modules\Core\Models\SystemEvent;
use App\Modules\Core\Monitoring\Counters\MonitoringCounters;
use App\Modules\Core\Tenancy\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * Los contadores de la pantalla de monitoreo TIENEN que ser el total real, no lo que cupo en una
 * lista recortada para pintarse. Antes de la Fase 1b, la pantalla sumaba `->count()` de colecciones ya
 * limitadas a 15 errores y 10 webhooks: con 18 errores activos, el titular decía «15», y nadie podía
 * saber si esa cifra era el total o el recorte. Aquí se prueba con MÁS filas de las que cualquier
 * lista de la pantalla enseña, precisamente para que un recorte que vuelva a colarse falle aquí.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(CurrentCompany::class)->forget();
});

it('cuenta TODOS los errores activos, más de los que enseña cualquier lista', function (): void {
    /*
     * Dieciocho grupos activos: más que los quince que se enseñaban antes en el Resumen y más que los
     * veinticinco de una página de la pestaña Errores. Cada uno con una PALABRA distinta y no un número:
     * la huella normaliza los números sueltos a `{n}» —es lo que hace que «Timeout API /12345» y
     * «Timeout API /67890» sean el MISMO error—, así que dieciocho mensajes que solo cambiaran en el
     * dígito se habrían fundido en un solo grupo, y este test habría probado otra cosa sin darse cuenta.
     */
    $palabras = ['pera', 'manzana', 'limon', 'uva', 'mango', 'kiwi', 'coco', 'guayaba', 'lechosa', 'nispero',
        'tamarindo', 'zapote', 'guanabana', 'cereza', 'ciruela', 'membrillo', 'higo', 'granada'];

    foreach ($palabras as $palabra) {
        ErrorEvent::anotar(new RuntimeException("fallo distinto: {$palabra}"), ['F.php:1'], null, null, null);
    }

    expect(ErrorEvent::query()->count())->toBe(18); // si esto falla, la huella se fundió: ver arriba.

    // Tres resueltos, que NO deben contar como activos.
    ErrorEvent::query()->latest('id')->limit(3)->get()->each(fn (ErrorEvent $e) => $e->update(['status' => ErrorEvent::RESUELTO]));

    expect(app(MonitoringCounters::class)->calcular()['errores_activos'])->toBe(15);
});

it('cuenta los webhooks de Polar pendientes: sin resolver Y los recibidos que se quedaron a medias', function (): void {
    // Trece «sin resolver»: más que los diez que se enseñan en el Resumen.
    for ($i = 1; $i <= 13; $i++) {
        PolarWebhookEvent::create(['event_id' => "evt_pend_{$i}", 'type' => 'subscription.active', 'result' => 'unresolved', 'payload' => []]);
    }

    // Uno «recibido» hace diez minutos: se quedó a medio procesar y nadie lo está mirando.
    $atascado = PolarWebhookEvent::create(['event_id' => 'evt_atascado', 'type' => 'order.paid', 'result' => 'received', 'payload' => []]);
    $atascado->forceFill(['created_at' => now()->subMinutes(10)])->save();

    // Uno «recibido» hace un minuto: normal, Polar todavía no ha tenido tiempo de que se procese.
    PolarWebhookEvent::create(['event_id' => 'evt_reciente', 'type' => 'order.paid', 'result' => 'received', 'payload' => []]);

    // Uno «aplicado»: ya se resolvió, no pide nada.
    PolarWebhookEvent::create(['event_id' => 'evt_aplicado', 'type' => 'order.paid', 'result' => 'applied', 'payload' => []]);

    expect(app(MonitoringCounters::class)->calcular()['webhooks_pendientes'])->toBe(14);
});

it('el titular no cuenta las empresas con problemas de negocio: eso es aparte', function (): void {
    // Sin nada roto en la plataforma, el titular es cero aunque haya avisos de negocio (probados en
    // CompanyHealthServiceTest / CompanyHealthTest): son preguntas distintas.
    $contadores = app(MonitoringCounters::class)->calcular();

    expect($contadores)->toHaveKey('empresas_con_problemas')
        ->and($contadores['incidentes_activos'])->toBeNull(); // sin Fase 2 todavía: «no se sabe», no «cero»
});

it('sin la tabla de errores, el contador sale en cero y no revienta', function (): void {
    Illuminate\Support\Facades\Schema::drop('error_events');
    App\Modules\Core\Support\DbTable::olvidar();

    expect(app(MonitoringCounters::class)->calcular()['errores_activos'])->toBe(0);
});

it('avisos y graves de las últimas 24 horas, sin contar los de antes', function (): void {
    SystemEvent::create(['type' => 'auth.failed', 'level' => SystemEvent::AVISO, 'message' => 'reciente']);
    SystemEvent::create(['type' => 'auth.login', 'level' => SystemEvent::INFO, 'message' => 'no cuenta: normal']);

    $viejo = SystemEvent::create(['type' => 'auth.failed', 'level' => SystemEvent::GRAVE, 'message' => 'viejo']);
    $viejo->forceFill(['created_at' => now()->subDays(2)])->save();

    expect(app(MonitoringCounters::class)->calcular()['avisos_graves_24h'])->toBe(1);
});
