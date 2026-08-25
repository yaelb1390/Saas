<?php

declare(strict_types=1);

use App\Modules\AI\Models\AiSetting;
use App\Modules\AI\Providers\Contracts\AiProvider;
use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\WhatsApp\Enums\MessageDirection;
use App\Modules\WhatsApp\Gateways\WhatsAppGateway;
use App\Modules\WhatsApp\Jobs\ResponderAlCliente;
use App\Modules\WhatsApp\Jobs\TranscribeVoiceNote;
use App\Modules\WhatsApp\Models\WaBotSetting;
use App\Modules\WhatsApp\Models\WaConversation;
use App\Modules\WhatsApp\Models\WaMessage;
use App\Modules\WhatsApp\Services\WhatsAppBot;
use App\Modules\WhatsApp\Support\MensajeEntrante;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

/*
 * Las notas de voz y las ráfagas de mensajes.
 *
 * El caso que motiva todo esto es un FALLO, no una mejora: hasta ahora el webhook solo sabía leer
 * texto, así que una nota de voz salía `null` y se DESCARTABA. No es que el bot no supiera
 * contestarla: el mensaje no llegaba a existir. Ni se guardaba, ni aparecía en la bandeja, ni nadie
 * se enteraba. Para el dueño era idéntico a que el cliente nunca hubiera escrito, y aquí media
 * clientela habla por audio.
 *
 * Por eso el primer test no comprueba que se transcriba bien —eso depende del modelo—, sino que el
 * mensaje EXISTE aunque la transcripción falle entera.
 */

uses(RefreshDatabase::class);

/** Lo que Evolution manda por el webhook, con el contenido que se le diga. */
function avisoDeEvolution(string $instancia, array $contenido, string $id = 'MID-VOZ'): array
{
    return [
        'event' => 'messages.upsert',
        'instance' => $instancia,
        'data' => [
            'key' => ['remoteJid' => '18095557777@s.whatsapp.net', 'fromMe' => false, 'id' => $id],
            'pushName' => 'Ramona',
            'message' => $contenido,
        ],
    ];
}

beforeEach(function (): void {
    app(CurrentCompany::class)->forget();
    $this->company = app(CompanyService::class)->create(new CreateCompanyData(name: 'Colmado Voz'));
    app(CurrentCompany::class)->set($this->company->id);
    config(['evolution.webhook_secret' => 'shh', 'evolution.base_url' => 'https://evo.local']);

    $this->gateway = new class implements WhatsAppGateway
    {
        /** @var array<int, array{0: string, 1: string}> */
        public array $enviados = [];

        public function sendText(string $phone, string $body): array
        {
            $this->enviados[] = [$phone, $body];

            return ['external_id' => 'ext-'.count($this->enviados), 'status' => 'sent'];
        }

        public function puedeEnviarDocumentos(): bool
        {
            return false;
        }

        public function sendDocument(string $phone, string $url, string $fileName, string $caption = ''): array
        {
            return ['external_id' => 'doc', 'status' => 'sent'];
        }
    };
    $this->app->instance(WhatsAppGateway::class, $this->gateway);
});

// ------------------------------------------------------------------ Que no se pierda nada

it('una nota de voz se guarda aunque la transcripción falle entera', function (): void {
    /*
     * EL TEST QUE JUSTIFICA TODO EL CAMBIO.
     *
     * Se hunde todo lo que hay debajo —Evolution no da el audio, no hay proveedor que transcriba— y
     * aun así el mensaje tiene que estar en la bandeja. Perder la transcripción es un fastidio;
     * perder el mensaje es perder al cliente sin enterarse.
     */
    Http::fake(['*' => Http::response([], 500)]);

    $this->postJson('/webhooks/evolution', avisoDeEvolution($this->company->slug, [
        'audioMessage' => ['mimetype' => 'audio/ogg; codecs=opus', 'ptt' => true],
    ]), ['apikey' => 'shh'])->assertOk();

    $mensaje = WaMessage::withoutGlobalScopes()->first();

    expect($mensaje)->not->toBeNull()
        ->and($mensaje->type)->toBe('audio')
        ->and($mensaje->body)->toBe(MensajeEntrante::ROTULOS['audio'])
        ->and($mensaje->direction)->toBe(MessageDirection::Inbound);
});

it('guarda la foto, el documento y la ubicación en vez de tirarlos', function (): void {
    /*
     * No es solo el audio: el `extractBody()` viejo tiraba TODO lo que no fuera texto. Una foto de un
     * producto —«¿tienen esta?»— es una venta perdida en silencio.
     */
    $this->postJson('/webhooks/evolution', avisoDeEvolution($this->company->slug, [
        'imageMessage' => ['caption' => '¿tienen esta?'],
    ], 'MID-FOTO'), ['apikey' => 'shh'])->assertOk();

    $this->postJson('/webhooks/evolution', avisoDeEvolution($this->company->slug, [
        'documentMessage' => ['fileName' => 'pedido.pdf'],
    ], 'MID-DOC'), ['apikey' => 'shh'])->assertOk();

    $this->postJson('/webhooks/evolution', avisoDeEvolution($this->company->slug, [
        'locationMessage' => ['degreesLatitude' => 19.45, 'degreesLongitude' => -70.7],
    ], 'MID-UBI'), ['apikey' => 'shh'])->assertOk();

    $mensajes = WaMessage::withoutGlobalScopes()->orderBy('id')->get();

    expect($mensajes)->toHaveCount(3)
        // La foto CON pie se guarda como el pie: eso es lo que preguntó el cliente.
        ->and($mensajes[0]->type)->toBe('image')
        ->and($mensajes[0]->body)->toBe('¿tienen esta?')
        // El documento conserva el nombre del archivo: es lo único que identifica de qué pedido habla.
        ->and($mensajes[1]->type)->toBe('document')
        ->and($mensajes[1]->body)->toContain('pedido.pdf')
        ->and($mensajes[2]->type)->toBe('location');
});

it('un acuse de recibo sigue sin guardarse: no es un mensaje de nadie', function (): void {
    /*
     * La otra cara del cambio. Si «guardar todo» se interpretara como guardar literalmente todo lo
     * que entra por el webhook, la bandeja se llenaría de ruido de protocolo y el dueño dejaría de
     * mirarla. La distinción entre «no hay mensaje» y «hay un mensaje que no sé leer» es el diseño.
     */
    $this->postJson('/webhooks/evolution', avisoDeEvolution($this->company->slug, [
        'protocolMessage' => ['type' => 'REVOKE'],
    ], 'MID-ACUSE'), ['apikey' => 'shh']);

    expect(WaMessage::withoutGlobalScopes()->count())->toBe(0);
});

it('sin proveedor que transcriba, el mensaje se queda y el bot NO contesta', function (): void {
    /*
     * Lo peligroso aquí sería contestar al rótulo. El bot recibiría «🎤 Nota de voz» como pregunta y
     * respondería cualquier cosa a algo que el cliente no dijo. Mejor callar y que lo lea una persona.
     */
    AiSetting::actual()->update(['provider' => 'local', 'api_key' => null]);
    app()->forgetInstance(AiProvider::class);

    WaBotSetting::paraEmpresa($this->company->id)
        ->fill(['is_active' => true, 'business_info' => 'Abrimos de 8 a 8.'])->save();

    Http::fake(['*' => Http::response([], 500)]);

    $this->postJson('/webhooks/evolution', avisoDeEvolution($this->company->slug, [
        'audioMessage' => ['mimetype' => 'audio/ogg'],
    ]), ['apikey' => 'shh'])->assertOk();

    expect($this->gateway->enviados)->toBeEmpty()
        ->and(WaMessage::withoutGlobalScopes()->where('body', MensajeEntrante::ROTULOS['audio'])->count())->toBe(1);
});

it('la transcripción REESCRIBE el mensaje que ya existe, no crea otro', function (): void {
    /*
     * Un segundo mensaje con la transcripción dejaría la conversación diciendo las cosas dos veces, y
     * el dueño sin saber cuál de las dos filas mandó su cliente. La bandeja tiene que reflejar la
     * conversación que hubo, no la que hubo más lo que el sistema hizo con ella.
     */
    Http::fake([
        '*getBase64FromMediaMessage*' => Http::response(['base64' => 'UklGRg==', 'mimetype' => 'audio/ogg']),
        '*:generateContent*' => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => 'Mándame dos cajas de agua']]]]],
        ]),
    ]);

    AiSetting::actual()->update(['provider' => 'gemini', 'api_key' => 'AIzaDePrueba1234567890']);
    app()->forgetInstance(AiProvider::class);

    $mensaje = WaMessage::withoutGlobalScopes()->create([
        'company_id' => $this->company->id,
        'wa_conversation_id' => conversacionDeVoz($this->company->id)->id,
        'direction' => MessageDirection::Inbound,
        'type' => 'audio',
        'body' => MensajeEntrante::ROTULOS['audio'],
        'status' => 'received',
        'external_id' => 'MID-VOZ',
        'sent_at' => now(),
    ]);

    (new TranscribeVoiceNote($mensaje))->handle(app(CurrentCompany::class));

    expect(WaMessage::withoutGlobalScopes()->count())->toBe(1)
        ->and($mensaje->fresh()->body)->toBe('Mándame dos cajas de agua')
        // Sigue marcado como audio: la bandeja avisa de que ese texto es una transcripción y pudo
        // entender mal, para que al dueño se le ocurra escuchar el original si algo no cuadra.
        ->and($mensaje->fresh()->type)->toBe('audio');
});

it('si no se entiende el audio, se deja el rótulo en vez de escribir un sinsentido', function (): void {
    /*
     * Escribir «(no se entiende)» en la bandeja sería PEOR que el rótulo: parece que el cliente dijo
     * eso. Con «🎤 Nota de voz» el dueño sabe que hay un audio y lo escucha él.
     */
    Http::fake([
        '*getBase64FromMediaMessage*' => Http::response(['base64' => 'UklGRg==', 'mimetype' => 'audio/ogg']),
        '*:generateContent*' => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => '(no se entiende)']]]]],
        ]),
    ]);

    AiSetting::actual()->update(['provider' => 'gemini', 'api_key' => 'AIzaDePrueba1234567890']);
    app()->forgetInstance(AiProvider::class);

    $mensaje = WaMessage::withoutGlobalScopes()->create([
        'company_id' => $this->company->id,
        'wa_conversation_id' => conversacionDeVoz($this->company->id)->id,
        'direction' => MessageDirection::Inbound,
        'type' => 'audio',
        'body' => MensajeEntrante::ROTULOS['audio'],
        'status' => 'received',
        'external_id' => 'MID-VOZ',
        'sent_at' => now(),
    ]);

    (new TranscribeVoiceNote($mensaje))->handle(app(CurrentCompany::class));

    expect($mensaje->fresh()->body)->toBe(MensajeEntrante::ROTULOS['audio']);
});

it('transcrita, el bot contesta a lo que DIJO el cliente y no al rótulo', function (): void {
    /*
     * El camino entero, de una punta a otra: llega el audio por el webhook, se transcribe, y la
     * respuesta se encola SOLA desde la transcripción.
     *
     * Que la respuesta la encole el trabajo de transcripción y no el oyente es lo que quita de en
     * medio una carrera: el oyente tendría que adivinar cuánto tarda el proveedor, y el día que
     * tardara un segundo de más el bot le contestaría al rótulo «🎤 Nota de voz» —y, al no
     * entenderlo, apartaría la conversación—. Una transcripción lenta dejaba el bot apagado con ese
     * cliente para siempre.
     */
    Http::fake([
        '*getBase64FromMediaMessage*' => Http::response(['base64' => 'UklGRg==', 'mimetype' => 'audio/ogg']),
        '*:generateContent*' => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => 'Necesito dos cajas de agua para hoy']]]]],
        ]),
    ]);

    AiSetting::actual()->update(['provider' => 'gemini', 'api_key' => 'AIzaDePrueba1234567890']);
    app()->forgetInstance(AiProvider::class);

    WaBotSetting::paraEmpresa($this->company->id)
        ->fill(['is_active' => true, 'business_info' => 'Vendemos agua. Delivery en Santiago.'])->save();

    $this->postJson('/webhooks/evolution', avisoDeEvolution($this->company->slug, [
        'audioMessage' => ['mimetype' => 'audio/ogg', 'ptt' => true],
    ]), ['apikey' => 'shh'])->assertOk();

    // El bot contestó, y la conversación sigue suya: nadie la apartó para un humano.
    expect($this->gateway->enviados)->toHaveCount(1)
        ->and(conversacionDeVoz($this->company->id)->fresh()->bot_paused_at)->toBeNull();

    // Y lo que se le preguntó al modelo es lo que dijo el cliente, no «🎤 Nota de voz».
    Http::assertSent(function ($peticion): bool {
        $cuerpo = json_encode($peticion->data(), JSON_UNESCAPED_UNICODE) ?: '';

        return str_contains($cuerpo, 'REGLAS QUE MANDAN')
            && str_contains($cuerpo, 'dos cajas de agua');
    });
});

it('un audio que no se pudo transcribir deja la conversación esperando a una persona', function (): void {
    /*
     * Sin esto, una nota de voz que no se pudo leer se queda en la bandeja callada y sin
     * distinguirse de las conversaciones ya atendidas: el dueño solo la vería si bajara a mirar por
     * casualidad. Marcada, sale con «Te espera» en la lista, que es donde se mira.
     *
     * Y NO se le manda nada al cliente a propósito: un «no te entendí» automático a quien acaba de
     * mandar un audio suele provocar OTRO audio para aclararse, que tampoco se va a poder transcribir.
     */
    Http::fake([
        '*getBase64FromMediaMessage*' => Http::response([], 500),
        '*:generateContent*' => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => 'lo que sea']]]]],
        ]),
    ]);

    AiSetting::actual()->update(['provider' => 'gemini', 'api_key' => 'AIzaDePrueba1234567890']);
    app()->forgetInstance(AiProvider::class);

    WaBotSetting::paraEmpresa($this->company->id)
        ->fill(['is_active' => true, 'business_info' => 'Vendemos agua.'])->save();

    $this->postJson('/webhooks/evolution', avisoDeEvolution($this->company->slug, [
        'audioMessage' => ['mimetype' => 'audio/ogg'],
    ]), ['apikey' => 'shh'])->assertOk();

    expect($this->gateway->enviados)->toBeEmpty()
        ->and(conversacionDeVoz($this->company->id)->fresh()->bot_paused_at)->not->toBeNull();
});

// ------------------------------------------------------------------ Las ráfagas

it('tres mensajes seguidos se contestan UNA vez, y con lo que dijo la ráfaga entera', function (): void {
    /*
     * Nadie escribe párrafos por WhatsApp: escribe ráfagas. Contestando a cada mensaje el bot suelta
     * tres respuestas —dos a un saludo—, la conversación parece una máquina y encima se pagan tres
     * llamadas al modelo para una sola pregunta.
     *
     * Y no basta con contestar una vez: hay que contestar A TODO. El último trozo de una ráfaga suele
     * ser el que menos dice; mirar solo ese es el fallo fácil de cometer aquí.
     */
    Http::fake([
        '*:generateContent*' => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => 'Sí, tenemos agua. Te la mando hoy.']]]]],
        ]),
    ]);

    AiSetting::actual()->update(['provider' => 'gemini', 'api_key' => 'AIzaDePrueba1234567890']);
    app()->forgetInstance(AiProvider::class);

    WaBotSetting::paraEmpresa($this->company->id)
        ->fill(['is_active' => true, 'business_info' => 'Vendemos agua. Delivery en Santiago.'])->save();

    $conversacion = conversacionDeVoz($this->company->id);
    $ráfaga = ['hola', 'necesito dos cajas de agua', 'para hoy'];
    $mensajes = [];

    foreach ($ráfaga as $texto) {
        $mensajes[] = WaMessage::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'wa_conversation_id' => $conversacion->id,
            'direction' => MessageDirection::Inbound,
            'type' => 'text',
            'body' => $texto,
            'status' => 'received',
            'sent_at' => now(),
        ]);
    }

    // Los tres trabajos, como los habría encolado el webhook. Solo el del último debe contestar.
    foreach ($mensajes as $mensaje) {
        (new ResponderAlCliente($mensaje))->handle(app(CurrentCompany::class), app(WhatsAppBot::class));
    }

    expect($this->gateway->enviados)->toHaveCount(1);

    // Y lo que se le preguntó al modelo lleva la ráfaga entera, no solo «para hoy».
    Http::assertSent(function ($peticion): bool {
        $cuerpo = json_encode($peticion->data()) ?: '';

        return str_contains($cuerpo, 'dos cajas de agua') && str_contains($cuerpo, 'para hoy');
    });
});

it('el trabajo de un mensaje viejo se aparta en cuanto entra otro detrás', function (): void {
    /*
     * El antirebote, aislado. Se comprueba por IDENTIFICADOR y no por hora: dos mensajes seguidos
     * caben en el mismo segundo y por hora empatarían, dejando que contestaran los dos.
     */
    Http::fake(['*:generateContent*' => Http::response([
        'candidates' => [['content' => ['parts' => [['text' => 'Dime']]]]],
    ])]);

    AiSetting::actual()->update(['provider' => 'gemini', 'api_key' => 'AIzaDePrueba1234567890']);
    app()->forgetInstance(AiProvider::class);

    WaBotSetting::paraEmpresa($this->company->id)
        ->fill(['is_active' => true, 'business_info' => 'Abrimos de 8 a 8.'])->save();

    $conversacion = conversacionDeVoz($this->company->id);
    $ahora = now();

    $primero = WaMessage::withoutGlobalScopes()->create([
        'company_id' => $this->company->id, 'wa_conversation_id' => $conversacion->id,
        'direction' => MessageDirection::Inbound, 'type' => 'text', 'body' => 'hola',
        'status' => 'received', 'sent_at' => $ahora, 'created_at' => $ahora,
    ]);

    // El segundo con LA MISMA hora exacta: por fecha serían indistinguibles.
    WaMessage::withoutGlobalScopes()->create([
        'company_id' => $this->company->id, 'wa_conversation_id' => $conversacion->id,
        'direction' => MessageDirection::Inbound, 'type' => 'text', 'body' => 'tienen agua?',
        'status' => 'received', 'sent_at' => $ahora, 'created_at' => $ahora,
    ]);

    (new ResponderAlCliente($primero))->handle(app(CurrentCompany::class), app(WhatsAppBot::class));

    expect($this->gateway->enviados)->toBeEmpty();
});

it('lo ya contestado no se vuelve a mandar al modelo como pregunta', function (): void {
    /*
     * El corte lo marca la última respuesta del negocio. Sin eso, cada mensaje nuevo arrastraría toda
     * la conversación como pregunta: se pagaría por ella entera y la pregunta de verdad quedaría
     * enterrada en un muro de texto viejo.
     */
    Http::fake(['*:generateContent*' => Http::response([
        'candidates' => [['content' => ['parts' => [['text' => 'Claro que sí.']]]]],
    ])]);

    AiSetting::actual()->update(['provider' => 'gemini', 'api_key' => 'AIzaDePrueba1234567890']);
    app()->forgetInstance(AiProvider::class);

    WaBotSetting::paraEmpresa($this->company->id)
        ->fill(['is_active' => true, 'business_info' => 'Vendemos agua.'])->save();

    $conversacion = conversacionDeVoz($this->company->id);

    foreach ([
        [MessageDirection::Inbound, 'pregunta vieja de la semana pasada'],
        [MessageDirection::Outbound, 'ya te contesté a eso'],
        [MessageDirection::Inbound, 'ahora quiero dos cajas'],
    ] as [$direccion, $texto]) {
        $ultimo = WaMessage::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'wa_conversation_id' => $conversacion->id,
            'direction' => $direccion, 'type' => 'text', 'body' => $texto,
            'status' => 'received', 'sent_at' => now(),
        ]);
    }

    (new ResponderAlCliente($ultimo))->handle(app(CurrentCompany::class), app(WhatsAppBot::class));

    Http::assertSent(function ($peticion): bool {
        $partes = $peticion->data()['contents'] ?? [];
        $ultimoTurno = json_encode(end($partes) ?: []) ?: '';

        // La pregunta es solo lo nuevo. Lo viejo sigue estando, pero como historial.
        return str_contains($ultimoTurno, 'ahora quiero dos cajas')
            && ! str_contains($ultimoTurno, 'pregunta vieja');
    });
});

it('la ráfaga NO se manda dos veces: ni como pregunta ni como historial', function (): void {
    /*
     * Al agrupar aparece un desperdicio que no se ve: los mensajes de la ráfaga van en la pregunta y,
     * si el historial sigue quitando solo el último, van OTRA VEZ como turnos anteriores. El modelo
     * lee «hola» dos veces, se paga dos veces, y encima parece que el cliente lo repitió.
     */
    Http::fake(['*:generateContent*' => Http::response([
        'candidates' => [['content' => ['parts' => [['text' => 'Dime']]]]],
    ])]);

    AiSetting::actual()->update(['provider' => 'gemini', 'api_key' => 'AIzaDePrueba1234567890']);
    app()->forgetInstance(AiProvider::class);

    WaBotSetting::paraEmpresa($this->company->id)
        ->fill(['is_active' => true, 'business_info' => 'Vendemos agua.'])->save();

    $conversacion = conversacionDeVoz($this->company->id);

    foreach (['tengo una pregunta', 'cuánto cuesta la caja de agua'] as $texto) {
        $ultimo = WaMessage::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'wa_conversation_id' => $conversacion->id,
            'direction' => MessageDirection::Inbound, 'type' => 'text', 'body' => $texto,
            'status' => 'received', 'sent_at' => now(),
        ]);
    }

    (new ResponderAlCliente($ultimo))->handle(app(CurrentCompany::class), app(WhatsAppBot::class));

    Http::assertSent(function ($peticion): bool {
        $contenidos = json_encode($peticion->data()['contents'] ?? [], JSON_UNESCAPED_UNICODE) ?: '';

        // Una sola vez cada frase. Con el historial mal recortado saldrían dos.
        return substr_count($contenidos, 'tengo una pregunta') === 1
            && substr_count($contenidos, 'cuánto cuesta la caja de agua') === 1;
    });
});

it('la ráfaga no se traga la petición de hablar con una persona', function (): void {
    /*
     * El fallo que el agrupado introduce si se hace mal: «quiero hablar con alguien» / «por favor».
     * Contestando solo al último mensaje, la petición se pierde y el cliente se queda esperando a un
     * humano que nadie ha avisado. Es la peor forma de fallar de este módulo.
     */
    Http::fake(['*:generateContent*' => Http::response([
        'candidates' => [['content' => ['parts' => [['text' => 'Lo que sea']]]]],
    ])]);

    AiSetting::actual()->update(['provider' => 'gemini', 'api_key' => 'AIzaDePrueba1234567890']);
    app()->forgetInstance(AiProvider::class);

    WaBotSetting::paraEmpresa($this->company->id)
        ->fill(['is_active' => true, 'business_info' => 'Abrimos de 8 a 8.'])->save();

    $conversacion = conversacionDeVoz($this->company->id);

    foreach (['quiero hablar con una persona', 'por favor'] as $texto) {
        $ultimo = WaMessage::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'wa_conversation_id' => $conversacion->id,
            'direction' => MessageDirection::Inbound, 'type' => 'text', 'body' => $texto,
            'status' => 'received', 'sent_at' => now(),
        ]);
    }

    (new ResponderAlCliente($ultimo))->handle(app(CurrentCompany::class), app(WhatsAppBot::class));

    expect($conversacion->fresh()->bot_paused_at)->not->toBeNull();
});

/** Una conversación con la que trabajar, sin pasar por el webhook. */
function conversacionDeVoz(int $companyId): WaConversation
{
    return WaConversation::withoutGlobalScopes()->firstOrCreate(
        ['company_id' => $companyId, 'phone' => '18095557777'],
        ['name' => 'Ramona', 'last_message_at' => now()],
    );
}
