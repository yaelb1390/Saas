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
