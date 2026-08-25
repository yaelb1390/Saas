<?php

declare(strict_types=1);

namespace App\Modules\WhatsApp\Services;

use App\Modules\CRM\Models\Customer;
use App\Modules\WhatsApp\Enums\MessageDirection;
use App\Modules\WhatsApp\Enums\MessageStatus;
use App\Modules\WhatsApp\Events\WhatsAppMessageReceived;
use App\Modules\WhatsApp\Jobs\SendWhatsAppMessage;
use App\Modules\WhatsApp\Models\WaConversation;
use App\Modules\WhatsApp\Models\WaMessage;
use App\Modules\WhatsApp\Support\ClienteDeWhatsApp;
use App\Modules\WhatsApp\Support\MensajeEntrante;

/**
 * Orquesta el envío y la recepción de mensajes de WhatsApp. Persiste toda la conversación para
 * trazabilidad y enlaza automáticamente con el cliente del CRM cuando el teléfono coincide.
 *
 * El diálogo con el proveedor vive en SendWhatsAppMessage (cola): este servicio no toca la red.
 */
final class WhatsAppService
{
    public function __construct(private readonly ClienteDeWhatsApp $clientes) {}

    /**
     * Encola un mensaje saliente. Se persiste como "Pendiente" y la cola lo entrega al proveedor,
     * de modo que la petición del usuario no espera a la red de WhatsApp.
     */
    public function sendText(string $phone, string $body, ?int $userId = null): WaMessage
    {
        $conversation = $this->conversationFor($phone);

        $message = $conversation->messages()->create([
            'company_id' => $conversation->company_id,
            'direction' => MessageDirection::Outbound,
            'type' => 'text',
            'body' => $body,
            'status' => MessageStatus::Pending,
            'user_id' => $userId ?? auth()->id(),
        ]);

        // La conversación sube al principio de la bandeja de inmediato, aunque el envío
        // todavía esté en cola.
        $conversation->update(['last_message_at' => now()]);

        SendWhatsAppMessage::dispatch($message);

        return $message->refresh();
    }

    /**
     * Encola un ARCHIVO. Mismo camino que un texto: se persiste y lo entrega la cola.
     *
     * Se guarda la dirección del archivo, no el archivo. El proveedor lo descarga por su cuenta
     * —Evolution acepta una URL— y aquí no hay dónde guardarlo: en producción el disco es de solo
     * lectura. Además, generándolo al vuelo detrás de un enlace firmado nunca se manda una versión
     * vieja de un documento que pudo cambiar.
     *
     * El `body` lleva el pie del mensaje, que es lo que se ve en la bandeja: una fila que solo
     * dijera «documento» no le diría nada a quien revise la conversación mañana.
     */
    public function sendDocument(string $phone, string $url, string $fileName, string $caption = '', ?int $userId = null): WaMessage
    {
        $conversation = $this->conversationFor($phone);

        $message = $conversation->messages()->create([
            'company_id' => $conversation->company_id,
            'direction' => MessageDirection::Outbound,
            'type' => 'document',
            'body' => $caption,
            'media_url' => $url,
            'media_name' => $fileName,
            'status' => MessageStatus::Pending,
            'user_id' => $userId ?? auth()->id(),
        ]);

        $conversation->update(['last_message_at' => now()]);

        SendWhatsAppMessage::dispatch($message);

        return $message->refresh();
    }

    /**
     * Registra un mensaje entrante (invocado desde el webhook de Evolution).
     */
    public function recordInbound(
        string $phone,
        string $body,
        ?string $externalId = null,
        ?string $name = null,
        ?MensajeEntrante $entrante = null,
    ): WaMessage {
        $conversation = $this->conversationFor($phone, $name);

        $message = $conversation->messages()->create([
            'company_id' => $conversation->company_id,
            'direction' => MessageDirection::Inbound,
            // El tipo, para que la bandeja pueda enseñar «🎤 nota de voz» y no una fila vacía.
            'type' => $entrante?->tipo ?? 'text',
            'body' => $body,
            'status' => MessageStatus::Received,
            'external_id' => $externalId,
            'sent_at' => now(),
        ]);

        $conversation->update(['last_message_at' => now()]);

        /*
         * Quien escribe entra en el CRM.
         *
         * Antes solo se ENLAZABA con un cliente que ya existiera, así que quien escribía por primera
         * vez dejaba una conversación sin ficha: no se le podía cotizar ni ver su historial sin darlo
         * de alta a mano. Va después de guardar el mensaje a propósito: si crear el cliente falla, el
         * mensaje ya está a salvo.
         */
        $this->clientes->deLaConversacion($conversation, $phone, $name);

        WhatsAppMessageReceived::dispatch($message);

        return $message;
    }

    /**
     * Registra un mensaje que llega por Zernio (la vía oficial de Meta).
     *
     * Se separa de `recordInbound()` porque la identidad NO es la misma. Por Evolution un mensaje
     * llega con un teléfono y basta. Por la vía oficial hay dos cosas más que guardar y una que puede
     * faltar:
     *
     *  · La CONVERSACIÓN de Zernio, que es por donde se contesta. Sin ella no se puede responder.
     *  · La CUENTA desde la que se responde.
     *  · El TELÉFONO, que desde abril de 2026 **puede no venir**: WhatsApp deja escribir a un negocio
     *    a quien usa nombre de usuario, sin exponer su número. Meta manda entonces un identificador
     *    propio (BSUID), que es lo que Zernio recomienda usar como identidad.
     *
     * Por eso la conversación se busca primero por su identificador de Zernio y solo después por el
     * teléfono: buscar por teléfono como primera opción partiría el hilo de quien no lo tiene, y
     * dejaría fuera al que cambia de identificador a mitad de conversación.
     */
    public function recordInboundFromZernio(
        string $conversationId,
        string $accountId,
        string $identidad,
        string $body,
        ?string $phone = null,
        ?string $externalId = null,
        ?string $name = null,
    ): WaMessage {
        $conversation = WaConversation::query()
            ->where('external_conversation_id', $conversationId)
            ->first()
            // Si es la primera vez que llega por aquí pero ya había hilo con ese número —por ejemplo
            // porque el negocio venía del emparejamiento por QR—, se reutiliza en vez de duplicarlo.
            ?? WaConversation::query()->where('phone', $phone ?? $identidad)->first();

        if ($conversation === null) {
            $conversation = new WaConversation;
            $conversation->fill([
                // El teléfono si lo hay; si no, el identificador estable que manda Meta. La columna
                // guarda «con qué identidad escribe esta persona», y en la inmensa mayoría de los
                // casos eso es su número.
                'phone' => $phone ?? $identidad,
                'name' => $name,
                // El enlace con el CRM solo se intenta con un teléfono de verdad: buscar un cliente
                // por un BSUID no encontraría nada y encontrarlo sería peor.
                'customer_id' => $phone === null ? null : Customer::where('phone', $phone)->value('id'),
            ]);
        } elseif ($name !== null && $conversation->name === null) {
            $conversation->name = $name;
        }

        // Se refrescan siempre: una cuenta reconectada cambia de identificador, y con el viejo las
        // respuestas empezarían a fallar sin motivo aparente.
        $conversation->forceFill([
            'external_conversation_id' => $conversationId,
            'external_account_id' => $accountId,
        ])->save();

        $message = $conversation->messages()->create([
            'company_id' => $conversation->company_id,
            'direction' => MessageDirection::Inbound,
            'type' => 'text',
            'body' => $body,
            'status' => MessageStatus::Received,
            'external_id' => $externalId,
            'sent_at' => now(),
        ]);

        $conversation->update(['last_message_at' => now()]);

        WhatsAppMessageReceived::dispatch($message);

        return $message;
    }

    private function conversationFor(string $phone, ?string $name = null): WaConversation
    {
        $conversation = WaConversation::firstOrNew(['phone' => $phone]);

        if (! $conversation->exists) {
            $customer = Customer::where('phone', $phone)->first();
            $conversation->fill([
                'name' => $name,
                'customer_id' => $customer?->id,
            ])->save();
        } elseif ($name !== null && $conversation->name === null) {
            $conversation->update(['name' => $name]);
        }

        return $conversation;
    }
}
