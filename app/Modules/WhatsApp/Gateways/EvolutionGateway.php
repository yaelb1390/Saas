<?php

declare(strict_types=1);

namespace App\Modules\WhatsApp\Gateways;

use App\Modules\Core\Models\Company;
use App\Modules\Core\Tenancy\CurrentCompany;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Implementación real del gateway usando Evolution API (v2).
 *
 * El nombre de la instancia es el slug de la empresa activa: así cada empresa tiene su propia
 * línea de WhatsApp y el webhook entrante puede resolver el tenant a partir de `instance`.
 */
final class EvolutionGateway implements WhatsAppConnection, WhatsAppGateway
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(private readonly array $config) {}

    public function sendText(string $phone, string $body): array
    {
        $response = $this->request()
            ->post('/message/sendText/'.$this->instanceName(), [
                'number' => $phone,
                'text' => $body,
            ])
            ->throw();

        /** @var array<string, mixed> $data */
        $data = (array) $response->json();

        return [
            'external_id' => data_get($data, 'key.id'),
            'status' => 'sent',
        ];
    }

    public function status(): array
    {
        $instance = $this->instanceName();

        // El estado se consulta en cada carga del panel: si Evolution no responde rápido,
        // preferimos degradar a "sin conexión" antes que bloquear la página.
        $response = $this->request()->timeout(4)->get('/instance/connectionState/'.$instance);

        if (! $response->successful()) {
            // La instancia aún no existe en Evolution.
            return ['state' => 'missing', 'instance' => $instance, 'connected' => false];
        }

        $state = (string) ($response->json('instance.state') ?? 'close');

        return ['state' => $state, 'instance' => $instance, 'connected' => $state === 'open'];
    }

    public function connect(): array
    {
        $status = $this->status();

        if ($status['connected']) {
            return ['state' => 'open', 'qr' => null];
        }

        if ($status['state'] === 'missing') {
            $created = $this->request()->post('/instance/create', [
                'instanceName' => $status['instance'],
                'qrcode' => true,
                'integration' => 'WHATSAPP-BAILEYS',
                'webhook' => [
                    'url' => $this->webhookUrl(),
                    'byEvents' => false,
                    'events' => ['MESSAGES_UPSERT'],
                ],
            ]);

            if ($created->successful()) {
                return ['state' => 'connecting', 'qr' => $created->json('qrcode.base64')];
            }
        }

        // La instancia existe pero está desconectada: pide un QR nuevo.
        $connect = $this->request()->get('/instance/connect/'.$status['instance']);

        return ['state' => 'connecting', 'qr' => $connect->json('base64')];
    }

    private function request(): PendingRequest
    {
        return Http::withHeaders(['apikey' => (string) ($this->config['api_key'] ?? '')])
            ->acceptJson()
            ->timeout(15)
            ->baseUrl(rtrim((string) ($this->config['base_url'] ?? ''), '/'));
    }

    /**
     * El slug de la empresa activa identifica la instancia (una línea por empresa).
     */
    private function instanceName(): string
    {
        $companyId = app(CurrentCompany::class)->id();

        $slug = $companyId !== null
            ? Company::query()->whereKey($companyId)->value('slug')
            : null;

        return (string) ($slug ?? $this->config['instance'] ?? 'default');
    }

    /**
     * URL que Evolution llamará al recibir mensajes. El secreto viaja como query string para
     * no depender del soporte de cabeceras personalizadas del proveedor.
     */
    private function webhookUrl(): string
    {
        $base = (string) ($this->config['webhook_url'] ?? url('/webhooks/evolution'));
        $secret = (string) ($this->config['webhook_secret'] ?? '');

        return $secret === '' ? $base : $base.'?secret='.urlencode($secret);
    }
}
