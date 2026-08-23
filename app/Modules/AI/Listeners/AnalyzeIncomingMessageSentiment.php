<?php

declare(strict_types=1);

namespace App\Modules\AI\Listeners;

use App\Modules\AI\Services\SentimentService;
use App\Modules\Core\Models\SystemEvent;
use App\Modules\WhatsApp\Events\WhatsAppMessageReceived;
use Throwable;

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

        if ($body === null || $body === '') {
            return;
        }

        try {
            $this->sentiment->analyzeModel($event->message, $body);
        } catch (Throwable $e) {
            /*
             * Clasificar el humor de un mensaje NO puede tumbar la recepción de ese mensaje.
             *
             * Esto corre dentro de la petición del webhook de Evolution —en producción no hay colas,
             * así que no hay dónde apartarlo—, y Evolution reintenta lo que le falla. Sin este
             * `catch`, un rato malo del proveedor de IA convertía cada mensaje entrante en un 500, y
             * el mismo mensaje volvía una y otra vez.
             *
             * El mensaje YA está guardado cuando esto se ejecuta: lo único que se pierde es la
             * etiqueta de sentimiento, que es un adorno al lado de no recibir lo que te escriben.
             */
            report($e);

            SystemEvent::registrar(
                type: 'integration.failed',
                message: 'No se pudo clasificar el sentimiento de un mensaje de WhatsApp',
                contexto: ['motivo' => $e->getMessage()],
                level: SystemEvent::AVISO,
                companyId: (int) $event->message->company_id,
            );
        }
    }
}
