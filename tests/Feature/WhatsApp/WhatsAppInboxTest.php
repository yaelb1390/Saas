<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\WhatsApp\Enums\MessageDirection;
use App\Modules\WhatsApp\Enums\MessageStatus;
use App\Modules\WhatsApp\Jobs\SendWhatsAppMessage;
use App\Modules\WhatsApp\Models\WaMessage;
use App\Modules\WhatsApp\Services\WhatsAppService;
use App\Modules\WhatsApp\Support\InboxPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(CurrentCompany::class)->forget();
    $this->company = app(CompanyService::class)->create(new CreateCompanyData(name: 'WA Inbox Co'));
    $this->user = withRole(User::create([
        'company_id' => $this->company->id,
        'name' => 'Agente',
        'email' => 'agente@wa.test',
        'password' => 'secret-password',
    ]));
    app(CurrentCompany::class)->set($this->company->id);
});

/** Fuerza el gateway real de Evolution (por defecto el sistema usa el gateway de log). */
function useEvolution(): void
{
    config([
        'evolution.base_url' => 'http://evolution.test',
        'evolution.api_key' => 'test-key',
        'evolution.webhook_secret' => 'shh',
        'evolution.webhook_url' => 'http://web/webhooks/evolution',
    ]);
}

it('envía un mensaje desde la bandeja y lo registra como saliente', function (): void {
    $this->actingAs($this->user)
        ->post(route('panel.whatsapp.send'), ['phone' => '18095551234', 'body' => 'Hola desde el panel'])
        ->assertRedirect(route('panel.whatsapp', ['c' => '18095551234']));

    $message = WaMessage::firstOrFail();

    expect($message->direction)->toBe(MessageDirection::Outbound)
        ->and($message->status)->toBe(MessageStatus::Sent)
        ->and($message->body)->toBe('Hola desde el panel');
});

it('encola la entrega en vez de bloquear la petición', function (): void {
    Queue::fake();

    $this->actingAs($this->user)
        ->post(route('panel.whatsapp.send'), ['phone' => '18095551234', 'body' => 'Mensaje en cola'])
        ->assertRedirect();

    // El mensaje se ve al instante como "Pendiente"; la cola lo entrega al proveedor.
    expect(WaMessage::firstOrFail()->status)->toBe(MessageStatus::Pending);

    Queue::assertPushed(SendWhatsAppMessage::class);
});

it('rechaza un teléfono con formato inválido', function (): void {
    $this->actingAs($this->user)
        ->post(route('panel.whatsapp.send'), ['phone' => '809-555-1234', 'body' => 'x'])
        ->assertSessionHasErrors('phone');

    expect(WaMessage::count())->toBe(0);
});

it('el gateway de Evolution envía a la instancia de la empresa activa', function (): void {
    useEvolution();
    Http::fake(['*' => Http::response(['key' => ['id' => 'EVO-1']], 200)]);

    $this->actingAs($this->user)
        ->post(route('panel.whatsapp.send'), ['phone' => '18095551234', 'body' => 'Hola'])
        ->assertRedirect();

    Http::assertSent(fn ($request) => str_contains($request->url(), '/message/sendText/'.$this->company->slug)
        && $request['number'] === '18095551234'
        && $request->hasHeader('apikey', 'test-key'));

    expect(WaMessage::firstOrFail()->external_id)->toBe('EVO-1');
});

it('crea la instancia y devuelve el QR de emparejamiento', function (): void {
    useEvolution();
    Http::fake([
        '*/instance/connectionState/*' => Http::response(['error' => 'not found'], 404),
        '*/instance/create' => Http::response(['qrcode' => ['base64' => 'data:image/png;base64,AAAA']], 201),
    ]);

    $this->actingAs($this->user)
        ->post(route('panel.whatsapp.connect'))
        ->assertRedirect()
        ->assertSessionHas('wa_qr', 'data:image/png;base64,AAAA');
});

it('informa que la línea ya está conectada sin pedir QR', function (): void {
    useEvolution();
    Http::fake(['*/instance/connectionState/*' => Http::response(['instance' => ['state' => 'open']], 200)]);

    $this->actingAs($this->user)
        ->post(route('panel.whatsapp.connect'))
        ->assertRedirect()
        ->assertSessionHas('panel_ok')
        ->assertSessionMissing('wa_qr');
});

it('el sondeo devuelve los mensajes entrantes sin recargar la página', function (): void {
    // Llega un mensaje por el webhook mientras el usuario tiene la bandeja abierta.
    app(WhatsAppService::class)->recordInbound('18095551234', 'Hola, ¿están abiertos?', 'WA-1', 'Ana');

    $this->actingAs($this->user)
        ->getJson(route('panel.whatsapp.poll', ['c' => '18095551234']))
        ->assertOk()
        ->assertJsonPath('active_phone', '18095551234')
        ->assertJsonPath('thread.0.body', 'Hola, ¿están abiertos?')
        ->assertJsonPath('thread.0.out', false)
        ->assertJsonPath('conversations.0.title', 'Ana');
});

it('el sondeo no expone conversaciones de otra empresa', function (): void {
    $other = app(CompanyService::class)->create(new CreateCompanyData(name: 'Ajena WA'));
    app(CurrentCompany::class)->set($other->id);
    app(WhatsAppService::class)->recordInbound('18099999999', 'Mensaje ajeno', 'WA-X', 'Intruso');

    app(CurrentCompany::class)->set($this->company->id);

    $this->actingAs($this->user)
        ->getJson(route('panel.whatsapp.poll'))
        ->assertOk()
        ->assertJsonCount(0, 'conversations');
});

it('la bandeja sigue siendo usable si Evolution está caído', function (): void {
    useEvolution();
    Http::fake(fn () => throw new ConnectionException('sin conexión'));

    $this->actingAs($this->user)
        ->get(route('panel.whatsapp'))
        ->assertOk()
        ->assertSee('Sin conexión');
});

it('la pantalla trae los dos ajustes nuevos y la marca de nota de voz', function (): void {
    /*
     * Que el código guarde `retention_days` no sirve de nada si el formulario no tiene dónde
     * escribirlo. Ya pasó una vez en este proyecto: una vista reescrita perdió componentes enteros
     * sin dar ni un error, y solo se vio al abrirla en el navegador.
     *
     * Se comprueba el CAMPO, no un texto de ayuda: los textos se reescriben y los nombres de campo
     * son el contrato con el que guarda.
     */
    $respuesta = $this->actingAs($this->user)->get(route('panel.whatsapp'))->assertOk();

    $respuesta->assertSee('name="group_seconds"', false)
        ->assertSee('name="retention_days"', false)
        // El aviso de que borrar no tiene vuelta atrás va JUNTO al campo, no después de guardar.
        ->assertSee('no se borra nunca')
        // Y la bandeja tiene que saber pintar una nota de voz transcrita.
        ->assertSee('m.sin_transcribir', false);
});

it('la retención de menos de una semana se rechaza en vez de aceptarse', function (): void {
    /*
     * Alguien que teclea «1» pensando en «un año» se borraría la semana entera de golpe. No hay
     * confirmación posible después: el comando corre de madrugada y no hay papelera.
     */
    $this->actingAs($this->user)
        ->post(route('panel.whatsapp.bot'), [
            'provider' => 'evolution',
            'business_info' => 'Abrimos de 8 a 8.',
            'retention_days' => 3,
        ])
        ->assertSessionHasErrors('retention_days');
});

// ------------------------------------------------------------------ Cómo se lee el hilo

it('la hora sale en la del negocio, no en UTC', function (): void {
    /*
     * Se guardan en UTC y aquí son cuatro horas menos. Se vio en pantalla: un mensaje recién
     * llegado aparecía marcado a las 04:01 cuando eran las 00:01.
     *
     * En un chat la hora no es decoración —se mira para saber si hace rato que alguien espera— y
     * cuatro horas de diferencia la vuelven inútil.
     */
    $mensaje = app(WhatsAppService::class)->recordInbound('18095551234', 'buenas', 'M-1');
    // Las diez de la noche en Santo Domingo son las dos de la madrugada del día siguiente en UTC.
    $mensaje->forceFill(['sent_at' => '2026-08-23 02:00:00'])->save();

    $hilo = app(InboxPresenter::class)->payload('18095551234')['thread'];

    expect($hilo[0]['time'])->toBe('22:00');
});

it('los mensajes seguidos del mismo se marcan para agruparlos', function (): void {
    // Tres «Hola» seguidos con tres colas no se parecen a una conversación.
    $wa = app(WhatsAppService::class);

    $uno = $wa->recordInbound('18095551234', 'Hola', 'M-1');
    $dos = $wa->recordInbound('18095551234', 'Hola?', 'M-2');
    $uno->forceFill(['sent_at' => '2026-08-23 02:00:00'])->save();
    $dos->forceFill(['sent_at' => '2026-08-23 02:01:00'])->save();

    $hilo = app(InboxPresenter::class)->payload('18095551234')['thread'];

    expect($hilo[0]['seguido'])->toBeFalse()
        ->and($hilo[1]['seguido'])->toBeTrue();
});

it('pasados cinco minutos ya no se agrupa, aunque escriba el mismo', function (): void {
    // Media hora después es otra intervención, no una frase más de la misma.
    $wa = app(WhatsAppService::class);

    $uno = $wa->recordInbound('18095551234', 'Hola', 'M-1');
    $dos = $wa->recordInbound('18095551234', 'Sigues ahi?', 'M-2');
    $uno->forceFill(['sent_at' => '2026-08-23 02:00:00'])->save();
    $dos->forceFill(['sent_at' => '2026-08-23 02:40:00'])->save();

    $hilo = app(InboxPresenter::class)->payload('18095551234')['thread'];

    expect($hilo[1]['seguido'])->toBeFalse();
});

it('el separador de día solo aparece cuando cambia el día', function (): void {
    $wa = app(WhatsAppService::class);

    $ayer = $wa->recordInbound('18095551234', 'De ayer', 'M-1');
    $hoyA = $wa->recordInbound('18095551234', 'De hoy', 'M-2');
    $hoyB = $wa->recordInbound('18095551234', 'De hoy tambien', 'M-3');

    /*
     * Las horas se construyen EN LA ZONA DEL NEGOCIO, no en UTC.
     *
     * El sistema guarda en UTC y aquí son cuatro horas menos, así que entre las ocho de la noche y
     * la medianoche el día del reloj y el día del negocio NO son el mismo. Escrito con now() a
     * secas, «ayer a las tres de la tarde» en UTC caía en el HOY del negocio y el test fallaba todas
     * las noches durante esas cuatro horas —y pasaba el resto del día, que es la peor forma de
     * fallar—. Lo que se está probando es lo que ve el dueño, así que se escribe en su hora.
     */
    $aqui = now()->timezone(config('app.business_timezone'));

    $ayer->forceFill(['sent_at' => $aqui->copy()->subDay()->setTime(15, 0)])->save();
    $hoyA->forceFill(['sent_at' => $aqui->copy()->setTime(10, 0)])->save();
    $hoyB->forceFill(['sent_at' => $aqui->copy()->setTime(11, 0)])->save();

    $hilo = app(InboxPresenter::class)->payload('18095551234')['thread'];

    expect($hilo[0]['separador'])->toBe('Ayer')
        ->and($hilo[1]['separador'])->toBe('Hoy')
        // El tercero es del mismo día que el segundo: no lleva separador.
        ->and($hilo[2]['separador'])->toBeNull();
});
