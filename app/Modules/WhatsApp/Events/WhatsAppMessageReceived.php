<?php

declare(strict_types=1);

namespace App\Modules\WhatsApp\Events;

use App\Modules\WhatsApp\Models\WaMessage;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Se dispara al recibir un mensaje entrante de WhatsApp. Punto de enganche para respuestas
 * automáticas, clasificación de sentimiento (IA) y automatizaciones (n8n).
 */
final class WhatsAppMessageReceived
{
    use Dispatchable;

    public function __construct(public readonly WaMessage $message) {}
}
