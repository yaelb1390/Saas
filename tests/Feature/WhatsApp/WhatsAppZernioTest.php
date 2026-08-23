<?php

declare(strict_types=1);

use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Models\Company;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\Social\Models\SocialWelcome;
use App\Modules\Social\Models\SocialWelcomeSetting;
use App\Modules\Social\Services\ZernioWebhookRegistrar;
use App\Modules\WhatsApp\Gateways\EvolutionGateway;
use App\Modules\WhatsApp\Gateways\WhatsAppGateway;
use App\Modules\WhatsApp\Gateways\ZernioWhatsAppGateway;
use App\Modules\WhatsApp\Jobs\SendWhatsAppMessage;
use App\Modules\WhatsApp\Models\WaBotSetting;
use App\Modules\WhatsApp\Models\WaConversation;
use App\Modules\WhatsApp\Models\WaMessage;
use App\Modules\WhatsApp\Services\WhatsAppService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

/*
 * WhatsApp por la vía oficial de Meta (Zernio).
 *
 * Lo que se fija aquí es el REPARTO y el AISLAMIENTO, que es donde esto se puede romper de formas
 * que nadie notaría hasta que un cliente se queje:
 *
 *   · un mensaje de WhatsApp no puede acabar contestado por la bienvenida de Instagram, ni al revés,
 *   · apagar la bienvenida no puede dejar al bot de WhatsApp sin recibir nada,
 *   · elegida una vía, la otra no se llama,
 *   · y no se inventa un envío cuando no hay conversación que contestar.
 */

uses(RefreshDatabase::class);

/** Una empresa con clave de Zernio. */
function empresaConZernio(string $nombre): Company
{
    app(CurrentCompany::class)->forget();
    $company = app(CompanyService::class)->create(new CreateCompanyData(name: $nombre));
    $company->forceFill(['social_api_key' => 'sk_de_prueba'])->save();
    app(CurrentCompany::class)->set($company->id);

    return $company->fresh();
}

/**
 * El aviso tal y como lo manda Zernio, con la forma que declara su especificación.
 *
 * Los nombres NO son inventados: se leyeron del OpenAPI real. Importa sobre todo que `direction` sea
 * `incoming` —y no `inbound`— y que el remitente pueda venir sin teléfono.
 *
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function avisoZernio(string $plataforma, ?string $texto, array $extra = []): array
{
    return array_replace_recursive([
        'id' => 'evt_1',
        'event' => 'message.received',
        'message' => [
            'id' => 'msg_1',
            'conversationId' => 'conv_abc',
            'platform' => $plataforma,
            'platformMessageId' => 'wamid.XYZ',
            'direction' => 'incoming',
            'text' => $texto,
            'attachments' => [],
            'sender' => [
                'id' => '18095551234',
                'name' => 'Ana',
                'phoneNumber' => '+18095551234',
            ],
            'sentAt' => '2026-08-22T10:00:00Z',
            'isRead' => false,
        ],
        'conversation' => ['id' => 'conv_abc', 'platform' => $plataforma, 'participantName' => 'Ana'],
        'account' => ['id' => 'acc_1'],
        'timestamp' => '2026-08-22T10:00:00Z',
    ], $extra);
}

/**
 * Manda el aviso FIRMADO, como haría Zernio.
 *
 * La firma va sobre el cuerpo crudo, así que el JSON se serializa una vez y se manda ese mismo
 * texto: dejar que el framework lo vuelva a serializar cambiaría el orden de las claves y el HMAC
 * dejaría de cuadrar por motivos que no tienen nada que ver con lo que se está probando.
 *
 * @param  array<string, mixed>  $aviso
 */
function mandarAviso(SocialWelcomeSetting $ajustes, array $aviso): TestResponse
{
    $cuerpo = (string) json_encode($aviso, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    return test()->call(
        'POST',
        route('webhooks.social', $ajustes->token),
        [],
        [],
        [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_ZERNIO_SIGNATURE' => hash_hmac('sha256', $cuerpo, (string) $ajustes->secret),
        ],
        $cuerpo,
    );
}

beforeEach(function (): void {
    $this->company = empresaConZernio('Bot Oficial');
    $this->ajustes = SocialWelcomeSetting::paraEmpresa((int) $this->company->id);
});

// ---------------------------------------------------------------- El reparto

it('un mensaje de WhatsApp entra en la bandeja y NO dispara la bienvenida de Instagram', function (): void {
    // La bienvenida, encendida: así la única razón de que no conteste es la plataforma.
    $this->ajustes->forceFill(['is_active' => true])->save();
    Http::fake();

    mandarAviso($this->ajustes, avisoZernio('whatsapp', 'buenas, tienen delivery?'))->assertOk();

    $mensaje = WaMessage::withoutGlobalScopes()->first();

    expect($mensaje)->not->toBeNull()
        ->and($mensaje->body)->toBe('buenas, tienen delivery?');

    // La bienvenida no dejó marca: no se metió en una conversación que no es suya.
    expect(SocialWelcome::withoutGlobalScopes()->count())->toBe(0);
});

it('un mensaje de Instagram sigue yendo a la bienvenida y NO entra en la bandeja de WhatsApp', function (): void {
    $this->ajustes->forceFill(['is_active' => true])->save();
    Http::fake(['*' => Http::response([], 200)]);

    mandarAviso($this->ajustes, avisoZernio('instagram', 'hola'))->assertOk();

    expect(WaMessage::withoutGlobalScopes()->count())->toBe(0)
        ->and(SocialWelcome::withoutGlobalScopes()->count())->toBe(1);
});

it('guarda la conversación y la cuenta de Zernio, que es por donde se contesta', function (): void {
    Http::fake();

    mandarAviso($this->ajustes, avisoZernio('whatsapp', 'hola'))->assertOk();

    $conversacion = WaConversation::withoutGlobalScopes()->firstOrFail();

    expect($conversacion->external_conversation_id)->toBe('conv_abc')
        ->and($conversacion->external_account_id)->toBe('acc_1')
        // El «+» de E.164 se quita para que case con el formato del resto del módulo.
        ->and($conversacion->phone)->toBe('18095551234');
});

it('sin teléfono usa el identificador de Meta y no inventa un cliente del CRM', function (): void {
    /*
     * Desde abril de 2026 se puede escribir a un negocio con nombre de usuario y sin enseñar el
     * número. Si esto se cayera, esas conversaciones se perderían enteras.
     */
    Http::fake();

    mandarAviso($this->ajustes, avisoZernio('whatsapp', 'hola', [
        'message' => ['sender' => ['id' => 'BSUID-999', 'phoneNumber' => null]],
    ]))->assertOk();

    $conversacion = WaConversation::withoutGlobalScopes()->firstOrFail();

    expect($conversacion->phone)->toBe('BSUID-999')
        ->and($conversacion->customer_id)->toBeNull();
});

it('una foto sin texto no llena la bandeja de mensajes vacíos', function (): void {
    Http::fake();

    mandarAviso($this->ajustes, avisoZernio('whatsapp', null, [
        'message' => ['attachments' => [['type' => 'image', 'url' => 'https://x/y']]],
    ]))->assertOk();

    expect(WaMessage::withoutGlobalScopes()->count())->toBe(0);
});

// ---------------------------------------------------------------- El envío

it('contesta a la conversación de Zernio, con su cuenta', function (): void {
    Http::fake(['*' => Http::response(['ok' => true], 200)]);

    $conversacion = WaConversation::create(['phone' => '18095551234']);
    $conversacion->forceFill([
        'external_conversation_id' => 'conv_abc',
        'external_account_id' => 'acc_1',
    ])->save();

    (new ZernioWhatsAppGateway($this->company))->sendText('18095551234', 'Claro que sí');

    Http::assertSent(fn ($request): bool => str_contains($request->url(), '/v1/inbox/conversations/conv_abc/messages')
        && $request['accountId'] === 'acc_1'
        && $request['message'] === 'Claro que sí');
});

it('sin conversación previa NO se inventa un envío: se explica por qué no se puede', function (): void {
    /*
     * Meta exige que la ventana la abra el cliente. Fallar con un motivo legible es lo que permite
     * que la pantalla lo cuente en vez de enseñar un error técnico.
     */
    Http::fake();

    expect(fn () => (new ZernioWhatsAppGateway($this->company))->sendText('18099999999', 'Hola'))
        ->toThrow(RuntimeException::class, 'solo se puede responder a quien escribió primero');

    Http::assertNothingSent();
});

// ---------------------------------------------------------------- La vía elegida

it('elegida la vía oficial el gateway es el de Zernio, y elegido el QR el de Evolution', function (): void {
    config(['evolution.base_url' => 'http://evolution:8080']);

    WaBotSetting::paraEmpresa((int) $this->company->id)
        ->forceFill(['provider' => WaBotSetting::OFICIAL])->save();

    expect(app(WhatsAppGateway::class))->toBeInstanceOf(ZernioWhatsAppGateway::class);

    WaBotSetting::paraEmpresa((int) $this->company->id)
        ->forceFill(['provider' => WaBotSetting::POR_QR])->save();

    expect(app(WhatsAppGateway::class))->toBeInstanceOf(EvolutionGateway::class);
});

it('sin clave de Zernio no se usa la vía oficial aunque esté elegida', function (): void {
    // Si no cayera a la otra, la línea quedaría muerta sin decir por qué.
    config(['evolution.base_url' => 'http://evolution:8080']);

    $this->company->forceFill(['social_api_key' => null])->save();
    WaBotSetting::paraEmpresa((int) $this->company->id)
        ->forceFill(['provider' => WaBotSetting::OFICIAL])->save();

    expect(app(WhatsAppGateway::class))->toBeInstanceOf(EvolutionGateway::class);
});

// ---------------------------------------------------------------- El webhook compartido

it('apagar la bienvenida de Instagram NO deja al bot de WhatsApp sin recibir mensajes', function (): void {
    /*
     * El fallo que este trabajo vino a evitar: el webhook lo daba de alta la bienvenida y lo borraba
     * al apagarla. Con WhatsApp entrando por la misma dirección, apagar una cosa mataba la otra en
     * silencio, y nadie relacionaría jamás las dos.
     */
    $this->ajustes->forceFill(['is_active' => false, 'zernio_webhook_id' => 'wh_1'])->save();

    WaBotSetting::paraEmpresa((int) $this->company->id)
        ->forceFill(['provider' => WaBotSetting::OFICIAL, 'is_active' => true])->save();

    Http::fake();

    expect((new ZernioWebhookRegistrar($this->company))->haceFalta())->toBeTrue()
        ->and((new ZernioWebhookRegistrar($this->company))->sincronizar())->toBeTrue();

    // No se llamó a borrar nada, y el webhook sigue siendo el mismo.
    Http::assertNothingSent();
    expect($this->ajustes->fresh()->zernio_webhook_id)->toBe('wh_1');
});

it('sin nadie que lo necesite, el webhook se da de baja', function (): void {
    $this->ajustes->forceFill(['is_active' => false, 'zernio_webhook_id' => 'wh_1'])->save();
    Http::fake(['*' => Http::response([], 200)]);

    expect((new ZernioWebhookRegistrar($this->company))->sincronizar())->toBeFalse()
        ->and($this->ajustes->fresh()->zernio_webhook_id)->toBeNull();
});

it('el bot de WhatsApp por sí solo da de alta el webhook, aunque no se use Instagram', function (): void {
    $this->ajustes->forceFill(['is_active' => false, 'zernio_webhook_id' => null])->save();

    WaBotSetting::paraEmpresa((int) $this->company->id)
        ->forceFill(['provider' => WaBotSetting::OFICIAL, 'is_active' => true])->save();

    Http::fake(['*' => Http::response(['webhook' => ['_id' => 'wh_nuevo']], 200)]);

    expect((new ZernioWebhookRegistrar($this->company))->sincronizar())->toBeTrue()
        ->and($this->ajustes->fresh()->zernio_webhook_id)->toBe('wh_nuevo');
});

// ---------------------------------------------------------------- Aislamiento

it('el aviso de una empresa nunca escribe con la cuenta de otra', function (): void {
    $otra = empresaConZernio('Ajena');
    $ajustesOtra = SocialWelcomeSetting::paraEmpresa((int) $otra->id);

    app(CurrentCompany::class)->set($this->company->id);
    Http::fake();

    // Llega por la dirección de la OTRA empresa.
    mandarAviso($ajustesOtra, avisoZernio('whatsapp', 'hola'))->assertOk();

    $mensaje = WaMessage::withoutGlobalScopes()->firstOrFail();

    expect($mensaje->company_id)->toBe($otra->id)
        ->and($mensaje->company_id)->not->toBe($this->company->id);
});

it('el envío encolado sale por la vía de SU empresa, no por la que hubiera puesta', function (): void {
    /*
     * El fallo estaba en el ORDEN.
     *
     * El trabajo recibía el gateway por inyección en la firma, y Laravel resuelve las dependencias
     * antes de ejecutar el cuerpo: se construía con la empresa que hubiera activa —ninguna en un
     * worker recién arrancado, o la del trabajo anterior— y el `set()` llegaba tarde.
     *
     * Mientras la vía era una sola no se notaba. Desde que cada empresa elige la suya, los mensajes
     * de una empresa de la vía oficial salían por el emparejamiento por QR, o por el gateway de
     * registro —que no envía nada— sin un solo error.
     *
     * Por eso el test ARRANCA SIN EMPRESA ACTIVA: es la situación del worker, y con el orden viejo
     * es exactamente cuando se equivoca.
     */
    config(['evolution.base_url' => 'http://evolution:8080']);
    Http::fake(['*' => Http::response(['ok' => true], 200)]);

    WaBotSetting::paraEmpresa((int) $this->company->id)
        ->forceFill(['provider' => WaBotSetting::OFICIAL])->save();

    $conversacion = WaConversation::create(['phone' => '18095551234']);
    $conversacion->forceFill([
        'external_conversation_id' => 'conv_abc',
        'external_account_id' => 'acc_1',
    ])->save();

    $mensaje = app(WhatsAppService::class)->sendText('18095551234', 'Desde la cola');

    app(CurrentCompany::class)->forget();

    (new SendWhatsAppMessage($mensaje))->handle(app(CurrentCompany::class));

    // Salió por la bandeja de Zernio, y no por Evolution.
    Http::assertSent(fn ($request): bool => str_contains($request->url(), '/v1/inbox/conversations/conv_abc/messages'));
    Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'evolution:8080'));
});
