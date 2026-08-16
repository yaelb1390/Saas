<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Tenancy\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

/*
 * El reporte de una respuesta automática.
 *
 * Lo que se cubre aquí son las tres formas que tiene esta pantalla de MENTIR, que son peores que no
 * existir porque el dueño actúa sobre ellas:
 *
 *   · Un porcentaje de clics calculado sobre el denominador equivocado.
 *   · Un «0 entregados» en Instagram, donde la red simplemente no informa.
 *   · Una gráfica dibujada sobre un registro truncado, que se lee como un desplome.
 */

uses(RefreshDatabase::class);

const CLAVE_REP = 'sk_'.'0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';

beforeEach(function (): void {
    $this->withoutVite();
    app(CurrentCompany::class)->forget();
    $this->company = app(CompanyService::class)->create(new CreateCompanyData(name: 'Batidera'));
    app(CurrentCompany::class)->set($this->company->id);
    $this->company->update(['modules' => ['social'], 'social_api_key' => CLAVE_REP]);

    $this->owner = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Dueña',
        'email' => 'duena@batidera.test', 'password' => 'secret-password',
    ]), 'owner');
});

// Zernio con una automatización y su registro.
//
// EL ORDEN IMPORTA. `Http::fake` casa por orden de declaración, y el patrón del registro tiene que ir
// ANTES que el comodín de las automatizaciones: si no, la llamada al registro casa con aquel,
// devuelve `['success' => true]` y el reporte sale vacío en un test que «pasa».
//
// Los nombres de campo son los del servidor, comprobados contra su OpenAPI.
function zernioConReporte(array $stats = [], array $logs = [], array $extra = [], string $platform = 'instagram'): void
{
    Http::fake(array_merge([
        '*/v1/comment-automations/*/logs*' => Http::response(array_merge([
            'logs' => $logs,
            'pagination' => ['total' => count($logs), 'hasMore' => false],
        ], $extra)),
        '*/v1/profiles*' => Http::response(['profiles' => [['_id' => 'prof_1', 'isDefault' => true]]]),
        '*/v1/comment-automations' => Http::response(['success' => true, 'automations' => [[
            'id' => 'auto_1', 'name' => 'Precio', 'platform' => $platform, 'accountId' => 'ig_1',
            'keywords' => ['precio'], 'dmMessage' => 'Hola', 'isActive' => true,
            'stats' => array_merge([
                'triggered' => 10, 'dmsSent' => 10, 'dmsFailed' => 0, 'uniqueContacts' => 9,
            ], $stats),
        ]]]),
        '*/v1/comment-automations/*' => Http::response(['success' => true]),
    ]));
}

// -------------------------------------------------------------- Los nombres de campo de verdad

it('enseña el nombre real de quien comentó, no «Alguien»', function (): void {
    // La versión anterior de esta pantalla buscaba `contactName` y `text`, que el servidor no manda:
    // todas las filas salían como «Alguien» y sin el comentario.
    zernioConReporte(logs: [[
        'id' => 'l1', 'commenterName' => 'María Pérez', 'commentText' => 'precio?',
        'status' => 'sent', 'createdAt' => '2026-08-14T15:00:00Z',
    ]]);

    $this->actingAs($this->owner)->get(route('panel.social.automations.reporte', 'auto_1'))
        ->assertOk()
        ->assertSee('María Pérez')
        ->assertSee('precio?')
        ->assertDontSee('Alguien');
});

// -------------------------------------------------------------- Los estados, en español

it('explica «En espera» en vez de soltar pending, y dice a qué hora escribe', function (): void {
    // «pending» no es un fallo: es una respuesta con espera que aún no le toca. Sin decirlo, se abre
    // un ticket de «no funciona» por algo que está funcionando.
    zernioConReporte(logs: [[
        'id' => 'l1', 'commenterName' => 'Ana', 'commentText' => 'precio',
        'status' => 'pending', 'nextDueAt' => '2026-08-14T18:32:00Z', 'createdAt' => '2026-08-14T18:29:00Z',
    ]]);

    $this->actingAs($this->owner)->get(route('panel.social.automations.reporte', 'auto_1'))
        ->assertOk()
        ->assertSee('En espera')
        ->assertSee('Todavía no le toca')
        ->assertDontSee('pending');
});

it('explica el estado de quien no ha confirmado que sigue', function (): void {
    zernioConReporte(logs: [[
        'id' => 'l1', 'commenterName' => 'Ana', 'status' => 'gated', 'createdAt' => '2026-08-14T18:29:00Z',
    ]]);

    $this->actingAs($this->owner)->get(route('panel.social.automations.reporte', 'auto_1'))
        ->assertOk()
        ->assertSee('Esperando que confirme')
        ->assertDontSee('gated');
});

it('el fallo de la respuesta pública se ve aparte del privado', function (): void {
    // Que salga el privado y falle el comentario es un fallo distinto, y hasta ahora invisible.
    zernioConReporte(logs: [[
        'id' => 'l1', 'commenterName' => 'Ana', 'status' => 'sent',
        'commentReplyStatus' => 'failed', 'commentReplyError' => 'El comentario fue borrado',
        'createdAt' => '2026-08-14T18:29:00Z',
    ]]);

    $this->actingAs($this->owner)->get(route('panel.social.automations.reporte', 'auto_1'))
        ->assertOk()
        ->assertSee('Respuesta pública falló')
        ->assertSee('El comentario fue borrado');
});

// -------------------------------------------------------------- Lo que se esconde para no mentir

it('el porcentaje de clics se calcula sobre los mensajes medibles, no sobre los enviados', function (): void {
    /*
     * Aviso explícito de la especificación. Con 100 enviados, 40 medibles y 10 clics la verdad es
     * 25 %; dividir entre los enviados daría 10 % y el dueño concluiría que su enlace no funciona.
     */
    zernioConReporte(['dmsSent' => 100, 'trackedSends' => 40, 'linkClicks' => 10, 'uniqueClicks' => 8]);

    $this->actingAs($this->owner)->get(route('panel.social.automations.reporte', 'auto_1'))
        ->assertOk()
        ->assertSee('25%')
        ->assertDontSee('10%');
});

it('sin enlace medible no hay bloque de clics ni división por cero', function (): void {
    // «0 %» afirmaría que nadie pulsó. Lo que pasa es que no había nada que contar.
    zernioConReporte(['dmsSent' => 50, 'trackedSends' => 0, 'linkClicks' => 0]);

    $this->actingAs($this->owner)->get(route('panel.social.automations.reporte', 'auto_1'))
        ->assertOk()
        ->assertSee('no lleva un enlace medible')
        ->assertDontSee('personas pulsaron el enlace');
});

it('«Entregados» no sale en Instagram, donde la red no informa', function (): void {
    // «0 entregados de 120 enviados» se lee como catástrofe, y solo es que IG no manda acuse.
    zernioConReporte(['dmsSent' => 120, 'delivered' => 0], platform: 'instagram');

    $this->actingAs($this->owner)->get(route('panel.social.automations.reporte', 'auto_1'))
        ->assertOk()
        ->assertDontSee('entregados');
});

it('y sí sale en Facebook cuando los hay', function (): void {
    zernioConReporte(['dmsSent' => 120, 'delivered' => 118], platform: 'facebook');

    $this->actingAs($this->owner)->get(route('panel.social.automations.reporte', 'auto_1'))
        ->assertOk()
        ->assertSee('entregados');
});

// -------------------------------------------------------------- Lo que se escapó

it('enseña los comentarios que no cazó ninguna palabra, con su ventana', function (): void {
    // Sin la ventana se lee como histórico y el dueño concluye que va casi perfecto.
    zernioConReporte(extra: ['misses' => [
        'total' => 3, 'retentionDays' => 7,
        'samples' => [['commentText' => 'cuanto vale?', 'commenterName' => 'Luis']],
    ]]);

    $this->actingAs($this->owner)->get(route('panel.social.automations.reporte', 'auto_1'))
        ->assertOk()
        ->assertSee('Comentarios que se te escaparon')
        ->assertSee('últimos 7 días')
        ->assertSee('cuanto vale?');
});

it('un comentario vetado por una regla dice que fue vetado, no que se escapó', function (): void {
    // Si no, el dueño añade una palabra que nunca va a casar y concluye que el producto está roto.
    zernioConReporte(extra: ['misses' => [
        'total' => 1, 'retentionDays' => 7,
        'samples' => [['commentText' => 'esto es una estafa', 'commenterName' => 'Troll', 'excludedBy' => 'estafa']],
    ]]);

    $this->actingAs($this->owner)->get(route('panel.social.automations.reporte', 'auto_1'))
        ->assertOk()
        ->assertSee('excluido por');
});

// -------------------------------------------------------------- La gráfica, con puerta

it('no dibuja la gráfica cuando el registro va truncado', function (): void {
    /*
     * Con más disparos de los que caben, el eje sería «los últimos días» y se leería como un
     * desplome que en realidad es el borde de la ventana.
     */
    zernioConReporte(
        logs: [['id' => 'l1', 'commenterName' => 'Ana', 'status' => 'sent', 'createdAt' => '2026-08-14T15:00:00Z']],
        extra: ['pagination' => ['total' => 912, 'hasMore' => true]],
    );

    $this->actingAs($this->owner)->get(route('panel.social.automations.reporte', 'auto_1'))
        ->assertOk()
        ->assertSee('de 912 disparos')
        ->assertDontSee('<canvas', false);
});

it('agrupa los disparos en la zona horaria del negocio', function (): void {
    /*
     * A UTC-4, un comentario de las 22:00 del 13 llega marcado como 02:00 UTC del 14. Agrupar en UTC
     * partiría una noche dominicana en dos barras.
     */
    config(['app.timezone' => 'America/Santo_Domingo']);

    zernioConReporte(logs: [
        // Las 22:00 del 13 en Santo Domingo. El segundo es solo para que haya dos días y se dibuje.
        ['id' => 'l1', 'commenterName' => 'Ana', 'status' => 'sent', 'createdAt' => '2026-08-14T02:00:00Z'],
        ['id' => 'l2', 'commenterName' => 'Luis', 'status' => 'sent', 'createdAt' => '2026-08-16T15:00:00Z'],
    ]);

    // Agrupando en UTC, el primero caería el 14 y el 13 no existiría en la serie.
    $this->actingAs($this->owner)->get(route('panel.social.automations.reporte', 'auto_1'))
        ->assertOk()
        ->assertSee('2026-08-13', false);
});

// -------------------------------------------------------------- Que no se caiga

it('la pantalla no revienta cuando Zernio no responde', function (): void {
    Http::fake(['*' => Http::response(['detail' => 'caído'], 500)]);

    $this->actingAs($this->owner)->get(route('panel.social.automations.reporte', 'auto_1'))
        ->assertRedirect();
});

it('una automatización que ya no existe redirige con su motivo', function (): void {
    zernioConReporte();

    $this->actingAs($this->owner)->get(route('panel.social.automations.reporte', 'no_existe'))
        ->assertRedirect(route('panel.social.automations'))
        ->assertSessionHas('panel_error');
});

it('un cajero no entra al reporte', function (): void {
    // Enseña a quién le está escribiendo el negocio: es la misma puerta que publicar.
    $cajero = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Cajero',
        'email' => 'cajero@batidera.test', 'password' => 'secret-password',
    ]), 'staff');

    $this->actingAs($cajero)->get(route('panel.social.automations.reporte', 'auto_1'))
        ->assertForbidden();
});
