<?php

declare(strict_types=1);

use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\WhatsApp\Enums\MessageDirection;
use App\Modules\WhatsApp\Models\WaMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(CurrentCompany::class)->forget();
    $this->company = app(CompanyService::class)->create(new CreateCompanyData(name: 'Hook Co'));
    config(['evolution.webhook_secret' => 'shh']);
});

function inboundPayload(string $instance): array
{
    return [
        'instance' => $instance,
        'data' => [
            'key' => ['remoteJid' => '18095550000@s.whatsapp.net', 'fromMe' => false, 'id' => 'MID-1'],
            'pushName' => 'Juan',
            'message' => ['conversation' => 'Hola desde webhook'],
        ],
    ];
}

it('rechaza el webhook sin el secreto correcto', function (): void {
    $this->postJson('/webhooks/evolution', inboundPayload($this->company->slug), ['apikey' => 'wrong'])
        ->assertStatus(401);
});

it('registra el mensaje entrante recibido por el webhook', function (): void {
    $this->postJson('/webhooks/evolution', inboundPayload($this->company->slug), ['apikey' => 'shh'])
        ->assertOk();

    $message = WaMessage::withoutGlobalScopes()->first();

    expect($message)->not->toBeNull()
        ->and($message->company_id)->toBe($this->company->id)
        ->and($message->body)->toBe('Hola desde webhook')
        ->and($message->direction)->toBe(MessageDirection::Inbound);
});

it('ignora los ecos de mensajes propios (fromMe)', function (): void {
    $payload = inboundPayload($this->company->slug);
    $payload['data']['key']['fromMe'] = true;

    $this->postJson('/webhooks/evolution', $payload, ['apikey' => 'shh'])
        ->assertStatus(202);

    expect(WaMessage::withoutGlobalScopes()->count())->toBe(0);
});
