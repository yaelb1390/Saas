<?php

declare(strict_types=1);

use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\CRM\Models\Customer;
use App\Modules\WhatsApp\Enums\MessageDirection;
use App\Modules\WhatsApp\Enums\MessageStatus;
use App\Modules\WhatsApp\Events\WhatsAppMessageReceived;
use App\Modules\WhatsApp\Gateways\WhatsAppGateway;
use App\Modules\WhatsApp\Models\WaConversation;
use App\Modules\WhatsApp\Models\WaMessage;
use App\Modules\WhatsApp\Services\WhatsAppService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(CurrentCompany::class)->forget();
    $this->company = app(CompanyService::class)->create(new CreateCompanyData(name: 'WA Co'));
    app(CurrentCompany::class)->set($this->company->id);

    // Gateway falso que captura los envíos sin tocar la red.
    $this->gateway = new class implements WhatsAppGateway
    {
        /** @var array<int, array{0: string, 1: string}> */
        public array $calls = [];

        public function sendText(string $phone, string $body): array
        {
            $this->calls[] = [$phone, $body];

            return ['external_id' => 'ext-123', 'status' => 'sent'];
        }
    };
    $this->app->instance(WhatsAppGateway::class, $this->gateway);
    $this->wa = app(WhatsAppService::class);
});

it('envía un texto, registra el mensaje saliente y enlaza el cliente por teléfono', function (): void {
    $customer = Customer::create(['name' => 'Juan', 'phone' => '18095550000']);

    $message = $this->wa->sendText('18095550000', 'Hola');

    expect($message->direction)->toBe(MessageDirection::Outbound)
        ->and($message->status)->toBe(MessageStatus::Sent)
        ->and($message->external_id)->toBe('ext-123')
        ->and($this->gateway->calls)->toHaveCount(1);

    expect(WaConversation::firstOrFail()->customer_id)->toBe($customer->id);
});

it('registra un mensaje entrante y dispara el evento', function (): void {
    Event::fake([WhatsAppMessageReceived::class]);

    $message = $this->wa->recordInbound('18095550000', 'Hola inbound', 'MID-1', 'Juan');

    expect($message->direction)->toBe(MessageDirection::Inbound)
        ->and($message->status)->toBe(MessageStatus::Received)
        ->and($message->body)->toBe('Hola inbound');

    Event::assertDispatched(WhatsAppMessageReceived::class);
});

it('marca el mensaje como fallido si el gateway lanza excepción', function (): void {
    $this->app->instance(WhatsAppGateway::class, new class implements WhatsAppGateway
    {
        public function sendText(string $phone, string $body): array
        {
            throw new RuntimeException('gateway caído');
        }
    });
    $wa = app(WhatsAppService::class);

    expect(fn () => $wa->sendText('18095550000', 'x'))->toThrow(RuntimeException::class);

    expect(WaMessage::firstOrFail()->status)->toBe(MessageStatus::Failed);
});
