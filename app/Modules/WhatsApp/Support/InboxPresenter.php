<?php

declare(strict_types=1);

namespace App\Modules\WhatsApp\Support;

use App\Modules\Core\Support\DbTable;
use App\Modules\WhatsApp\Enums\MessageDirection;
use App\Modules\WhatsApp\Models\WaBotSetting;
use App\Modules\WhatsApp\Models\WaConversation;
use App\Modules\WhatsApp\Models\WaMessage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Aplana la bandeja a datos serializables. La usan tanto la vista inicial como el endpoint de
 * sondeo, de modo que ambos rinden exactamente la misma forma y no hay dos verdades.
 *
 * Todo lo que la pantalla necesita para refrescarse sale de aquí, y eso incluye ahora el estado de
 * la línea: sin él, después de escanear el QR la vista tenía que pedir «Recarga esta página»,
 * porque nadie le contaba nunca que el emparejamiento había salido bien.
 */
final class InboxPresenter
{
    public function __construct(private readonly LineStatus $linea) {}

    /**
     * @return array<string, mixed>
     */
    public function payload(?string $phone = null): array
    {
        $conversations = WaConversation::query()
            ->with(['customer', 'messages' => fn ($q) => $q->latest()->limit(1)])
            ->latest('last_message_at')
            ->get();

        $active = $phone !== null && $phone !== ''
            ? $conversations->firstWhere('phone', $phone)
            : $conversations->first();

        $thread = $active?->messages()->oldest()->limit(200)->get();

        return [
            'conversations' => $conversations
                ->map(fn (WaConversation $c): array => $this->conversationRow($c))
                ->all(),

            'thread' => $thread === null
                ? []
                : $thread->map(fn (WaMessage $m): array => $this->messageRow($m))->all(),

            'active_phone' => $active === null ? null : (string) $active->phone,

            // Si el bot se apartó de ESTA conversación, la cabecera lo dice y ofrece devolvérsela.
            'active_paused' => $active?->bot_paused_at !== null,

            'line' => $this->linea->actual(),

            'bot' => $this->bot(),
        ];
    }

    /**
     * Si el bot está atendiendo, para que la pantalla lo diga sin tener que recargar.
     *
     * @return array{active: bool, ready: bool, provider: string, puede_escribir_primero: bool}
     */
    private function bot(): array
    {
        // Las migraciones se aplican a mano y el despliegue no las corre: entre que sale el código
        // y alguien migra, esta tabla no existe. Preguntar por ella sin comprobarlo tumba la bandeja
        // entera, que es exactamente lo que ya pasó una vez en la pantalla de Redes sociales.
        if (! DbTable::existe('wa_bot_settings')) {
            return [
                'active' => false,
                'ready' => false,
                'provider' => WaBotSetting::POR_QR,
                'puede_escribir_primero' => true,
            ];
        }

        /** @var WaBotSetting|null $ajustes */
        $ajustes = WaBotSetting::query()->first();

        return [
            'active' => $ajustes?->puedeContestar() ?? false,
            // Encendido pero sin nada que decir: la pantalla avisa en vez de dejar creer que atiende.
            'ready' => $ajustes !== null && filled($ajustes->business_info),
            'provider' => $ajustes?->provider ?? WaBotSetting::POR_QR,
            /*
             * Por la vía oficial NO se puede escribir a un número con el que no se ha hablado nunca:
             * Meta exige que la ventana la abra el cliente. Viaja en el sondeo para que la pantalla
             * pueda desactivar el botón con su explicación en vez de dejar que el envío falle.
             */
            'puede_escribir_primero' => $ajustes?->puedeEscribirPrimero() ?? true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function conversationRow(WaConversation $conversation): array
    {
        /** @var WaMessage|null $last */
        $last = $conversation->messages->first();

        /** @var Carbon|null $lastAt */
        $lastAt = $conversation->last_message_at;

        $name = $conversation->name === null ? null : (string) $conversation->name;
        $phone = (string) $conversation->phone;

        return [
            'phone' => $phone,
            'title' => $name ?? $phone,
            'initials' => $this->initials($name),
            'preview' => $last === null ? 'Sin mensajes' : (string) $last->body,
            'out' => $last !== null && $last->direction === MessageDirection::Outbound,
            'time' => $lastAt?->diffForHumans(short: true),
            // Que el bot se haya apartado se ve en la LISTA y no solo dentro: son los clientes que
            // están esperando a una persona, y hay que poder localizarlos de un vistazo.
            'paused' => $conversation->bot_paused_at !== null,
            'is_customer' => $conversation->customer !== null,
            // La insignia «Cliente CRM» decía que la persona está fichada y no llevaba a ninguna
            // parte: había que ir al CRM y buscarla a mano para ver qué le debía.
            'customer_url' => $conversation->customer !== null
                ? route('panel.customers.show', $conversation->customer)
                : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function messageRow(WaMessage $message): array
    {
        /** @var Carbon|null $at */
        $at = $message->sent_at ?? $message->created_at;

        return [
            'id' => $message->id,
            'out' => $message->direction === MessageDirection::Outbound,
            'body' => (string) $message->body,
            'time' => $at?->format('H:i'),
            'status' => $message->status->value,
            // Quién lo escribió. Sin esto, el dueño no puede saber qué le prometió el bot a un
            // cliente y qué dijo su empleado, que es justo lo que necesita mirar cuando algo sale mal.
            'bot' => (bool) $message->sent_by_bot,
        ];
    }

    /**
     * Iniciales solo si hay un nombre real: un número de teléfono no las produce con sentido.
     */
    private function initials(?string $name): ?string
    {
        return $name !== null && preg_match('/\p{L}/u', $name) === 1
            ? Str::upper(Str::substr($name, 0, 2))
            : null;
    }
}
