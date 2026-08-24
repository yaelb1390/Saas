<?php

declare(strict_types=1);

use App\Modules\AI\Models\AiSetting;
use App\Modules\AI\Providers\Contracts\AiProvider;
use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Support\DbTable;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\Help\Models\AssistantQuestion;
use App\Modules\Inventory\Models\Product;
use App\Modules\WhatsApp\Gateways\WhatsAppGateway;
use App\Modules\WhatsApp\Models\WaBotSetting;
use App\Modules\WhatsApp\Models\WaConversation;
use App\Modules\WhatsApp\Models\WaMessage;
use App\Modules\WhatsApp\Services\WhatsAppService;
use App\Modules\WhatsApp\Support\ProductLookup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

/*
 * El bot que atiende a los CLIENTES del negocio.
 *
 * Aquí no se comprueba que «conteste bien»: eso depende del modelo y no se puede fijar en un test.
 * Lo que se fija es lo que cuesta dinero, reputación o un cliente si sale mal:
 *
 *   · el precio de COSTE no puede salir por ninguna vía,
 *   · lo que no hay se dice, no se calla ni se ofrece,
 *   · cuando alguien pide una persona, el bot se calla Y SE QUEDA callado,
 *   · un fallo del proveedor no puede devolver un 500, porque Evolution reintentaría en bucle.
 */

uses(RefreshDatabase::class);

/**
 * Deja al bot hablando con un Gemini falseado que contesta lo que se le diga.
 *
 * Es su propia función y no `conGemini()` porque aquí hace falta ELEGIR la respuesta —para probar el
 * «no lo sé» y el fallo—, y porque `Http::fake` casa en orden de declaración: un segundo `fake` sobre
 * la misma URL no gana al primero, lo pierde.
 */
function botConGemini(string $respuesta = 'Claro que sí, tenemos.'): void
{
    Http::fake([
        '*:generateContent*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => $respuesta]]]]]]),
    ]);

    AiSetting::actual()->update(['provider' => 'gemini', 'api_key' => 'AIzaDePrueba1234567890']);

    // `AiProvider` es un singleton y ya se resolvió —al proveedor local— antes de esto.
    app()->forgetInstance(AiProvider::class);
}

/**
 * ¿Llamó EL BOT al proveedor?
 *
 * No vale `assertNothingSent()`: sobre el mismo mensaje entrante corre también el clasificador de
 * sentimiento, que es otra función y llama al proveedor por su cuenta. Sin distinguirlos, «no se
 * llamó a nadie» sería falso siempre y el test no probaría nada.
 *
 * Se distingue por el encabezado de las reglas del bot, que ninguna otra llamada lleva. Se eligió
 * ese y no la primera línea porque la primera línea la escribe el dueño y puede ser cualquier cosa:
 * las reglas están siempre, digan lo que digan sus instrucciones.
 */
function elBotLlamo(): bool
{
    $llamo = false;

    Http::recorded(function ($peticion) use (&$llamo): void {
        if (str_contains(json_encode($peticion->data()) ?: '', 'REGLAS QUE MANDAN')) {
            $llamo = true;
        }
    });

    return $llamo;
}

/** El bot encendido y con algo que contar. */
function botEncendido(int $companyId, string $info = 'Abrimos de 8 a 8. Hacemos delivery en Santiago.'): WaBotSetting
{
    $ajustes = WaBotSetting::paraEmpresa($companyId);
    $ajustes->fill(['is_active' => true, 'business_info' => $info])->save();

    return $ajustes;
}

/** Un mensaje que llega de un cliente, como si viniera del webhook. */
function clienteEscribe(string $texto, string $phone = '18095551111'): WaMessage
{
    return app(WhatsAppService::class)->recordInbound($phone, $texto, null, 'Cliente');
}

beforeEach(function (): void {
    app(CurrentCompany::class)->forget();
    $this->company = app(CompanyService::class)->create(new CreateCompanyData(name: 'Colmado Bot'));
    app(CurrentCompany::class)->set($this->company->id);

    // Gateway falso: captura lo que se envía sin tocar la red ni WhatsApp.
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
            $this->enviados[] = [$phone, $caption];

            return ['external_id' => 'doc-'.count($this->enviados), 'status' => 'sent'];
        }
    };
    $this->app->instance(WhatsAppGateway::class, $this->gateway);
});

// ---------------------------------------------------------------- El precio de coste

it('el precio de coste NO llega al proveedor de IA, ni preguntando por él directamente', function (): void {
    /*
     * El peor fallo posible de este módulo.
     *
     * `cost` vive en la columna de al lado de `price`. Es el margen del negocio, y que se le escape a
     * un cliente —o a la competencia preguntando de buenas maneras— no es un error cosmético.
     *
     * Se comprueba contra lo que SALE hacia el proveedor y no contra lo que contesta: lo que el
     * modelo no recibe, no lo puede decir.
     */
    Product::create(['sku' => 'BAT-1', 'name' => 'Batida de lechosa', 'cost' => '35.55', 'price' => '120.00']);

    botConGemini();
    botEncendido($this->company->id);

    clienteEscribe('a como te cuesta a ti la batida de lechosa? cuanto te cuesta comprarla');

    Http::assertNotSent(fn ($request): bool => str_contains(json_encode($request->data()) ?: '', '35.55'));

    // Y el precio de venta SÍ tiene que ir, o el bot no sabría contestar lo que le preguntan.
    Http::assertSent(fn ($request): bool => str_contains(json_encode($request->data()) ?: '', '120.00'));
});

it('el buscador del catálogo pide columnas explícitas y nunca el producto entero', function (): void {
    /*
     * Esta es la barrera de verdad, y por eso se fija aparte.
     *
     * El test de arriba pasa igual aunque alguien quite el `select()`, porque el mapeo de salida no
     * incluye `cost`. Lo que evita que un cambio futuro lo arrastre es que la consulta NO sea
     * `select *`, y eso es lo que se comprueba aquí.
     */
    Product::create(['sku' => 'BAT-2', 'name' => 'Batida de fresa', 'cost' => '40.00', 'price' => '130.00']);

    DB::enableQueryLog();
    app(ProductLookup::class)->buscar('batida');
    $sql = collect(DB::getQueryLog())->pluck('query')->implode(' ');
    DB::disableQueryLog();

    expect($sql)->toContain('"name"')
        ->and($sql)->not->toContain('select *')
        ->and($sql)->not->toContain('cost');
});

// ---------------------------------------------------------------- Lo que hoy no hay

it('lo que se acabó se le dice al modelo como HOY NO HAY, no se calla ni se ofrece', function (): void {
    // `is_active` («ya no lo vendemos») y `is_available` («se acabó, vuelve mañana») no son lo mismo
    // y al cliente hay que contestarle distinto.
    Product::create(['sku' => 'POL-1', 'name' => 'Pollo horneado', 'price' => '350.00', 'is_available' => false]);

    botConGemini();
    botEncendido($this->company->id);

    clienteEscribe('tienen pollo horneado?');

    Http::assertSent(fn ($request): bool => str_contains(json_encode($request->data()) ?: '', 'HOY NO HAY'));
});

it('lo que está de baja no se le ofrece al cliente en absoluto', function (): void {
    Product::create(['sku' => 'VIE-1', 'name' => 'Pollo al carbon', 'price' => '400.00', 'is_active' => false]);

    botConGemini();
    botEncendido($this->company->id);

    clienteEscribe('tienen pollo al carbon?');

    // Ni siquiera aparece en la lista: no es que no haya hoy, es que ya no se vende.
    Http::assertNotSent(fn ($request): bool => str_contains(json_encode($request->data()) ?: '', 'Pollo al carbon'));
});

// ---------------------------------------------------------------- El traspaso a una persona

it('quien pide hablar con una persona deja de recibir respuestas del bot, y no vuelven', function (): void {
    botConGemini();
    botEncendido($this->company->id);

    clienteEscribe('quiero hablar con una persona por favor');

    expect($this->gateway->enviados)->toHaveCount(1)
        ->and($this->gateway->enviados[0][1])->toContain('una persona del equipo');

    expect(WaConversation::firstOrFail()->bot_paused_at)->not->toBeNull();

    // Y lo importante: NO vuelve. Tres mensajes más y sigue callado.
    clienteEscribe('hola?');
    clienteEscribe('tienen delivery?');
    clienteEscribe('me pueden atender?');

    expect($this->gateway->enviados)->toHaveCount(1);
});

it('el bot que no sabe se aparta en vez de repetir que no entendió', function (): void {
    // El modelo declara que no lo sabe con una palabra clave, para poder distinguirlo de una
    // respuesta de verdad sin adivinar por el texto.
    botConGemini('NO_LO_SE');
    botEncendido($this->company->id);

    clienteEscribe('ustedes reparan neveras?');
    clienteEscribe('hola? reparan neveras?');

    // Un solo mensaje: el de traspaso. No hay dos «no entendí» seguidos.
    expect($this->gateway->enviados)->toHaveCount(1)
        ->and($this->gateway->enviados[0][1])->toContain('una persona del equipo');

    expect(AssistantQuestion::withoutGlobalScopes()->where('answered_by', AssistantQuestion::WHATSAPP_SIN_RESPUESTA)->count())
        ->toBe(1);
});

it('una persona puede devolverle la conversación al bot', function (): void {
    botConGemini();
    botEncendido($this->company->id);

    clienteEscribe('quiero hablar con alguien');
    expect($this->gateway->enviados)->toHaveCount(1);

    WaConversation::firstOrFail()->forceFill(['bot_paused_at' => null])->save();

    clienteEscribe('bueno, y a que hora abren?');

    expect($this->gateway->enviados)->toHaveCount(2);
});

// ---------------------------------------------------------------- Apagado y cortesías

it('con el bot apagado no sale ni un mensaje', function (): void {
    botConGemini();
    // Sin `botEncendido()`: la fila ni existe.

    clienteEscribe('tienen delivery?');

    expect($this->gateway->enviados)->toBeEmpty();
    expect(elBotLlamo())->toBeFalse();
});

it('encendido pero sin contarle nada del negocio, tampoco contesta', function (): void {
    // Un bot sin información no se queda callado: le contestaría a todo el mundo que no sabe. Mejor
    // que ni empiece.
    botConGemini();
    WaBotSetting::paraEmpresa($this->company->id)->fill(['is_active' => true, 'business_info' => null])->save();

    clienteEscribe('tienen delivery?');

    expect($this->gateway->enviados)->toBeEmpty();
});

it('un saludo se contesta con el saludo del dueño, sin llamar a la IA ni gastar cuota', function (): void {
    botConGemini();
    $ajustes = botEncendido($this->company->id);
    $ajustes->fill(['greeting' => 'Klk, gracias por escribir a Colmado La Esquina.'])->save();

    clienteEscribe('klk');

    expect($this->gateway->enviados)->toHaveCount(1)
        ->and($this->gateway->enviados[0][1])->toBe('Klk, gracias por escribir a Colmado La Esquina.');

    expect(elBotLlamo())->toBeFalse();

    /*
     * Y no cuenta para el tope diario.
     *
     * En el panel el tope cuenta TODAS las preguntas, también las que no llaman al proveedor. Aquí
     * ese razonamiento hace daño: quien escribe no es el dueño de la cuenta, es un desconocido, y
     * diez «klk» de un curioso dejarían al negocio sin bot para el cliente que sí iba a comprar.
     */
    expect(AssistantQuestion::withoutGlobalScopes()->count())->toBe(0);
});

it('una pregunta que empieza con un saludo NO se traga por el saludo', function (): void {
    // El error clásico: comparar con «contiene» en vez de con igualdad.
    botConGemini('La batida cuesta 120 pesos.');
    botEncendido($this->company->id);

    clienteEscribe('hola, cuanto vale la batida?');

    expect($this->gateway->enviados[0][1])->toBe('La batida cuesta 120 pesos.');
    expect(elBotLlamo())->toBeTrue();
});

// ---------------------------------------------------------------- El gasto

it('al agotarse el tope diario no se llama al proveedor y la conversación pasa a una persona', function (): void {
    botConGemini();
    botEncendido($this->company->id);

    AiSetting::actual()->update(['daily_limit' => 1]);
    AssistantQuestion::create([
        'company_id' => $this->company->id, 'question' => 'ya gastada', 'answered_by' => AssistantQuestion::WHATSAPP_IA,
    ]);

    clienteEscribe('tienen delivery a Santiago?');

    // Con Gemini de verdad (falseado): sobre el proveedor local esto sería cierto siempre y no
    // demostraría nada, porque nunca sale a la red.
    expect(elBotLlamo())->toBeFalse();

    expect(WaConversation::firstOrFail()->bot_paused_at)->not->toBeNull();
});

it('el tope se cuenta por empresa: una no se come la cuota de la otra', function (): void {
    botConGemini();
    botEncendido($this->company->id);
    AiSetting::actual()->update(['daily_limit' => 1]);

    // La otra empresa ya gastó lo suyo.
    app(CurrentCompany::class)->forget();
    $otra = app(CompanyService::class)->create(new CreateCompanyData(name: 'Otro Negocio'));
    AssistantQuestion::withoutGlobalScopes()->create([
        'company_id' => $otra->id, 'question' => 'suya', 'answered_by' => AssistantQuestion::WHATSAPP_IA,
    ]);

    app(CurrentCompany::class)->set($this->company->id);
    clienteEscribe('a que hora abren?');

    // A la nuestra le quedaba entera.
    expect($this->gateway->enviados)->toHaveCount(1);
});

it('el bot de una empresa no contesta con el catálogo de la otra', function (): void {
    app(CurrentCompany::class)->forget();
    $otra = app(CompanyService::class)->create(new CreateCompanyData(name: 'Ferretería Ajena'));
    app(CurrentCompany::class)->set($otra->id);
    Product::create(['sku' => 'SEC-1', 'name' => 'Taladro secreto', 'price' => '9999.00']);

    app(CurrentCompany::class)->set($this->company->id);
    Product::create(['sku' => 'MIO-1', 'name' => 'Taladro propio', 'price' => '1500.00']);

    botConGemini();
    botEncendido($this->company->id);

    clienteEscribe('tienen taladro?');

    Http::assertNotSent(fn ($request): bool => str_contains(json_encode($request->data()) ?: '', 'Taladro secreto'));
    Http::assertSent(fn ($request): bool => str_contains(json_encode($request->data()) ?: '', 'Taladro propio'));
});

// ---------------------------------------------------------------- Que no reviente

it('si el proveedor falla, el webhook sigue devolviendo 200 y el mensaje queda guardado', function (): void {
    /*
     * Evolution REINTENTA lo que le falla.
     *
     * Un 500 aquí no sería «el bot no contestó una vez»: sería el mismo mensaje llegando otra vez, y
     * otra, y el cliente recibiendo la misma respuesta varias veces o la cuota gastándose en bucle.
     */
    config(['evolution.webhook_secret' => 'shh']);
    botEncendido($this->company->id);

    AiSetting::actual()->update(['provider' => 'gemini', 'api_key' => 'AIzaDePrueba1234567890']);
    app()->forgetInstance(AiProvider::class);
    Http::fake(['*:generateContent*' => Http::response(['error' => 'boom'], 500)]);

    $this->postJson('/webhooks/evolution', [
        'event' => 'messages.upsert',
        'instance' => $this->company->slug,
        'data' => [
            'key' => ['remoteJid' => '18095552222@s.whatsapp.net', 'fromMe' => false, 'id' => 'MID-9'],
            'pushName' => 'Ana',
            'message' => ['conversation' => 'tienen delivery?'],
        ],
    ], ['apikey' => 'shh'])->assertOk();

    // El mensaje del cliente NO se pierde: está en la bandeja para que lo conteste una persona.
    expect(WaMessage::withoutGlobalScopes()->where('body', 'tienen delivery?')->count())->toBe(1);

    // Y el bot se apartó en vez de dejar la conversación en el aire.
    expect(WaConversation::withoutGlobalScopes()->firstOrFail()->bot_paused_at)->not->toBeNull();
});

it('el freno corta cuando lleva demasiadas respuestas seguidas en la misma conversación', function (): void {
    /*
     * Evolution conecta por QR con la sesión de WhatsApp Web, no con la API oficial: Meta puede
     * bloquear el número si ve automatización agresiva. Perder el bot en un caso raro es barato;
     * perder el número del negocio no lo es.
     */
    botConGemini('Sí, claro.');
    botEncendido($this->company->id);
    AiSetting::actual()->update(['daily_limit' => 500]);

    foreach (range(1, 14) as $i) {
        clienteEscribe("pregunta numero {$i} sobre delivery");
    }

    // Doce respuestas y para. La número trece ya no sale.
    expect($this->gateway->enviados)->toHaveCount(12);
    expect(WaConversation::firstOrFail()->bot_paused_at)->not->toBeNull();
});

// ---------------------------------------------------------------- La línea se entera sola

it('CONNECTION_UPDATE deja la línea en línea y el sondeo lo refleja sin recargar', function (): void {
    config(['evolution.webhook_secret' => 'shh']);

    $this->postJson('/webhooks/evolution', [
        'event' => 'connection.update',
        'instance' => $this->company->slug,
        'data' => ['instance' => $this->company->slug, 'state' => 'open', 'statusReason' => 200],
    ], ['apikey' => 'shh'])->assertOk();

    // No se trató como un mensaje: no hay nada guardado en la bandeja.
    expect(WaMessage::withoutGlobalScopes()->count())->toBe(0);

    // Y queda en el registro del sistema, que es lo primero que se mira cuando alguien dice que el
    // bot dejó de contestar.
    expect(DB::table('system_events')->where('type', 'integration.whatsapp')->count())->toBe(1);
});

it('un webhook sin nombre de evento se sigue tratando como mensaje', function (): void {
    /*
     * Compatibilidad hacia atrás, y no es un detalle.
     *
     * Antes esto no miraba el evento en absoluto. Si un aviso sin nombre cayera ahora en el «ignorar»,
     * un mensaje de un cliente se perdería EN SILENCIO, y nadie lo notaría hasta que se quejara de
     * que escribió y no le contestaron.
     */
    config(['evolution.webhook_secret' => 'shh']);

    $this->postJson('/webhooks/evolution', [
        'instance' => $this->company->slug,
        'data' => [
            'key' => ['remoteJid' => '18095553333@s.whatsapp.net', 'fromMe' => false, 'id' => 'MID-7'],
            'message' => ['conversation' => 'sin campo event'],
        ],
    ], ['apikey' => 'shh'])->assertOk();

    expect(WaMessage::withoutGlobalScopes()->where('body', 'sin campo event')->count())->toBe(1);
});

it('si la migración del bot todavía no está aplicada, el webhook no se cae', function (): void {
    /*
     * Este es EL caso de producción, no una rareza de laboratorio.
     *
     * Aquí las migraciones se aplican a mano y el despliegue no las corre: entre que el código sale
     * y alguien migra pasan minutos o un fin de semana. En ese hueco, el oyente del bot se encuentra
     * una tabla que no existe.
     *
     * Sin el `try/catch` del oyente eso sería un 500 en el webhook, Evolution reintentaría el mensaje
     * en bucle, y el negocio dejaría de recibir lo que le escriben sus clientes.
     */
    config(['evolution.webhook_secret' => 'shh']);

    Schema::drop('wa_bot_settings');
    DbTable::olvidar();

    $this->postJson('/webhooks/evolution', [
        'event' => 'messages.upsert',
        'instance' => $this->company->slug,
        'data' => [
            'key' => ['remoteJid' => '18095554444@s.whatsapp.net', 'fromMe' => false, 'id' => 'MID-11'],
            'message' => ['conversation' => 'sin la tabla del bot'],
        ],
    ], ['apikey' => 'shh'])->assertOk();

    expect(WaMessage::withoutGlobalScopes()->where('body', 'sin la tabla del bot')->count())->toBe(1);
});
