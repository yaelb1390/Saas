<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\Social\Enums\KeywordMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

/*
 * Respuestas automáticas a comentarios.
 *
 * Esto habla con clientes reales SIN NADIE DELANTE: una palabra mal elegida contesta a quien no debía
 * y un enlace equivocado se manda cientos de veces antes de que alguien lo note. Lo que se cubre aquí
 * es eso, más las reglas que la API impone de verdad —comprobadas contra ella, no leídas en el manual—
 * para que el motivo se lea en el formulario y no en un fallo diez segundos después.
 */

uses(RefreshDatabase::class);

const CLAVE_AUTO = 'sk_'.'0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';

beforeEach(function (): void {
    $this->withoutVite();
    app(CurrentCompany::class)->forget();
    $this->company = app(CompanyService::class)->create(new CreateCompanyData(name: 'Batidera'));
    app(CurrentCompany::class)->set($this->company->id);
    $this->company->update(['modules' => ['social'], 'social_api_key' => CLAVE_AUTO]);

    $this->owner = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Dueña',
        'email' => 'duena@batidera.test', 'password' => 'secret-password',
    ]), 'owner');
});

/**
 * Zernio con una cuenta de Instagram, una de TikTok y una automatización.
 *
 * Los nombres de campo son los que devuelve el servidor de verdad, no los del manual: `displayName`,
 * `needsReconnection`, `stats.dmsSent`. Copiar la documentación fue lo que dejó pasar dos fallos en el
 * módulo anterior.
 */
function zernioConAutomatizaciones(array $automatizaciones = []): void
{
    Http::fake([
        '*/v1/profiles*' => Http::response(['profiles' => [['_id' => 'prof_1', 'isDefault' => true]]]),
        '*/v1/accounts*' => Http::response(['accounts' => [
            ['_id' => 'ig_1', 'platform' => 'instagram', 'displayName' => 'La Batidera', 'needsReconnection' => false],
            ['_id' => 'tk_1', 'platform' => 'tiktok', 'displayName' => 'La Batidera TikTok', 'needsReconnection' => false],
            ['_id' => 'ig_2', 'platform' => 'instagram', 'displayName' => 'Caducada', 'needsReconnection' => true],
        ]]),
        '*/v1/comment-automations/*' => Http::response(['success' => true]),
        '*/v1/comment-automations' => Http::response(['success' => true, 'automations' => $automatizaciones]),
    ]);
}

/** El cuerpo del formulario. */
function formularioAuto(array $extra = []): array
{
    return array_merge([
        'name' => 'Precio de las batidas',
        'account_id' => 'ig_1',
        'keywords' => 'precio, cuánto',
        'dm_message' => 'La batida está a RD$150 y te la llevamos.',
    ], $extra);
}

// -------------------------------------------------------------------- Qué cuentas se ofrecen

it('solo ofrece cuentas de Instagram y Facebook', function (): void {
    // TikTok no admite esto: una automatización sobre esa cuenta no se dispararía nunca, y el cliente
    // estaría esperando respuestas que no llegan.
    zernioConAutomatizaciones();

    $html = $this->actingAs($this->owner)->get(route('panel.social.automations'))->assertOk()->getContent();

    expect($html)->toContain('La Batidera')
        ->and($html)->not->toContain('La Batidera TikTok');
});

it('tampoco ofrece una cuenta que hay que reconectar', function (): void {
    // Se guardaría bien y no contestaría a nadie.
    zernioConAutomatizaciones();

    $html = $this->actingAs($this->owner)->get(route('panel.social.automations'))->assertOk()->getContent();

    expect($html)->not->toContain('Caducada');
});

it('sin ninguna cuenta que lo admita, lo explica en vez de dejar un formulario inútil', function (): void {
    Http::fake([
        '*/v1/profiles*' => Http::response(['profiles' => [['_id' => 'prof_1', 'isDefault' => true]]]),
        '*/v1/accounts*' => Http::response(['accounts' => [
            ['_id' => 'tk_1', 'platform' => 'tiktok', 'displayName' => 'Solo TikTok'],
        ]]),
        '*/v1/comment-automations*' => Http::response(['success' => true, 'automations' => []]),
    ]);

    $this->actingAs($this->owner)->get(route('panel.social.automations'))
        ->assertOk()
        ->assertSee('solo funcionan en');
});

// -------------------------------------------------------------------- Las reglas que impone la API

it('busca la palabra suelta por omisión, no dentro de otra palabra', function (): void {
    // El valor por omisión de la API es «contains», y con él «precio» contestaría a quien escriba
    // «aprecio». En una respuesta automática eso son cientos de mensajes al destinatario equivocado.
    zernioConAutomatizaciones();

    $this->actingAs($this->owner)->post(route('panel.social.automations.store'), formularioAuto());

    Http::assertSent(fn ($request): bool => ! str_ends_with($request->url(), '/v1/comment-automations')
        || $request->method() !== 'POST'
        || $request->data()['matchMode'] === KeywordMatch::Word->value);
});

it('«responder también por privado» exige palabras clave', function (): void {
    // Sin ninguna significaría «contesta a todos los mensajes que entren». La API lo rechaza; se corta
    // antes para que el motivo se lea en el formulario.
    zernioConAutomatizaciones();

    $this->actingAs($this->owner)
        ->post(route('panel.social.automations.store'), formularioAuto([
            'keywords' => '', 'also_in_dms' => '1',
        ]))
        ->assertSessionHasErrors('keywords');
});

it('con botón, el mensaje privado baja a 640 caracteres', function (): void {
    // Es el tope de Zernio. Sin comprobarlo aquí, el cliente escribe un mensaje largo y el servidor lo
    // rechaza al guardar, sin decirle que la culpa fue del botón.
    zernioConAutomatizaciones();

    $this->actingAs($this->owner)
        ->post(route('panel.social.automations.store'), formularioAuto([
            'dm_message' => str_repeat('a', 700),
            'button_title' => 'Pedir', 'button_url' => 'https://wa.me/1809',
        ]))
        ->assertSessionHasErrors('dm_message');
});

it('y sin botón admite hasta mil', function (): void {
    zernioConAutomatizaciones();

    $this->actingAs($this->owner)
        ->post(route('panel.social.automations.store'), formularioAuto(['dm_message' => str_repeat('a', 700)]))
        ->assertSessionHasNoErrors();
});

it('un botón sin enlace no se guarda', function (): void {
    // Un botón que no lleva a ningún sitio es peor que ninguno: el cliente lo pulsa y no pasa nada.
    zernioConAutomatizaciones();

    $this->actingAs($this->owner)
        ->post(route('panel.social.automations.store'), formularioAuto(['button_title' => 'Pedir']))
        ->assertSessionHasErrors('button_url');
});

// -------------------------------------------------------------------- Lo que se manda

it('manda el perfil, que la API exige', function (): void {
    // Ya mordió al conectar cuentas: el manual lo daba por opcional y la API responde
    // «missing_required_field».
    zernioConAutomatizaciones();

    $this->actingAs($this->owner)->post(route('panel.social.automations.store'), formularioAuto());

    Http::assertSent(fn ($request): bool => ! str_ends_with($request->url(), '/v1/comment-automations')
        || $request->method() !== 'POST'
        || ($request->data()['profileId'] ?? null) === 'prof_1');
});

it('parte las palabras por comas y les quita los huecos', function (): void {
    zernioConAutomatizaciones();

    $this->actingAs($this->owner)->post(route('panel.social.automations.store'), formularioAuto([
        'keywords' => ' precio ,  cuánto,,info ',
    ]));

    Http::assertSent(fn ($request): bool => ! str_ends_with($request->url(), '/v1/comment-automations')
        || $request->method() !== 'POST'
        || $request->data()['keywords'] === ['precio', 'cuánto', 'info']);
});

it('el botón viaja con su forma completa', function (): void {
    zernioConAutomatizaciones();

    $this->actingAs($this->owner)->post(route('panel.social.automations.store'), formularioAuto([
        'button_title' => 'Pedir ahora', 'button_url' => 'https://wa.me/18095551234',
    ]));

    Http::assertSent(fn ($request): bool => ! str_ends_with($request->url(), '/v1/comment-automations')
        || $request->method() !== 'POST'
        || $request->data()['buttons'] === [[
            'type' => 'url', 'title' => 'Pedir ahora', 'url' => 'https://wa.me/18095551234',
        ]]);
});

it('lo que no se ofrece en el formulario no se manda', function (): void {
    // La API acepta ausentes las variaciones, los retrasos y las palabras excluidas. Mandarlos vacíos
    // sería decirle que los queremos vacíos, que no es lo mismo.
    zernioConAutomatizaciones();

    $this->actingAs($this->owner)->post(route('panel.social.automations.store'), formularioAuto());

    Http::assertSent(function ($request): bool {
        if (! str_ends_with($request->url(), '/v1/comment-automations') || $request->method() !== 'POST') {
            return true;
        }

        $enviado = array_keys($request->data());

        return array_intersect($enviado, ['dmMessageVariations', 'dmDelaySeconds', 'excludeKeywords']) === [];
    });
});

// -------------------------------------------------------------------- Apagar, borrar, fallar

it('apagar no borra ni toca nada más', function (): void {
    // Quien ve que está contestando mal necesita un clic, no rellenar el formulario entero otra vez.
    zernioConAutomatizaciones();

    $this->actingAs($this->owner)
        ->post(route('panel.social.automations.toggle', 'auto_1'), ['is_active' => '0'])
        ->assertSessionHasNoErrors();

    Http::assertSent(fn ($request): bool => ! str_contains($request->url(), '/comment-automations/auto_1')
        || $request->method() !== 'PATCH'
        || $request->data() === ['isActive' => false]);
});

it('si Zernio rechaza el guardado, se dice su motivo', function (): void {
    Http::fake([
        '*/v1/profiles*' => Http::response(['profiles' => [['_id' => 'prof_1', 'isDefault' => true]]]),
        '*/v1/comment-automations' => Http::response(['message' => 'Esa cuenta no admite automatizaciones'], 422),
    ]);

    $this->actingAs($this->owner)->post(route('panel.social.automations.store'), formularioAuto());

    expect(session('panel_error'))->toContain('no admite automatizaciones');
});

it('si el servicio no responde, la pantalla se pinta con el motivo', function (): void {
    // Dejarla en blanco haría creer que se borraron las automatizaciones.
    Http::fake(['*' => Http::response(['detail' => 'caído'], 500)]);

    $this->actingAs($this->owner)->get(route('panel.social.automations'))
        ->assertOk()
        ->assertSee('No pudimos hablar con el servicio');
});

// -------------------------------------------------------------------- Quién puede

it('un cajero no puede crear ni apagar una respuesta automática', function (): void {
    // Publica en nombre del negocio, sin nadie delante y para siempre.
    $cajero = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Cajero',
        'email' => 'cajero@batidera.test', 'password' => 'secret-password',
    ]), 'staff');

    $this->actingAs($cajero)->get(route('panel.social.automations'))->assertForbidden();
    $this->actingAs($cajero)->post(route('panel.social.automations.store'), formularioAuto())->assertForbidden();
    $this->actingAs($cajero)->post(route('panel.social.automations.toggle', 'auto_1'))->assertForbidden();
});

it('sin el módulo contratado no hay pantalla', function (): void {
    $this->company->update(['modules' => ['pos']]);

    $this->actingAs($this->owner)->get(route('panel.social.automations'))->assertForbidden();
});

it('usa la clave de la empresa activa y no la de otra', function (): void {
    $otra = app(CompanyService::class)->create(new CreateCompanyData(name: 'Otra'));
    $otra->update(['modules' => ['social'], 'social_api_key' => 'sk_'.str_repeat('c', 64)]);

    zernioConAutomatizaciones();

    $this->actingAs($this->owner)->post(route('panel.social.automations.store'), formularioAuto());

    Http::assertSent(fn ($request): bool => $request->hasHeader('Authorization', 'Bearer '.CLAVE_AUTO));
});

// -------------------------------------------------------------------- Lo que lleva hecho

it('enseña cuántas veces se disparó y a cuánta gente', function (): void {
    // Es la única pregunta que importa: ¿esto sirve de algo?
    zernioConAutomatizaciones([[
        'id' => 'auto_1', 'name' => 'Precio', 'platform' => 'instagram', 'accountId' => 'ig_1',
        'keywords' => ['precio'], 'dmMessage' => 'Hola', 'isActive' => true,
        'stats' => ['triggered' => 42, 'dmsSent' => 40, 'dmsFailed' => 2, 'uniqueContacts' => 37],
    ]]);

    $this->actingAs($this->owner)->get(route('panel.social.automations'))
        ->assertOk()
        ->assertSee('42')->assertSee('40')->assertSee('37')
        ->assertSee('Encendida');
});

it('crear apagada la deja apagada de verdad, aunque Zernio la encienda', function (): void {
    /*
     * LA API IGNORA `isActive: false` AL CREAR. Comprobado contra ella: se mandó apagada y respondió
     * `isActive: true`, y la automatización quedó contestando de verdad en una cuenta real.
     *
     * Sin el segundo paso, quien desmarque «Encendida» creería que guardó algo dormido mientras su
     * negocio responde a los seguidores desde el primer comentario.
     */
    Http::fake([
        '*/v1/profiles*' => Http::response(['profiles' => [['_id' => 'prof_1', 'isDefault' => true]]]),
        '*/v1/accounts*' => Http::response(['accounts' => [
            ['_id' => 'ig_1', 'platform' => 'instagram', 'displayName' => 'La Batidera'],
        ]]),
        // Así responde la API de verdad: encendida, se pida lo que se pida.
        'https://api.zernio.com/v1/comment-automations' => Http::response([
            'success' => true,
            'automation' => ['id' => 'auto_9', 'isActive' => true],
        ]),
        '*/v1/comment-automations/*' => Http::response(['success' => true]),
    ]);

    $this->actingAs($this->owner)->post(route('panel.social.automations.store'), formularioAuto([
        // Sin `is_active`: el formulario manda la casilla solo cuando está marcada.
    ]))->assertSessionHasNoErrors();

    // Se corrige con un segundo paso, que es lo único que la API respeta.
    Http::assertSent(fn ($request): bool => str_contains($request->url(), '/comment-automations/auto_9')
        && $request->method() === 'PATCH'
        && $request->data() === ['isActive' => false]);
});

it('creada encendida no da el paso de más', function (): void {
    // Solo se corrige lo que hace falta: una llamada extra por cada alta sería ruido.
    zernioConAutomatizaciones();

    $this->actingAs($this->owner)->post(route('panel.social.automations.store'), formularioAuto(['is_active' => '1']));

    Http::assertNotSent(fn ($request): bool => $request->method() === 'PATCH');
});

// ------------------------------------------------------------ En qué publicación

it('por omisión vale para todas las publicaciones, también las futuras', function (): void {
    // Es lo que quiere la mayoría: la automatización sigue funcionando en las fotos de mañana. La API
    // lo entiende por AUSENCIA de los campos; mandarlos vacíos sería pedir «la publicación con
    // identificador vacío», que no es lo mismo.
    zernioConAutomatizaciones();

    $this->actingAs($this->owner)->post(route('panel.social.automations.store'), formularioAuto());

    Http::assertSent(function ($request): bool {
        if (! str_ends_with($request->url(), '/v1/comment-automations') || $request->method() !== 'POST') {
            return true;
        }

        return array_intersect(array_keys($request->data()), ['postId', 'platformPostId']) === [];
    });
});

it('al elegir una publicación manda los DOS identificadores', function (): void {
    // La API exige el suyo (`postId`) además del de la red (`platformPostId`): con uno solo no acota
    // nada y la automatización seguiría contestando en todas.
    zernioConAutomatizaciones();

    $this->actingAs($this->owner)->post(route('panel.social.automations.store'), formularioAuto([
        'post' => 'post_z1|17900000000000000',
    ]));

    Http::assertSent(fn ($request): bool => ! str_ends_with($request->url(), '/v1/comment-automations')
        || $request->method() !== 'POST'
        || ($request->data()['postId'] === 'post_z1' && $request->data()['platformPostId'] === '17900000000000000'));
});

it('una publicación a medias no acota nada', function (): void {
    // Con solo uno de los dos, la API lo ignoraría y la automatización contestaría en TODAS sin que
    // el dueño se entere. Mejor tratarlo como «en todas», que es lo que de verdad va a pasar.
    zernioConAutomatizaciones();

    $this->actingAs($this->owner)->post(route('panel.social.automations.store'), formularioAuto([
        'post' => 'post_z1',
    ]));

    Http::assertSent(function ($request): bool {
        if (! str_ends_with($request->url(), '/v1/comment-automations') || $request->method() !== 'POST') {
            return true;
        }

        return array_intersect(array_keys($request->data()), ['postId', 'platformPostId']) === [];
    });
});

it('solo ofrece las publicaciones de la cuenta elegida', function (): void {
    // Ofrecer las de otra cuenta dejaría elegir algo imposible, y la API lo rechazaría al guardar sin
    // decir por qué.
    Http::fake([
        '*/v1/profiles*' => Http::response(['profiles' => [['_id' => 'prof_1', 'isDefault' => true]]]),
        '*/v1/accounts*' => Http::response(['accounts' => [
            ['_id' => 'ig_1', 'platform' => 'instagram', 'displayName' => 'La Batidera'],
        ]]),
        '*/v1/posts*' => Http::response(['posts' => [[
            '_id' => 'post_z1', 'content' => 'Foto del combo',
            'platforms' => [['platform' => 'instagram', 'accountId' => ['_id' => 'ig_1'], 'platformPostId' => '179001']],
        ]]]),
        '*/v1/comment-automations*' => Http::response(['success' => true, 'automations' => []]),
    ]);

    $html = $this->actingAs($this->owner)->get(route('panel.social.automations'))->assertOk()->getContent();

    // La lista viaja al navegador con su cuenta, que es lo que permite filtrarla al cambiar de cuenta.
    expect($html)->toContain('Foto del combo')
        ->and($html)->toContain('179001')
        ->and($html)->toContain('En todas mis publicaciones');
});

it('descarta lo que aún no existe en la red', function (): void {
    // Un borrador o algo programado no tiene identificador de plataforma: todavía no hay foto que
    // comentar, así que ofrecerlo sería ofrecer algo que no existe.
    Http::fake([
        '*/v1/profiles*' => Http::response(['profiles' => [['_id' => 'prof_1', 'isDefault' => true]]]),
        '*/v1/accounts*' => Http::response(['accounts' => [
            ['_id' => 'ig_1', 'platform' => 'instagram', 'displayName' => 'La Batidera'],
        ]]),
        '*/v1/posts*' => Http::response(['posts' => [[
            '_id' => 'post_borrador', 'content' => 'Todavia sin publicar',
            'platforms' => [['platform' => 'instagram', 'accountId' => ['_id' => 'ig_1'], 'status' => 'scheduled']],
        ]]]),
        '*/v1/comment-automations*' => Http::response(['success' => true, 'automations' => []]),
    ]);

    $html = $this->actingAs($this->owner)->get(route('panel.social.automations'))->assertOk()->getContent();

    expect($html)->not->toContain('Todavia sin publicar');
});

it('buscar publicaciones las trae de la red, sin publicar nada', function (): void {
    // La mayoría de un negocio pequeño se sube desde el móvil: sin esto, «colgarla de esta foto» solo
    // funcionaría con las subidas desde el panel, que son las menos.
    Http::fake([
        '*/v1/profiles*' => Http::response(['profiles' => [['_id' => 'prof_1', 'isDefault' => true]]]),
        '*/v1/accounts*' => Http::response(['accounts' => [
            ['_id' => 'ig_1', 'platform' => 'instagram', 'displayName' => 'La Batidera'],
            ['_id' => 'tk_1', 'platform' => 'tiktok', 'displayName' => 'TikTok'],
        ]]),
        '*/v1/posts/sync-external' => Http::response(['synced' => ['postsFound' => 7]]),
        '*/v1/comment-automations*' => Http::response(['success' => true, 'automations' => []]),
    ]);

    $this->actingAs($this->owner)->post(route('panel.social.automations.sync'))->assertSessionHasNoErrors();

    expect(session('panel_ok'))->toContain('7');

    // Solo la cuenta que admite automatizaciones: pedirle publicaciones a TikTok es una llamada que
    // no puede servir para nada.
    Http::assertSent(fn ($request): bool => ! str_contains($request->url(), 'sync-external')
        || $request->data()['accountId'] === 'ig_1');
});

it('si la cuenta no tiene publicaciones, lo dice en vez de callar', function (): void {
    // Vacío no significa roto: puede que de verdad no haya subido nada todavía.
    Http::fake([
        '*/v1/accounts*' => Http::response(['accounts' => [
            ['_id' => 'ig_1', 'platform' => 'instagram', 'displayName' => 'La Batidera'],
        ]]),
        '*/v1/posts/sync-external' => Http::response(['synced' => ['postsFound' => 0]]),
    ]);

    $this->actingAs($this->owner)->post(route('panel.social.automations.sync'));

    expect(session('panel_ok'))->toContain('No encontramos publicaciones');
});

it('«solo a quien te siga» apagado se lee apagado', function (): void {
    /*
     * `filled(false)` vale TRUE en Laravel: solo el nulo y la cadena vacía cuentan como vacíos. Con
     * `filled()` la pantalla anunciaba «solo a quien te siga» en automatizaciones que contestaban a
     * cualquiera, que es justo al revés de lo que hacían.
     */
    zernioConAutomatizaciones([[
        'id' => 'auto_1', 'name' => 'Precio', 'platform' => 'instagram', 'accountId' => 'ig_1',
        'keywords' => ['precio'], 'dmMessage' => 'Hola', 'isActive' => true, 'followGate' => false,
    ]]);

    $this->actingAs($this->owner)->get(route('panel.social.automations'))
        ->assertOk()
        // El «·» acota la aserción a la línea de la tarjeta: el texto suelto sale también en
        // la casilla del formulario de alta, que está siempre.
        ->assertDontSee('· solo a quien te siga');
});

it('y encendido sí se anuncia', function (): void {
    zernioConAutomatizaciones([[
        'id' => 'auto_1', 'name' => 'Precio', 'platform' => 'instagram', 'accountId' => 'ig_1',
        'keywords' => ['precio'], 'dmMessage' => 'Hola', 'isActive' => true, 'followGate' => true,
    ]]);

    $this->actingAs($this->owner)->get(route('panel.social.automations'))
        ->assertOk()
        ->assertSee('· solo a quien te siga');
});

it('dice de qué red es cada automatización, con letra y no solo con color', function (): void {
    // El color de la franja no se lee en voz alta ni lo distingue quien no ve bien el rojo.
    zernioConAutomatizaciones([[
        'id' => 'auto_1', 'name' => 'Precio', 'platform' => 'facebook', 'accountId' => 'fb_1',
        'keywords' => ['precio'], 'dmMessage' => 'Hola', 'isActive' => true,
    ]]);

    $this->actingAs($this->owner)->get(route('panel.social.automations'))
        ->assertOk()
        ->assertSee('Facebook');
});

// ------------------------------------------------ Varias versiones, para que no la marquen de spam

it('manda las versiones alternativas con los nombres que la API usa', function (): void {
    /*
     * Un texto idéntico repetido cientos de veces es EXACTAMENTE lo que Instagram busca para
     * limitar una cuenta. Los nombres salen del OpenAPI de Zernio: `dmMessageVariations` y
     * `commentReplyVariations`, hasta cinco cada uno, sorteados por separado.
     */
    zernioConAutomatizaciones();

    $this->actingAs($this->owner)->post(route('panel.social.automations.store'), formularioAuto([
        'comment_reply' => '¡Te escribí!',
        'dm_variations' => ['Va a RD$150.', 'Cuesta RD$150 y te la llevamos.'],
        'reply_variations' => ['Ya te mandé el privado 😉'],
    ]));

    Http::assertSent(function ($request): bool {
        if (! str_ends_with($request->url(), '/v1/comment-automations') || $request->method() !== 'POST') {
            return true;
        }

        return $request->data()['dmMessageVariations'] === ['Va a RD$150.', 'Cuesta RD$150 y te la llevamos.']
            && $request->data()['commentReplyVariations'] === ['Ya te mandé el privado 😉'];
    });
});

it('las casillas que quedaron en blanco no viajan', function (): void {
    // Las versiones se añaden y se quitan en pantalla. Una cadena vacía haría que Zernio contestara
    // con un mensaje en blanco una de cada tres veces, que es peor que no variar.
    zernioConAutomatizaciones();

    $this->actingAs($this->owner)->post(route('panel.social.automations.store'), formularioAuto([
        'dm_variations' => ['Va a RD$150.', '', '   '],
    ]));

    Http::assertSent(function ($request): bool {
        if (! str_ends_with($request->url(), '/v1/comment-automations') || $request->method() !== 'POST') {
            return true;
        }

        return $request->data()['dmMessageVariations'] === ['Va a RD$150.'];
    });
});

it('sin ninguna versión no se manda el campo', function (): void {
    // Un array vacío le diría a Zernio «no quiero que varíe», que no es lo mismo que no opinar.
    zernioConAutomatizaciones();

    $this->actingAs($this->owner)->post(route('panel.social.automations.store'), formularioAuto([
        'dm_variations' => ['', ''],
    ]));

    Http::assertSent(function ($request): bool {
        if (! str_ends_with($request->url(), '/v1/comment-automations') || $request->method() !== 'POST') {
            return true;
        }

        return ! array_key_exists('dmMessageVariations', $request->data());
    });
});

it('no admite más de cinco versiones, que es el tope de la API', function (): void {
    // Una sexta hace que Zernio rechace la automatización ENTERA, no que ignore la sobrante.
    zernioConAutomatizaciones();

    $this->actingAs($this->owner)
        ->post(route('panel.social.automations.store'), formularioAuto([
            'dm_variations' => ['a', 'b', 'c', 'd', 'e', 'f'],
        ]))
        ->assertSessionHasErrors('dm_variations');
});

it('una versión del privado también respeta el tope de caracteres', function (): void {
    // Zernio manda una CUALQUIERA de las seis: si una se pasa, la automatización entera se cae.
    zernioConAutomatizaciones();

    $this->actingAs($this->owner)
        ->post(route('panel.social.automations.store'), formularioAuto([
            'button_title' => 'Pedir', 'button_url' => 'https://wa.me/18095551234',
            'dm_variations' => [str_repeat('a', 641)],
        ]))
        ->assertSessionHasErrors('dm_variations.0');
});

it('versiones de la respuesta pública sin la principal no se guardan', function (): void {
    // Rotan JUNTO a la principal, no en su lugar: sin ella quedaría media función encendida.
    zernioConAutomatizaciones();

    $this->actingAs($this->owner)
        ->post(route('panel.social.automations.store'), formularioAuto([
            'comment_reply' => '',
            'reply_variations' => ['Ya te escribí'],
        ]))
        ->assertSessionHasErrors('comment_reply');
});

it('al editar, las versiones que ya tenía se ven en el formulario', function (): void {
    // Sin esto, abrir una automatización y guardarla sin tocar nada le borraría las variaciones.
    zernioConAutomatizaciones([[
        'id' => 'auto_1', 'name' => 'Precio', 'platform' => 'instagram', 'accountId' => 'ig_1',
        'keywords' => ['precio'], 'dmMessage' => 'Hola', 'isActive' => true,
        // Sin acentos a proposito: @js los escapa a é y la asercion no los veria. Que
        // sobrevivan es cosa del navegador, y ahi se comprueba.
        'dmMessageVariations' => ['Va a RD$150 con delivery'],
        'commentReplyVariations' => ['Ya te mande el privado'],
    ]]);

    $this->actingAs($this->owner)->get(route('panel.social.automations'))
        ->assertOk()
        ->assertSee('Va a RD$150 con delivery', false)
        ->assertSee('Ya te mande el privado', false)
        // Y se dice cuántos rotan, para ver de un vistazo cuál es la que se gana el bloqueo.
        ->assertSee('Rotan 2 mensajes distintos');
});

it('avisa cuando una automatización manda siempre el mismo texto', function (): void {
    zernioConAutomatizaciones([[
        'id' => 'auto_1', 'name' => 'Precio', 'platform' => 'instagram', 'accountId' => 'ig_1',
        'keywords' => ['precio'], 'dmMessage' => 'Hola', 'isActive' => true,
    ]]);

    $this->actingAs($this->owner)->get(route('panel.social.automations'))
        ->assertOk()
        ->assertSee('Un solo mensaje, siempre igual');
});

// ---------------------------------------------------------------- La espera antes de contestar

it('manda la espera con el nombre que la API usa', function (): void {
    // Contestar en el mismo segundo, siempre, se reconoce tan fácil como el texto repetido.
    zernioConAutomatizaciones();

    $this->actingAs($this->owner)->post(route('panel.social.automations.store'), formularioAuto([
        'dm_delay' => 180,
    ]));

    Http::assertSent(function ($request): bool {
        if (! str_ends_with($request->url(), '/v1/comment-automations') || $request->method() !== 'POST') {
            return true;
        }

        return ($request->data()['dmDelaySeconds'] ?? null) === 180;
    });
});

it('«al instante» no manda el campo', function (): void {
    // Cero es el valor por omisión de la API: decirlo no aporta nada.
    zernioConAutomatizaciones();

    $this->actingAs($this->owner)->post(route('panel.social.automations.store'), formularioAuto([
        'dm_delay' => 0,
    ]));

    Http::assertSent(function ($request): bool {
        if (! str_ends_with($request->url(), '/v1/comment-automations') || $request->method() !== 'POST') {
            return true;
        }

        return ! array_key_exists('dmDelaySeconds', $request->data());
    });
});

it('no se puede pedir una espera de más de un día', function (): void {
    // El tope de 86400 lo impone la API; pasarlo rechazaría la automatización entera.
    zernioConAutomatizaciones();

    $this->actingAs($this->owner)
        ->post(route('panel.social.automations.store'), formularioAuto(['dm_delay' => 90000]))
        ->assertSessionHasErrors('dm_delay');
});

it('no se toca la espera de la respuesta pública', function (): void {
    /*
     * Zernio nunca publica la respuesta pública antes de mandar el privado: sube sola su espera
     * hasta la del privado. Un segundo control solo podría retrasarla MÁS, y nadie quiere eso.
     */
    zernioConAutomatizaciones();

    $this->actingAs($this->owner)->post(route('panel.social.automations.store'), formularioAuto([
        'dm_delay' => 180, 'comment_reply' => '¡Te escribí!',
    ]));

    Http::assertSent(function ($request): bool {
        if (! str_ends_with($request->url(), '/v1/comment-automations') || $request->method() !== 'POST') {
            return true;
        }

        return ! array_key_exists('commentReplyDelaySeconds', $request->data());
    });
});

it('la espera que ya tenía se ve en la lista y en el formulario', function (): void {
    // Sin verla, «lo monté y no contesta» sería un ticket cuando en realidad no le ha tocado.
    zernioConAutomatizaciones([[
        'id' => 'auto_1', 'name' => 'Precio', 'platform' => 'instagram', 'accountId' => 'ig_1',
        'keywords' => ['precio'], 'dmMessage' => 'Hola', 'isActive' => true,
        'dmDelaySeconds' => 180,
    ]]);

    $this->actingAs($this->owner)->get(route('panel.social.automations'))
        ->assertOk()
        ->assertSee('espera 3 min')
        ->assertSee('Unos 3 minutos');
});
