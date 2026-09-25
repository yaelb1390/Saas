<?php

declare(strict_types=1);

namespace App\Modules\SocialCommerce\Enums;

/**
 * Dirección de un mensaje de Instagram, tal como la manda Zernio en `message.received`
 * (`incoming`/`outgoing` — comprobado en {@see \App\Modules\Social\Http\Controllers\ZernioWebhookController}).
 *
 * Se declara aparte de `App\Modules\WhatsApp\Enums\MessageDirection` a propósito: son dos
 * productos vendidos por separado y no deben acoplarse entre sí, aunque el valor coincida.
 */
enum MessageDirection: string
{
    case Incoming = 'incoming';
    case Outgoing = 'outgoing';
}
