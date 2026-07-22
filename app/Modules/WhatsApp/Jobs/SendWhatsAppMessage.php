<?php

declare(strict_types=1);

namespace App\Modules\WhatsApp\Jobs;

use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\WhatsApp\Enums\MessageStatus;
use App\Modules\WhatsApp\Gateways\WhatsAppGateway;
use App\Modules\WhatsApp\Models\WaMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Entrega el mensaje al proveedor (Evolution API) fuera del ciclo de la petición.
 *
 * El usuario ve el mensaje al instante como "Pendiente"; la cola lo envía y lo marca como
 * "Enviado" o "Fallido". Sin esto, cada envío bloqueaba la petición ~1-2 s esperando a WhatsApp.
 */
final class SendWhatsAppMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 10;

    public function __construct(private readonly WaMessage $message) {}

    public function handle(WhatsAppGateway $gateway, CurrentCompany $currentCompany): void
    {
        // El worker no tiene sesión: hay que restablecer el tenant para que el gateway
        // resuelva la instancia correcta (una línea de WhatsApp por empresa).
        $currentCompany->set((int) $this->message->company_id);

        $conversation = $this->message->conversation;

        try {
            $result = $gateway->sendText($conversation->phone, (string) $this->message->body);
        } catch (Throwable $e) {
            $this->message->update(['status' => MessageStatus::Failed]);

            throw $e;
        }

        $this->message->update([
            'status' => MessageStatus::Sent,
            'external_id' => $result['external_id'],
            'sent_at' => now(),
        ]);

        $conversation->update(['last_message_at' => now()]);
    }

    /**
     * Si se agotan los reintentos, el mensaje queda marcado como fallido para el usuario.
     */
    public function failed(Throwable $e): void
    {
        $this->message->update(['status' => MessageStatus::Failed]);
    }
}
