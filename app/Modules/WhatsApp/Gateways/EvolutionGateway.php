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
    /**
     * Los eventos que Evolution nos tiene que avisar.
     *
     * `CONNECTION_UPDATE` es el que hace que la pantalla se entere sola de que ya escaneaste el QR.
     * Sin él la vista tenía que pedirle al usuario que recargara, porque nadie le contaba nunca que
     * el emparejamiento había salido bien.
     *
     * @var list<string>
     */
    private const EVENTOS = ['MESSAGES_UPSERT', 'CONNECTION_UPDATE'];

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

    public function puedeEnviarDocumentos(): bool
    {
        return true;
    }

    /**
     * Manda un archivo con `sendMedia`.
     *
     * La ruta y sus campos NO salen de la documentación: se le preguntaron al servidor. `sendDocument`
     * no existe («Cannot POST»); `sendMedia` sí, y mandándole un cuerpo vacío contesta que exige
     * `number` y `mediatype`, y que el `media` «must be a url or base64».
     */
    public function sendDocument(string $phone, string $url, string $fileName, string $caption = ''): array
    {
        $response = $this->request()
            ->post('/message/sendMedia/'.$this->instanceName(), [
                'number' => $phone,
                'mediatype' => 'document',
                // El tipo va explícito: sin él, algunos clientes de WhatsApp enseñan el PDF como un
                // archivo sin nombre que no se puede previsualizar.
                'mimetype' => 'application/pdf',
                'media' => $url,
                'fileName' => $fileName,
                'caption' => $caption,
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
            return ['state' => 'open', 'qr' => null, 'url' => null];
        }

        if ($status['state'] === 'missing') {
            $created = $this->request()->post('/instance/create', [
                'instanceName' => $status['instance'],
                'qrcode' => true,
                'integration' => 'WHATSAPP-BAILEYS',
                'webhook' => [
                    'url' => $this->webhookUrl(),
                    'byEvents' => false,
                    'events' => self::EVENTOS,
                ],
            ]);

            if ($created->successful()) {
                return ['state' => 'connecting', 'qr' => $created->json('qrcode.base64'), 'url' => null];
            }
        } else {
            /*
             * La instancia ya existía: hay que ponerle los eventos al día.
             *
             * Las creadas antes de esto solo pidieron `MESSAGES_UPSERT`, y eso no se arregla cambiando
             * el alta —el alta ya no vuelve a ocurrir para ellas—. Se comprobó contra Evolution 2.3.7:
             * la instancia que llevaba aquí desde julio seguía suscrita a un único evento.
             *
             * Va sin `throw()` a propósito: que no se pueda actualizar el webhook no es motivo para
             * negarle el QR a alguien que solo quiere vincular su línea. Como mucho se pierde el aviso
             * instantáneo, y el sondeo sigue contando la verdad.
             */
            $this->request()->post('/webhook/set/'.$status['instance'], [
                'webhook' => [
                    'enabled' => true,
                    'url' => $this->webhookUrl(),
                    'byEvents' => false,
                    'base64' => false,
                    'events' => self::EVENTOS,
                ],
            ]);
        }

        // La instancia existe pero está desconectada: pide un QR nuevo.
        $connect = $this->request()->get('/instance/connect/'.$status['instance']);

        return ['state' => 'connecting', 'qr' => $connect->json('base64'), 'url' => null];
    }

    /**
     * Desvincula el teléfono de la línea.
     *
     * Cierra la sesión, NO borra la instancia: en Evolution vive el histórico —en la línea que había
     * aquí, treinta y tres mil mensajes— y borrarla lo tiraría entero para no ganar nada. Después de
     * esto, `connect()` encuentra la instancia cerrada y pide un QR nuevo, que es justo lo que quiere
     * quien desvincula para vincular otro número.
     *
     * Es `DELETE`, no `POST`: comprobado contra la API, que responde 200 incluso si la línea ya
     * estaba caída —así desvincular arregla una sesión a medias en vez de fallar sobre ella—.
     */
    public function logout(): bool
    {
        return $this->request()->delete('/instance/logout/'.$this->instanceName())->successful();
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
