<?php

declare(strict_types=1);

namespace App\Modules\SocialCommerce\Services;

use App\Modules\Core\Models\Company;
use App\Modules\SocialCommerce\Events\LeadCaptured;
use App\Modules\SocialCommerce\Models\Conversation;
use Illuminate\Support\Carbon;

/**
 * Lo que hace falta con un aviso `message.received` de Zernio, ya con la empresa resuelta y la
 * firma comprobada por el controlador.
 *
 * Se procesa EN EL ACTO dentro del propio webhook, sin encolar un Job: a diferencia del bot de
 * WhatsApp (que llama a un proveedor de IA), esto solo hace escrituras locales — no hay ninguna
 * llamada externa que justifique diferirlo, y la cola de este proyecto se drena por cron en
 * producción (Fase 0, sección 9): encolarlo solo añadiría minutos de espera sin ganar nada.
 */
final class WebhookEventProcessor
{
    public function __construct(
        private readonly ContactIdentityResolver $identities,
        private readonly TemplateUsageRecorder $usage,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(Company $company, array $payload): string
    {
        $plataforma = data_get($payload, 'message.platform', data_get($payload, 'conversation.platform'));

        if (! in_array($plataforma, ['instagram', 'facebook'], true)) {
            return 'plataforma no soportada';
        }

        $texto = data_get($payload, 'message.text');

        if (! is_string($texto) || trim($texto) === '') {
            return 'sin texto';
        }

        $cuando = $this->cuando($payload);

        if (data_get($payload, 'message.direction') === 'outgoing') {
            $this->usage->record($company, $texto, $cuando);

            return 'saliente registrado';
        }

        return $this->registrarEntrante($company, $payload, $texto, $cuando, (string) $plataforma);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function registrarEntrante(Company $company, array $payload, string $texto, Carbon $cuando, string $plataforma): string
    {
        $conversationId = (string) data_get($payload, 'message.conversationId', '');
        $accountId = (string) data_get($payload, 'account.id', data_get($payload, 'account._id', ''));
        $externalId = (string) data_get($payload, 'message.sender.id', '');

        if ($conversationId === '' || $accountId === '' || $externalId === '') {
            return 'aviso incompleto';
        }

        $identidad = $this->identities->resolve(
            $company,
            $externalId,
            is_string($username = data_get($payload, 'message.sender.username')) ? $username : null,
            is_string($nombre = data_get($payload, 'message.sender.name')) ? $nombre : null,
        );

        $conversacion = Conversation::query()->where('zernio_conversation_id', $conversationId)->first();
        $esNueva = $conversacion === null;

        if ($conversacion === null) {
            $conversacion = new Conversation;
            $conversacion->fill([
                'contact_identity_id' => $identidad->id,
                'zernio_conversation_id' => $conversationId,
                'zernio_account_id' => $accountId,
                'platform' => $plataforma,
            ]);
        }

        $conversacion->last_message_at = $cuando;
        $conversacion->save();

        $conversacion->messages()->create([
            'company_id' => $conversacion->company_id,
            'direction' => 'incoming',
            'body' => $texto,
            'zernio_message_id' => data_get($payload, 'message.platformMessageId'),
            'sent_at' => $cuando,
        ]);

        if ($esNueva) {
            LeadCaptured::dispatch($conversacion);
        }

        return 'recibido';
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function cuando(array $payload): Carbon
    {
        $marca = data_get($payload, 'message.createdAt', data_get($payload, 'message.sentAt'));

        if (! is_string($marca) || $marca === '') {
            return Carbon::now();
        }

        return rescue(fn (): Carbon => Carbon::parse($marca), Carbon::now(), report: false);
    }
}
