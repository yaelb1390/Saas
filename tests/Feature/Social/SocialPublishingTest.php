<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Models\Company;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Tenancy\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/*
 * Redes sociales.
 *
 * Publicar es hablar en nombre del negocio ante todo el mundo, y la clave que lo permite abre el
 * Instagram del cliente. Lo que se cubre aquí es eso: quién puede publicar, que la clave no quede
 * legible para nadie que abra la tabla, y que un fallo del servicio no deje la pantalla en blanco
 * haciendo creer que se perdió lo publicado.
 */

uses(RefreshDatabase::class);

const CLAVE = 'sk_'.'0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';

beforeEach(function (): void {
    $this->withoutVite();
    app(CurrentCompany::class)->forget();
    $this->company = app(CompanyService::class)->create(new CreateCompanyData(name: 'Batidera'));
    app(CurrentCompany::class)->set($this->company->id);
    $this->company->update(['modules' => ['social']]);

    $this->owner = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Dueña',
        'email' => 'duena@batidera.test', 'password' => 'secret-password',
    ]), 'owner');
});

/** Zernio respondiendo con una cuenta conectada y una publicación. */
function zernioResponde(): void
{
    // Los patrones llevan `*` al final a propósito: `Http::fake` compara la URL COMPLETA, y sin él
    // «/v1/posts?limit=20» no casa con «*/v1/posts» y la llamada se escapa a la red de verdad.
    //
    // Los NOMBRES DE CAMPO son los que devuelve la API de verdad, comprobados contra ella: la
    // documentación decía `name` y `avatar`, y son `displayName` y `profilePicture`. Copiar aquí lo
    // que dice un manual en vez de lo que manda el servidor convertiría estos tests en una
    // ceremonia que pasa siempre y no protege de nada.
    Http::fake([
        '*/v1/profiles*' => Http::response(['profiles' => [
            ['_id' => 'prof_1', 'name' => 'Default', 'isDefault' => true],
        ]]),
        '*/v1/accounts*' => Http::response(['accounts' => [
            [
                '_id' => 'acc_1', 'platform' => 'instagram',
                'displayName' => 'La Batidera', 'username' => 'labatidera',
                'profilePicture' => 'https://cdn/x.jpg', 'followersCount' => 2059,
                'needsReconnection' => false, 'isActive' => true, 'enabled' => true,
            ],
        ]]),
        '*/v1/posts*' => Http::response(['posts' => [
            ['_id' => 'post_1', 'status' => 'published', 'content' => 'Batida de guineo'],
        ]]),
        '*/v1/connect/*' => Http::response(['authUrl' => 'https://zernio.com/oauth/instagram?t=abc']),
        '*/v1/media/presign*' => Http::response(['uploadUrl' => 'https://up.zernio.com/x', 'publicUrl' => 'https://cdn.zernio.com/x.jpg']),
    ]);
}

// -------------------------------------------------------------------- La clave

it('la clave se guarda cifrada: no queda legible en la tabla', function (): void {
    // Quien la tenga puede publicar en el Instagram del cliente. Que un volcado de la base la
    // enseñe en claro sería regalar esa cuenta a cualquiera con acceso de lectura.
    $this->actingAs($this->owner)
        ->put(route('panel.social.key'), ['api_key' => CLAVE])
        ->assertSessionHasNoErrors();

    $enBruto = (string) DB::table('companies')->where('id', $this->company->id)->value('social_api_key');

    expect($enBruto)->not->toBe(CLAVE)
        ->and($enBruto)->not->toContain('sk_')
        // Y sigue leyéndose bien desde el modelo.
        ->and(Company::find($this->company->id)->social_api_key)->toBe(CLAVE);
});

it('rechaza algo que no parece una clave de Zernio', function (): void {
    // Un dedazo se convertiría en «no pudimos hablar con el servicio» diez minutos después, sin que
    // nadie relacione una cosa con la otra.
    $this->actingAs($this->owner)
        ->put(route('panel.social.key'), ['api_key' => 'esto-no-es-una-clave'])
        ->assertSessionHasErrors('api_key');

    expect(Company::find($this->company->id)->social_api_key)->toBeNull();
});

it('dejarla vacía desconecta las redes', function (): void {
    $this->company->update(['social_api_key' => CLAVE]);

    $this->actingAs($this->owner)->put(route('panel.social.key'), ['api_key' => '']);

    expect(Company::find($this->company->id)->publicaEnRedes())->toBeFalse();
});

// -------------------------------------------------------------------- La pantalla

it('sin clave, la pantalla explica cómo conectarse en vez de salir vacía', function (): void {
    $this->actingAs($this->owner)->get(route('panel.social'))
        ->assertOk()
        ->assertSee('Conecta tu cuenta de Zernio')
        ->assertSee('Clave de Zernio');
});

it('con clave, enseña las cuentas y lo publicado', function (): void {
    $this->company->update(['social_api_key' => CLAVE]);
    zernioResponde();

    $this->actingAs($this->owner)->get(route('panel.social'))
        ->assertOk()
        ->assertSee('La Batidera')
        ->assertSee('Batida de guineo')
        ->assertSee('Publicado');
});

it('si el servicio no responde, la pantalla se pinta igual y dice por qué', function (): void {
    // Dejarla en blanco —o peor, con un error 500— haría creer que se perdió lo publicado.
    $this->company->update(['social_api_key' => CLAVE]);
    Http::fake(['*' => Http::response(['detail' => 'caído'], 500)]);

    $this->actingAs($this->owner)->get(route('panel.social'))
        ->assertOk()
        ->assertSee('No pudimos hablar con el servicio');
});

// -------------------------------------------------------------------- Publicar

it('publica en las cuentas elegidas y manda el texto tal cual', function (): void {
    $this->company->update(['social_api_key' => CLAVE]);
    zernioResponde();

    $this->actingAs($this->owner)->post(route('panel.social.publish'), [
        'content' => 'Hoy hay batida de guineo',
        'accounts' => ['instagram|acc_1'],
        // Con foto: Instagram no admite texto suelto y sin ella este caso no llegaría a salir.
        'media_url' => 'https://cdn.zernio.com/foto.jpg', 'media_type' => 'image',
    ])->assertSessionHasNoErrors();

    Http::assertSent(function ($request): bool {
        if (! str_contains($request->url(), '/v1/posts') || $request->method() !== 'POST') {
            return false;
        }

        return $request->data()['content'] === 'Hoy hay batida de guineo'
            && $request->data()['platforms'] === [['platform' => 'instagram', 'accountId' => 'acc_1']]
            && ($request->data()['publishNow'] ?? false) === true;
    });
});

it('al programar viaja la zona horaria DEL NEGOCIO, no la del servidor', function (): void {
    /*
     * Sin ella, «mañana a las 8» se publicaría a las 4 de la madrugada de aquí, que es justo cuando
     * no lo ve nadie.
     *
     * Antes esto solo comprobaba que viajara ALGO —`filled(...)`—, y por eso pasaba mientras se
     * mandaba «UTC»: el dueño escribía las 6 de la tarde y la publicación salía a las 2. Comprobar
     * que un campo está relleno no comprueba que sea el correcto, y aquí la diferencia son cuatro
     * horas de la vida real.
     */
    $this->company->update(['social_api_key' => CLAVE]);
    zernioResponde();

    $cuando = now()->addDay()->format('Y-m-d\TH:i');

    $this->actingAs($this->owner)->post(route('panel.social.publish'), [
        'content' => 'Mañana abrimos a las 8',
        'accounts' => ['instagram|acc_1'],
        // Con foto: Instagram no admite texto suelto y sin ella este caso no llegaría a salir.
        'media_url' => 'https://cdn.zernio.com/foto.jpg', 'media_type' => 'image',
        'scheduled_for' => $cuando,
    ])->assertSessionHasNoErrors();

    Http::assertSent(fn ($request): bool => str_contains($request->url(), '/v1/posts')
        && $request->method() === 'POST'
        && ($request->data()['timezone'] ?? null) === 'America/Santo_Domingo'
        && ! isset($request->data()['publishNow']));
});

it('no deja programar en el pasado', function (): void {
    // Publicaría al instante sin avisar, que no es lo que nadie espera de un botón que dice
    // «programar».
    $this->company->update(['social_api_key' => CLAVE]);
    zernioResponde();

    $this->actingAs($this->owner)->post(route('panel.social.publish'), [
        'content' => 'Tarde',
        'accounts' => ['instagram|acc_1'],
        'scheduled_for' => now()->subHour()->format('Y-m-d\TH:i'),
    ])->assertSessionHasErrors('scheduled_for');
});

it('exige elegir al menos una red', function (): void {
    $this->company->update(['social_api_key' => CLAVE]);

    $this->actingAs($this->owner)
        ->post(route('panel.social.publish'), ['content' => 'Sin destino'])
        ->assertSessionHasErrors('accounts');
});

it('una plataforma que no reconocemos se descarta sin tumbar el resto', function (): void {
    // Mandarla haría fallar la publicación ENTERA, incluidas las cuentas que sí valían.
    $this->company->update(['social_api_key' => CLAVE]);
    zernioResponde();

    $this->actingAs($this->owner)->post(route('panel.social.publish'), [
        'content' => 'Hola',
        'accounts' => ['inventada|acc_9', 'instagram|acc_1'],
        // Con foto: Instagram no admite texto suelto y sin ella este caso no llegaría a salir.
        'media_url' => 'https://cdn.zernio.com/foto.jpg', 'media_type' => 'image',
    ])->assertSessionHasNoErrors();

    Http::assertSent(fn ($request): bool => str_contains($request->url(), '/v1/posts')
        && $request->method() === 'POST'
        && $request->data()['platforms'] === [['platform' => 'instagram', 'accountId' => 'acc_1']]);
});

it('si el servicio rechaza la publicación, se dice su motivo', function (): void {
    // «El vídeo excede la duración de TikTok» se puede arreglar; «no se pudo publicar» no.
    $this->company->update(['social_api_key' => CLAVE]);
    Http::fake(['*/v1/posts' => Http::response(['message' => 'El vídeo excede la duración de TikTok'], 422)]);

    $this->actingAs($this->owner)->post(route('panel.social.publish'), [
        'content' => 'Vídeo largo',
        'accounts' => ['instagram|acc_1'],
        // Con foto: Instagram no admite texto suelto y sin ella este caso no llegaría a salir.
        'media_url' => 'https://cdn.zernio.com/foto.jpg', 'media_type' => 'image',
    ]);

    expect(session('panel_error'))->toContain('excede la duración de TikTok');
});

// -------------------------------------------------------------------- Conectar

it('conectar una red lleva a autorizarla en la propia red', function (): void {
    $this->company->update(['social_api_key' => CLAVE]);
    zernioResponde();

    $this->actingAs($this->owner)
        ->post(route('panel.social.connect'), ['platform' => 'instagram'])
        ->assertRedirect('https://zernio.com/oauth/instagram?t=abc');
});

it('no acepta conectar una red que no ofrecemos', function (): void {
    $this->company->update(['social_api_key' => CLAVE]);

    $this->actingAs($this->owner)
        ->post(route('panel.social.connect'), ['platform' => 'inventada'])
        ->assertSessionHasErrors('platform');
});

// -------------------------------------------------------------------- Quién puede qué

it('un cajero no entra a redes sociales', function (): void {
    $cajero = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Cajero',
        'email' => 'cajero@batidera.test', 'password' => 'secret-password',
    ]), 'staff');

    $this->actingAs($cajero)->get(route('panel.social'))->assertForbidden();
    $this->actingAs($cajero)->post(route('panel.social.publish'), [
        'content' => 'Hola', 'accounts' => ['instagram|acc_1'],
    ])->assertForbidden();

    // Cancelar es la vuelta atrás de publicar: quien no puede lo uno tampoco puede lo otro.
    $this->actingAs($cajero)->delete(route('panel.social.posts.cancel', 'post_prog'))->assertForbidden();
});

it('sin el módulo contratado no hay pantalla', function (): void {
    $this->company->update(['modules' => ['pos']]);

    $this->actingAs($this->owner)->get(route('panel.social'))->assertForbidden();
});

it('la clave de una empresa no sirve para publicar por otra', function (): void {
    // Cada empresa trae su propia clave, y esa clave ES su aislamiento: si se cruzaran, un cliente
    // publicaría en el Instagram de otro.
    $otra = app(CompanyService::class)->create(new CreateCompanyData(name: 'Otra'));
    $otra->update(['modules' => ['social'], 'social_api_key' => CLAVE]);

    $this->company->update(['social_api_key' => 'sk_'.str_repeat('a', 64)]);
    zernioResponde();

    $this->actingAs($this->owner)->post(route('panel.social.publish'), [
        'content' => 'Mía',
        'accounts' => ['instagram|acc_1'],
        // Con foto: Instagram no admite texto suelto y sin ella este caso no llegaría a salir.
        'media_url' => 'https://cdn.zernio.com/foto.jpg', 'media_type' => 'image',
    ]);

    // Se usa la clave de LA EMPRESA ACTIVA, no la de la otra.
    Http::assertSent(fn ($request): bool => $request->hasHeader('Authorization', 'Bearer sk_'.str_repeat('a', 64)));
});

// ------------------------------------------------------------ Lo que dijo la API real, no el manual

it('lee el nombre y la foto de los campos que la API usa de verdad', function (): void {
    // La documentación decía `name` y `avatar`. La API devuelve `displayName` y `profilePicture`, y
    // se comprobó contra ella: con los del manual, la pantalla enseñaba «Sin nombre» y sin foto.
    $this->company->update(['social_api_key' => CLAVE]);
    zernioResponde();

    // El número y la palabra van en elementos separados desde que la tarjeta los destaca a un lado,
    // así que se comprueban por separado: lo que importa es que el dato salga, no cómo se maqueta.
    $this->actingAs($this->owner)->get(route('panel.social'))
        ->assertOk()
        ->assertSee('La Batidera')
        ->assertSee('2,059')
        ->assertSee('seguidores');
});

it('conectar una red manda el perfil, que la API exige', function (): void {
    // Sin `profileId` responde «missing_required_field» y no se conecta nada. El manual lo daba por
    // opcional; la API no.
    $this->company->update(['social_api_key' => CLAVE]);
    zernioResponde();

    $this->actingAs($this->owner)->post(route('panel.social.connect'), ['platform' => 'instagram']);

    Http::assertSent(fn ($request): bool => str_contains($request->url(), '/v1/connect/')
        && str_contains($request->url(), 'profileId=prof_1'));
});

it('avisa de una cuenta caducada en vez de dejar que se publique al vacío', function (): void {
    // Una cuenta que necesita reconexión NO da error al publicar: se traga el post y no sale nada.
    // El dueño lo descubriría días después preguntándose por qué nadie vio su oferta.
    $this->company->update(['social_api_key' => CLAVE]);
    Http::fake([
        '*/v1/profiles*' => Http::response(['profiles' => [['_id' => 'prof_1', 'isDefault' => true]]]),
        '*/v1/accounts*' => Http::response(['accounts' => [
            ['_id' => 'acc_1', 'platform' => 'instagram', 'displayName' => 'Caducada', 'needsReconnection' => true],
        ]]),
        '*/v1/posts*' => Http::response(['posts' => []]),
    ]);

    $html = $this->actingAs($this->owner)->get(route('panel.social'))->assertOk()->getContent();

    expect($html)->toContain('Hay que volver a conectarla')
        // Y no se puede elegir para publicar: sería un «publicado» que no publica.
        ->and($html)->toContain('disabled');
});

// -------------------------------------------------------------------- Lo que la red no admite

it('Instagram no publica texto suelto, y se dice antes de intentarlo', function (): void {
    /*
     * Comprobado contra la API: publicar «HOLA» en Instagram devuelve 400 «Instagram posts require
     * media content». El viaje solo sirve para volver con un rechazo que el dueño no puede
     * interpretar, así que se corta aquí y se le dice qué le falta.
     */
    $this->company->update(['social_api_key' => CLAVE]);
    zernioResponde();

    $this->actingAs($this->owner)
        ->post(route('panel.social.publish'), ['content' => 'HOLA', 'accounts' => ['instagram|ig_1']])
        ->assertSessionHas('panel_error', 'Instagram no publica solo texto: añade una foto o un vídeo.');

    Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/v1/posts'));
});

it('con foto sí sale', function (): void {
    $this->company->update(['social_api_key' => CLAVE]);
    zernioResponde();

    $this->actingAs($this->owner)->post(route('panel.social.publish'), [
        'content' => 'HOLA',
        'accounts' => ['instagram|ig_1'],
        'media_url' => 'https://cdn.zernio.com/foto.jpg',
        'media_type' => 'image',
    ])->assertSessionHas('panel_ok');
});

it('Facebook sí admite texto suelto', function (): void {
    // No todas las redes son de imagen: bloquearlas todas quitaría lo que sí funciona.
    $this->company->update(['social_api_key' => CLAVE]);
    zernioResponde();

    $this->actingAs($this->owner)
        ->post(route('panel.social.publish'), ['content' => 'HOLA', 'accounts' => ['facebook|fb_1']])
        ->assertSessionHas('panel_ok');
});

it('nombra TODAS las redes que exigen foto, no solo la primera', function (): void {
    $this->company->update(['social_api_key' => CLAVE]);
    zernioResponde();

    $this->actingAs($this->owner)->post(route('panel.social.publish'), [
        'content' => 'HOLA', 'accounts' => ['instagram|ig_1', 'tiktok|tk_1'],
    ])->assertSessionHas('panel_error', 'Instagram y TikTok no publican solo texto: añade una foto o un vídeo.');
});

it('pide permiso de subida con el nombre de campo que la API exige', function (): void {
    /*
     * `filename` TODO EN MINÚSCULA. Con `fileName` la API responde 400 «missing_required_field,
     * param: filename» y adjuntar una foto fallaba SIEMPRE, que a su vez dejaba a Instagram sin
     * ninguna forma de publicar. Comprobado contra el servidor de verdad.
     */
    $this->company->update(['social_api_key' => CLAVE]);
    Http::fake(['*/v1/media/presign' => Http::response([
        'uploadUrl' => 'https://sube.zernio.com/x', 'publicUrl' => 'https://cdn.zernio.com/x.jpg',
    ])]);

    $this->actingAs($this->owner)->postJson(route('panel.social.presign'), [
        'file_name' => 'batida.jpg', 'content_type' => 'image/jpeg',
    ])->assertOk();

    Http::assertSent(fn ($request): bool => array_key_exists('filename', $request->data())
        && $request->data()['filename'] === 'batida.jpg'
        && ! array_key_exists('fileName', $request->data()));
});

// -------------------------------------------------------------------- Cancelar lo programado

/** Zernio con una publicación programada, además de la ya publicada. */
function zernioConProgramada(): void
{
    Http::fake([
        '*/v1/profiles*' => Http::response(['profiles' => [['_id' => 'prof_1', 'name' => 'Default', 'isDefault' => true]]]),
        '*/v1/accounts*' => Http::response(['accounts' => []]),
        '*/v1/posts/*' => Http::response(['message' => 'Post deleted successfully']),
        '*/v1/posts*' => Http::response(['posts' => [
            [
                '_id' => 'post_prog',
                'status' => 'scheduled',
                'content' => 'Sale esta tarde',
                // Diez de la noche en UTC son las seis de la tarde aquí.
                'scheduledFor' => '2099-01-15T22:00:00.000Z',
            ],
            ['_id' => 'post_pub', 'status' => 'published', 'content' => 'Ya salió'],
        ]]),
    ]);
}

it('cancela una publicación programada', function (): void {
    $this->company->update(['social_api_key' => CLAVE]);
    zernioConProgramada();

    $this->actingAs($this->owner)
        ->delete(route('panel.social.posts.cancel', 'post_prog'))
        ->assertRedirect();

    Http::assertSent(fn ($request): bool => $request->method() === 'DELETE'
        && str_ends_with($request->url(), '/v1/posts/post_prog'));
});

it('el botón de cancelar solo sale en lo que todavía no ha salido', function (): void {
    /*
     * Zernio rechaza borrar una publicada, así que ofrecer el botón ahí sería prometer algo que
     * falla. Retirar algo que ya vio la gente es otra acción, con otras consecuencias.
     */
    $this->company->update(['social_api_key' => CLAVE]);
    zernioConProgramada();

    $html = $this->actingAs($this->owner)->get(route('panel.social'))->assertOk()->getContent();

    expect($html)->toContain(route('panel.social.posts.cancel', 'post_prog'))
        ->and($html)->not->toContain(route('panel.social.posts.cancel', 'post_pub'));
});

it('la hora de salida se enseña en la hora del negocio, no en UTC', function (): void {
    /*
     * Quien viene a decidir si para una publicación necesita saber a qué hora sale. En UTC leería
     * las diez de la noche una que sale a las seis de la tarde, y decidiría con una hora que no es.
     */
    $this->company->update(['social_api_key' => CLAVE]);
    zernioConProgramada();

    $this->actingAs($this->owner)
        ->get(route('panel.social'))
        ->assertOk()
        ->assertSee('a las 18:00')
        ->assertDontSee('a las 22:00');
});
