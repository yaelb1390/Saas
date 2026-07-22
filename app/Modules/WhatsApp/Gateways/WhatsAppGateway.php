<?php

declare(strict_types=1);

namespace App\Modules\WhatsApp\Gateways;

/**
 * Abstracción del proveedor de WhatsApp. Permite intercambiar Evolution API por otro proveedor
 * (o por un doble en tests) sin tocar la lógica de negocio.
 */
interface WhatsAppGateway
{
    /**
     * Envía un mensaje de texto.
     *
     * @return array{external_id: ?string, status: string}
     */
    public function sendText(string $phone, string $body): array;
}
