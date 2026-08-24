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

    /**
     * Dice que SÍ adjunta, aunque no mande nada.
     *
     * Es lo correcto para el gateway de desarrollo: si dijera que no, en local nunca se recorrería
     * el camino del adjunto y ese código solo se estrenaría en producción, con un cliente delante.
     */
    public function puedeEnviarDocumentos(): bool
    {
        return true;
    }

    public function sendDocument(string $phone, string $url, string $fileName, string $caption = ''): array
    {
        Log::info('WhatsApp (log gateway) → documento sin envío real', [
            'phone' => $phone,
            'url' => $url,
            'file' => $fileName,
            'caption' => $caption,
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
        return ['state' => 'log', 'qr' => null, 'url' => null];
    }

    public function logout(): bool
    {
        // No hay nada que desvincular: nunca hubo línea.
        return false;
    }
}
