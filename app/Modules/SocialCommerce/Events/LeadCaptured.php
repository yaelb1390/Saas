<?php

declare(strict_types=1);

namespace App\Modules\SocialCommerce\Events;

use App\Modules\SocialCommerce\Models\Conversation;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Se abrió una conversación de Instagram nueva a partir de una regla de Social Commerce.
 *
 * Punto de enganche para automatizaciones externas (n8n) — mismo papel que
 * `App\Modules\WhatsApp\Events\WhatsAppMessageReceived` para ese módulo. Sin consumidor interno en
 * esta entrega (ver SOCIAL_COMMERCE_ARCHITECTURE.md, sección 3): nada escucha este evento todavía.
 */
final class LeadCaptured
{
    use Dispatchable;

    public function __construct(public readonly Conversation $conversation) {}
}
