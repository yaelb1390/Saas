<?php

declare(strict_types=1);

namespace App\Modules\WhatsApp\Gateways;

/**
 * Gestión del vínculo con la línea de WhatsApp (estado de sesión y emparejamiento por QR).
 *
 * Se separa de WhatsAppGateway (envío) siguiendo el principio de segregación de interfaces:
 * el dominio que envía mensajes no necesita conocer nada del ciclo de vida de la instancia.
 */
interface WhatsAppConnection
{
    /**
     * Estado actual de la línea.
     *
     * @return array{state: string, instance: string, connected: bool}
     */
    public function status(): array;

    /**
     * Inicia (o reanuda) el emparejamiento y devuelve el QR en base64 si hace falta escanearlo.
     *
     * @return array{state: string, qr: ?string}
     */
    public function connect(): array;
}
