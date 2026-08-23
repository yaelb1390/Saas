<?php

declare(strict_types=1);

namespace App\Modules\WhatsApp\Listeners;

use App\Modules\Core\Models\SystemEvent;
use App\Modules\WhatsApp\Events\WhatsAppMessageReceived;
use App\Modules\WhatsApp\Services\WhatsAppBot;
use Throwable;

/**
 * Cuando entra un mensaje de un cliente, que le conteste el bot.
 *
 * NO implementa `ShouldQueue`, y no por descuido: en producción no hay procesos en segundo plano
 * —`QUEUE_CONNECTION=sync`—, así que encolarlo no lo sacaría de la petición, solo lo escondería.
 * Corre dentro del webhook de Evolution, y de ahí sale la regla de abajo.
 */
final class ReplyToCustomer
{
    public function __construct(private readonly WhatsAppBot $bot) {}

    public function handle(WhatsAppMessageReceived $evento): void
    {
        try {
            $this->bot->atender($evento->message);
        } catch (Throwable $e) {
            /*
             * ESTE `catch` NO ES OPCIONAL.
             *
             * Evolution reintenta lo que le falla. Una excepción aquí saldría por el webhook como un
             * 500, Evolution volvería a mandar el mismo mensaje, volvería a fallar, y el cliente
             * acabaría recibiendo el mismo texto varias veces —o la cuenta gastando cuota en bucle—.
             *
             * Se deja constancia en el registro del sistema, que es donde se mira cuando alguien dice
             * «el bot dejó de contestar», y se sigue como si nada: el mensaje del cliente YA está
             * guardado y visible en la bandeja, así que no se pierde nada. Lo atiende una persona.
             */
            report($e);

            SystemEvent::registrar(
                type: 'integration.failed',
                message: 'El bot de WhatsApp no pudo contestar',
                contexto: ['motivo' => $e->getMessage()],
                level: SystemEvent::AVISO,
                companyId: (int) $evento->message->company_id,
            );
        }
    }
}
