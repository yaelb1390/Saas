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

/**
 * Orquesta el envío y la recepción de mensajes de WhatsApp. Persiste toda la conversación para
 * trazabilidad y enlaza automáticamente con el cliente del CRM cuando el teléfono coincide.
 *
 * El diálogo con el proveedor vive en SendWhatsAppMessage (cola): este servicio no toca la red.
 */
final class WhatsAppService
{
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
     * Registra un mensaje entrante (invocado desde el webhook de Evolution).
     */
    public function recordInbound(string $phone, string $body, ?string $externalId = null, ?string $name = null): WaMessage
    {
        $conversation = $this->conversationFor($phone, $name);

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
