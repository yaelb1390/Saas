<?php

declare(strict_types=1);

namespace App\Modules\WhatsApp\Jobs;

use App\Modules\Core\Models\SystemEvent;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\WhatsApp\Enums\MessageDirection;
use App\Modules\WhatsApp\Models\WaMessage;
use App\Modules\WhatsApp\Services\WhatsAppBot;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Contesta al cliente, pero solo cuando ha terminado de escribir.
 *
 * EL PROBLEMA QUE RESUELVE: la gente no escribe párrafos por WhatsApp, escribe ráfagas. «hola» /
 * «buenas» / «tienen batidas?» son tres mensajes en cuatro segundos. Contestando a cada uno, el bot
 * suelta tres respuestas —dos de ellas a un saludo— y la conversación parece una máquina. Además
 * paga tres llamadas al modelo para responder una sola pregunta.
 *
 * CÓMO: cada mensaje que entra aplaza este trabajo unos segundos. Cuando le toca ejecutarse, mira si
 * entró OTRO mensaje después; si lo hubo, se va sin hacer nada, porque el trabajo de ese otro es el
 * que va a contestar. Solo el último llega hasta el final, y contesta a todo junto.
 *
 * Es un antirebote de toda la vida, y no necesita ninguna tabla: la hora del último mensaje entrante
 * ya está guardada.
 */
final class ResponderAlCliente implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Un solo intento.
     *
     * Si contestar falla, reintentarlo dos minutos después le manda al cliente una respuesta a
     * destiempo, cuando ya se cansó de esperar o ya le atendió una persona. Es mejor no contestar
     * que contestar tarde y descolocado; el mensaje sigue en la bandeja para que lo vea alguien.
     */
    public int $tries = 1;

    /**
     * Cuántos mensajes de la ráfaga se juntan como mucho.
     *
     * Hay quien escribe palabra por palabra, y hay quien se enfada y manda treinta líneas. Sin tope,
     * esa ráfaga entera se iría al modelo en una sola pregunta: se paga por todo y encima la pregunta
     * de verdad queda enterrada. Con diez se cubre de sobra la ráfaga normal.
     */
    private const MAX_AGRUPADOS = 10;

    public function __construct(public readonly WaMessage $message) {}

    public function handle(CurrentCompany $currentCompany, WhatsAppBot $bot): void
    {
        $currentCompany->set((int) $this->message->company_id);

        if ($this->llegoOtroDespues()) {
            return;
        }

        try {
            $bot->atender($this->message, $this->loQueQuedoSinContestar());
        } catch (Throwable $e) {
            /*
             * El fallo se APUNTA, no se propaga.
             *
             * Este registro venía del listener y se conserva a propósito: es lo que se mira cuando
             * alguien dice «el bot dejó de contestar». Sin él, el trabajo fallaría en silencio dentro
             * del worker, donde nadie del negocio va a mirar nunca.
             *
             * Y no se relanza porque no hay nada que reintentar: el mensaje del cliente YA está
             * guardado y visible en la bandeja, así que no se pierde nada. Lo atiende una persona.
             */
            report($e);

            SystemEvent::registrar(
                type: 'integration.failed',
                message: 'El bot de WhatsApp no pudo contestar',
                contexto: ['motivo' => $e->getMessage()],
                level: SystemEvent::AVISO,
                companyId: (int) $this->message->company_id,
            );
        }
    }

    /**
     * Todo lo que el cliente escribió y todavía no se le ha contestado, en una sola pregunta.
     *
     * El corte lo marca la ÚLTIMA respuesta del negocio: lo que hay después está sin contestar, por
     * definición y sin necesidad de ninguna marca en la base de datos. Si no ha habido respuesta
     * nunca, es toda la conversación (con el tope de arriba).
     *
     * Contestar solo al último mensaje sería lo fácil y estaría mal: el último trozo de una ráfaga
     * suele ser el que menos dice —«por favor», «fría», «para hoy»— y es justo el que se leería.
     */
    private function loQueQuedoSinContestar(): string
    {
        $ultimaRespuesta = WaMessage::query()
            ->where('wa_conversation_id', $this->message->wa_conversation_id)
            ->where('direction', MessageDirection::Outbound)
            ->max('id');

        $pendientes = WaMessage::query()
            ->where('wa_conversation_id', $this->message->wa_conversation_id)
            ->where('direction', MessageDirection::Inbound)
            ->when($ultimaRespuesta !== null, fn ($q) => $q->where('id', '>', $ultimaRespuesta))
            // Nada posterior a este mensaje: si hubiera algo, este trabajo ya se habría ido arriba.
            ->where('id', '<=', $this->message->id)
            // De atrás hacia delante para que el tope se coma lo VIEJO y no lo último, que es lo que
            // suele traer la pregunta. Se reordena después.
            ->latest('id')
            ->limit(self::MAX_AGRUPADOS)
            ->pluck('body', 'id')
            ->sortKeys();

        return trim($pendientes->implode("\n"));
    }

    /**
     * ¿Entró otro mensaje del cliente después de este?
     *
     * Se compara por identificador y no solo por la hora: dos mensajes seguidos pueden llegar dentro
     * del mismo segundo, y por hora empatarían. El identificador siempre crece.
     */
    private function llegoOtroDespues(): bool
    {
        return WaMessage::query()
            ->where('wa_conversation_id', $this->message->wa_conversation_id)
            ->where('direction', MessageDirection::Inbound)
            ->where('id', '>', $this->message->id)
            ->exists();
    }
}
