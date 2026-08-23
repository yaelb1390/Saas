<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Models\Company;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\Social\Models\SocialWelcome;
use App\Modules\Social\Models\SocialWelcomeSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;

/*
 * La bienvenida a quien escribe por primera vez.
 *
 * QUÉ NO ES, y conviene que quede escrito donde se lee: esto NO saluda a quien te sigue. Instagram
 * no lo permite —seguir no abre la ventana de mensajería— y la API de Zernio no tiene ni un evento
 * de «nuevo seguidor». Esto contesta a quien YA escribió.
 *
 * Lo que se prueba aquí es lo que puede hacer daño: que nadie reciba dos mensajes seguidos —que es
 * justo lo que Instagram penaliza—, que nadie ajeno pueda disparar mensajes desde la cuenta de un
 * cliente, y que un fallo nuestro no deje a Zernio reintentando para siempre.
 */

uses(RefreshDatabase::class);

const CLAVE_BIENVENIDA = 'sk_'.'0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';

/** @return array{0: Company, 1: SocialWelcomeSetting} */
function empresaConBienvenida(string $nombre, string $correo, bool $encendida = true): array
{
    app(CurrentCompany::class)->forget();

    $company = app(CompanyService::class)->create(new CreateCompanyData(name: $nombre));
    $company->update(['modules' => ['social'], 'social_api_key' => CLAVE_BIENVENIDA]);

    app(CurrentCompany::class)->set($company->id);

    withRole(User::create([
        'company_id' => $company->id, 'name' => 'Duena', 'email' => $correo,
        'password' => 'secret-password',
    ]), 'owner');

    $ajustes = SocialWelcomeSetting::paraEmpresa((int) $company->id);
    $ajustes->update(['is_active' => $encendida, 'message' => 'Gracias por escribirnos!']);

    return [$company->fresh(), $ajustes->fresh()];
}

/** Un aviso de Zernio, firmado como lo firma Zernio. */
function avisar(SocialWelcomeSetting $ajustes, array $aviso, ?string $firma = null): TestResponse
{
    $cuerpo = json_encode($aviso, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    return test()->call(
        'POST',
        route('webhooks.social', $ajustes->token),
        [], [], [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_ZERNIO_SIGNATURE' => $firma ?? hash_hmac('sha256', $cuerpo, (string) $ajustes->secret),
        ],
        $cuerpo,
    );
}

function mensajeEntrante(string $conversacion = 'conv_1', string $plataforma = 'instagram'): array
{
    return [
        'id' => 'evt_1',
        'event' => 'message.received',
        'conversation' => [
            'id' => $conversacion,
            'platform' => $plataforma,
            'participantName' => 'Yasmely',
        ],
        'account' => ['id' => 'ig_1'],
        'message' => ['text' => 'hola, tienen batidas?'],
    ];
}

/**
 * Zernio contestando.
 *
 * Se llama en cada test y NO en el `beforeEach`: `Http::fake` casa en ORDEN DE DECLARACIÓN, así
 * que un falso puesto antes gana siempre y el test que necesita simular un fallo nunca lo vería.
 * Pasó: el test del envío fallido salía en verde con el código roto.
 */
function zernioContesta(int $estadoDelEnvio = 200): void
{
    Http::fake([
        '*/v1/inbox/conversations/*' => Http::response(
            $estadoDelEnvio === 200 ? ['ok' => true] : ['error' => 'boom'], $estadoDelEnvio,
        ),
        '*/v1/webhooks/settings*' => Http::response(['webhook' => ['_id' => 'wh_1']]),
    ]);
}

beforeEach(function (): void {
    [$this->company, $this->ajustes] = empresaConBienvenida('Batidera', 'duena@batidera.test');
});

// ------------------------------------------------------------------ Nadie recibe dos

it('saluda una vez, y solo una, aunque escriba tres veces seguidas', function (): void {
    zernioContesta();
    // Es EL test. Dos mensajes idénticos seguidos de un negocio es exactamente lo que Instagram
    // penaliza, y un cliente que escribe «hola», «tienen?», «hola?» es lo más normal del mundo.
    foreach (range(1, 3) as $ignorado) {
        avisar($this->ajustes, mensajeEntrante())->assertOk();
    }

    expect(SocialWelcome::query()->count())->toBe(1);

    Http::assertSentCount(1);
});

it('un reintento del mismo aviso no manda otro mensaje', function (): void {
    zernioContesta();
    // Zernio reintenta lo que falla. Sin la unicidad, un reintento sería un segundo mensaje.
    avisar($this->ajustes, mensajeEntrante())->assertOk();
    avisar($this->ajustes, mensajeEntrante())->assertOk();

    Http::assertSentCount(1);
});

it('a dos personas distintas sí las saluda a las dos', function (): void {
    zernioContesta();
    // El contraste: sin esto, «no manda dos» podría estar cumpliéndose por no mandar nunca.
    avisar($this->ajustes, mensajeEntrante('conv_1'))->assertOk();
    avisar($this->ajustes, mensajeEntrante('conv_2'))->assertOk();

    Http::assertSentCount(2);
});

// ------------------------------------------------------------------ Quién puede disparar

it('sin firma no se hace nada', function (): void {
    zernioContesta();
    // Sin esto, bastaría con conocer la dirección para hacer que le escribamos a quien sea desde la
    // cuenta de Instagram de un cliente.
    $cuerpo = json_encode(mensajeEntrante());

    $this->call('POST', route('webhooks.social', $this->ajustes->token), [], [], [],
        ['CONTENT_TYPE' => 'application/json'], $cuerpo)
        ->assertUnauthorized();

    Http::assertNothingSent();
});

it('con una firma que no cuadra tampoco', function (): void {
    zernioContesta();
    avisar($this->ajustes, mensajeEntrante(), 'sha256=meloinvento')->assertUnauthorized();

    Http::assertNothingSent();
});

it('un token que no existe responde lo mismo que una firma mala', function (): void {
    zernioContesta();
    // Mismo 401 a propósito: distinguirlos le diría a quien prueba direcciones cuándo ha acertado.
    $this->call('POST', route('webhooks.social', 'token_inventado'), [], [], [],
        ['CONTENT_TYPE' => 'application/json'], json_encode(mensajeEntrante()))
        ->assertUnauthorized();
});

it('el aviso de una empresa no dispara mensajes en otra', function (): void {
    zernioContesta();
    [, $otra] = empresaConBienvenida('Otra', 'duena@otra.test');

    avisar($otra, mensajeEntrante('conv_x'))->assertOk();

    expect(SocialWelcome::withoutGlobalScopes()->where('company_id', $this->company->id)->count())->toBe(0);
});

// ------------------------------------------------------------------ Qué se contesta y qué no

it('apagada no manda nada, pero el aviso se acepta', function (): void {
    zernioContesta();
    // Se responde 200 para que Zernio no lo tome por un fallo y se ponga a reintentar.
    $this->ajustes->update(['is_active' => false]);

    avisar($this->ajustes, mensajeEntrante())->assertOk();

    Http::assertNothingSent();
});

it('un mensaje de WhatsApp no dispara la bienvenida', function (): void {
    zernioContesta();
    // Ese módulo tiene su propia bandeja: dos sistemas contestando el mismo mensaje es peor que uno.
    avisar($this->ajustes, mensajeEntrante('conv_wa', 'whatsapp'))->assertOk();

    Http::assertNothingSent();
});

it('un mensaje nuestro no se saluda a sí mismo', function (): void {
    zernioContesta();
    $aviso = mensajeEntrante();
    $aviso['message']['isFromMe'] = true;

    avisar($this->ajustes, $aviso)->assertOk();

    Http::assertNothingSent();
});

// ------------------------------------------------------------------ Cuando algo falla

it('si Zernio rechaza el envío, no se devuelve 500 ni se da por saludado', function (): void {
    /*
     * Dos cosas a la vez, y las dos importan:
     *
     *  · 500 haría que Zernio reintentara para siempre, convirtiendo un fallo en un bucle.
     *  · Y si la marca se quedara puesta, esa persona no recibiría la bienvenida NUNCA, ni cuando el
     *    servicio vuelva.
     */
    zernioContesta(estadoDelEnvio: 500);

    avisar($this->ajustes, mensajeEntrante())->assertOk();

    expect(SocialWelcome::query()->count())->toBe(0);
});

// ------------------------------------------------------------------ Desde el panel

it('encender la bienvenida da de alta el webhook en Zernio', function (): void {
    /*
     * Con una dirección PÚBLICA, que es la única con la que esto funciona de verdad.
     *
     * Por omisión los tests corren en «localhost», y ahí el alta se rechaza a propósito: Zernio está
     * en internet y `localhost` es su propia máquina, no la nuestra. Registrarlo no da error y hace
     * que los mensajes se entreguen en otro sitio sin que nada lo avise.
     */
    URL::forceRootUrl('https://bmos.bm1390.cloud');

    zernioContesta();
    $this->ajustes->update(['is_active' => false]);

    $this->actingAs($this->company->ownerUser())
        ->put(route('panel.social.welcome'), [
            'message' => 'Gracias por escribirnos, dime que necesitas.',
            'is_active' => '1',
        ])->assertRedirect();

    Http::assertSent(fn ($request): bool => str_contains($request->url(), '/v1/webhooks/settings')
        && $request->method() === 'POST'
        && $request->data()['events'] === ['message.received']);

    expect($this->ajustes->fresh()->zernio_webhook_id)->toBe('wh_1');
});

it('guardar solo el texto no da de alta un webhook nuevo', function (): void {
    zernioContesta();
    // Sin esta comprobación, cada cambio de texto registraría otro webhook y el cliente acabaría
    // recibiendo una copia por cada uno.
    $this->ajustes->forceFill(['is_active' => true, 'zernio_webhook_id' => 'wh_1'])->save();

    $this->actingAs($this->company->ownerUser())
        ->put(route('panel.social.welcome'), ['message' => 'Otro texto', 'is_active' => '1'])
        ->assertRedirect();

    Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/v1/webhooks/settings')
        && $request->method() === 'POST');
});

it('con variaciones no sale siempre el mismo texto', function (): void {
    zernioContesta();
    $this->ajustes->update([
        'message' => 'Uno', 'variations' => ['Dos', 'Tres'],
    ]);

    $vistos = [];

    for ($i = 0; $i < 40; $i++) {
        $vistos[] = $this->ajustes->fresh()->textoParaEnviar();
    }

    expect(array_unique($vistos))->toHaveCount(3);
});

it('un cajero no puede tocar la bienvenida', function (): void {
    zernioContesta();
    $cajero = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Cajero',
        'email' => 'cajero@batidera.test', 'password' => 'secret-password',
    ]), 'staff');

    $this->actingAs($cajero)
        ->put(route('panel.social.welcome'), ['message' => 'Hola'])
        ->assertForbidden();
});

it('al borrar el webhook, el identificador viaja en la query y no en el cuerpo', function (): void {
    /*
     * La API lo exige en la query y rechaza el cuerpo con «missing_required_field: id».
     *
     * Se mandaba en el cuerpo, y como este borrado NO lanza a propósito —apagar la bienvenida tiene
     * que funcionar aunque Zernio no conteste—, el fallo era mudo: se limpiaba nuestro identificador
     * y el webhook seguía VIVO allí, disparando contra una dirección que ya no reconocía a nadie.
     * El webhook huérfano que el propio código decía estar evitando.
     */
    URL::forceRootUrl('https://bmos.bm1390.cloud');
    zernioContesta();

    $this->ajustes->forceFill(['is_active' => true, 'zernio_webhook_id' => 'wh_viejo'])->save();

    $this->actingAs($this->company->ownerUser())
        ->put(route('panel.social.welcome'), [
            'message' => 'Gracias por escribirnos, dime que necesitas.',
            'is_active' => '0',
        ])->assertRedirect();

    Http::assertSent(fn ($request): bool => $request->method() === 'DELETE'
        && str_contains($request->url(), '/v1/webhooks/settings?id=wh_viejo')
        && $request->data() === []);
});
