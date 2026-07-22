<?php

declare(strict_types=1);

namespace App\Modules\AI\Listeners;

use App\Modules\AI\Services\SentimentService;
use App\Modules\WhatsApp\Events\WhatsAppMessageReceived;

/**
 * Al recibir un mensaje de WhatsApp, clasifica su sentimiento automáticamente.
 * La IA consume el evento de dominio de WhatsApp; WhatsApp no depende de la IA.
 */
final class AnalyzeIncomingMessageSentiment
{
    public function __construct(private readonly SentimentService $sentiment) {}

    public function handle(WhatsAppMessageReceived $event): void
    {
        $body = $event->message->body;

        if ($body !== null && $body !== '') {
            $this->sentiment->analyzeModel($event->message, $body);
        }
    }
}
