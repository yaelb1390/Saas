<?php

declare(strict_types=1);

namespace App\Modules\WhatsApp\Gateways;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Gateway de reserva para desarrollo local: no envía nada, solo registra el mensaje y devuelve
 * un identificador simulado. Se usa cuando Evolution API no está configurado.
 */
final class LogWhatsAppGateway implements WhatsAppConnection, WhatsAppGateway
{
    public function sendText(string $phone, string $body): array
    {
        Log::info('WhatsApp (log gateway) → sin envío real', [
            'phone' => $phone,
            'body' => $body,
        ]);

        return [
            'external_id' => 'log-'.Str::uuid()->toString(),
            'status' => 'sent',
        ];
    }

    public function status(): array
    {
        return ['state' => 'log', 'instance' => 'log', 'connected' => false];
    }

    public function connect(): array
    {
        return ['state' => 'log', 'qr' => null];
    }
}
