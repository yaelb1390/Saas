<?php

declare(strict_types=1);

namespace App\Modules\WhatsApp\Support;

use App\Modules\Core\Support\DbTable;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\WhatsApp\Enums\MessageDirection;
use App\Modules\WhatsApp\Models\WaBotSetting;
use App\Modules\WhatsApp\Models\WaConversation;
use App\Modules\WhatsApp\Models\WaMessage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
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

            'thread' => $thread === null ? [] : $this->hilo($thread),

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
     * @return array{active: bool, ready: bool, provider: string, puede_escribir_primero: bool, falta: string|null}
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
                'falta' => null,
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

            /*
             * Qué falta para que la vía elegida pueda siquiera intentarlo.
             *
             * Sin esto, la pantalla ofrecía «Conectar con Meta» a una empresa sin clave de Zernio, y
             * al pulsarlo salía «WhatsApp no está configurado en este servidor» —que es mentira: el
             * servidor está bien, lo que falta es la clave de ESA empresa—. Ofrecer un botón que no
             * puede funcionar y culpar de ello al servidor es la peor combinación posible.
             */
            'falta' => $this->queFalta($ajustes),
        ];
    }

    /**
     * Lo que impide que la vía elegida funcione, o null si no falta nada.
     */
    private function queFalta(?WaBotSetting $ajustes): ?string
    {
        if ($ajustes?->usaZernio() === true) {
            // La vía oficial pasa por Zernio, y la clave vive en la empresa: se pone en Redes
            // sociales. Sin ella no hay a quién preguntarle por la línea.
            return filled(app(CurrentCompany::class)->model()?->social_api_key) ? null : 'clave';
        }

        // La del QR necesita un Evolution al que llegar, y eso es del servidor, no de la empresa.
        return filled(config('evolution.base_url')) ? null : 'servidor';
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
     * El hilo, con lo que cada mensaje necesita saber DEL ANTERIOR.
     *
     * Agrupar y poner separadores de fecha se decide comparando con el de arriba, y eso no se puede
     * hacer mensaje a mensaje. Se resuelve aquí y no en el navegador para que el sondeo y la primera
     * carga rindan exactamente lo mismo: si lo calculara Alpine, habría dos maneras de agrupar y
     * acabarían discrepando.
     *
     * @param  Collection<int, WaMessage>  $mensajes
     * @return array<int, array<string, mixed>>
     */
    private function hilo($mensajes): array
    {
        $anterior = null;
        $filas = [];

        foreach ($mensajes as $mensaje) {
            $filas[] = $this->messageRow($mensaje, $anterior);
            $anterior = $mensaje;
        }

        return $filas;
    }

    /**
     * @return array<string, mixed>
     */
    private function messageRow(WaMessage $message, ?WaMessage $anterior = null): array
    {
        /*
         * EN LA HORA DEL NEGOCIO.
         *
         * Se guardan en UTC, y aquí son cuatro horas menos: sin convertir, un mensaje de las ocho de
         * la noche se leía como las doce. En un chat eso no es un detalle —se mira la hora para saber
         * si hace rato que alguien espera— y se comprobó en pantalla: un mensaje recién llegado
         * aparecía marcado a las 04:01 cuando eran las 00:01.
         */
        $at = ($message->sent_at ?? $message->created_at)?->timezone(config('app.business_timezone'));
        $antes = ($anterior?->sent_at ?? $anterior?->created_at)?->timezone(config('app.business_timezone'));

        $mismoEmisor = $anterior !== null
            && $anterior->direction === $message->direction
            && (bool) $anterior->sent_by_bot === (bool) $message->sent_by_bot;

        return [
            'id' => $message->id,
            'out' => $message->direction === MessageDirection::Outbound,
            'body' => (string) $message->body,
            'time' => $at?->format('H:i'),
            'status' => $message->status->value,

            // El día, cuando cambia respecto al mensaje de arriba. Es lo que más se echa en falta
            // al mirar un hilo largo: sin esto, ayer y hoy son la misma pared de burbujas.
            'separador' => $at !== null && ($antes === null || ! $at->isSameDay($antes))
                ? $this->diaLegible($at)
                : null,

            /*
             * ¿Continúa lo que venía diciendo el mismo?
             *
             * Tres «Hola» seguidos son tres burbujas con su cola cada una, y eso no se parece a un
             * chat. Se agrupan si es el mismo emisor y no han pasado cinco minutos: más tiempo ya es
             * otra intervención, aunque la escriba la misma persona.
             */
            'seguido' => $mismoEmisor
                && $at !== null && $antes !== null
                /*
                 * `abs`, y no es cosmético.
                 *
                 * `diffInMinutes` devuelve la diferencia CON SIGNO: como el mensaje nuevo es
                 * posterior al anterior, salía −40, y −40 sí es menor que 5. Con eso se agrupaba
                 * todo lo del mismo emisor por lejos que estuviera en el tiempo.
                 */
                && abs($at->diffInMinutes($antes)) < 5
                && $at->isSameDay($antes),
            // Quién lo escribió. Sin esto, el dueño no puede saber qué le prometió el bot a un
            // cliente y qué dijo su empleado, que es justo lo que necesita mirar cuando algo sale mal.
            'bot' => (bool) $message->sent_by_bot,
        ];
    }

    /**
     * «Hoy», «Ayer», o el día escrito.
     *
     * El año solo cuando no es este: en un hilo de esta semana, leer «2026» en cada separador es
     * ruido.
     */
    private function diaLegible(Carbon $fecha): string
    {
        if ($fecha->isToday()) {
            return 'Hoy';
        }

        if ($fecha->isYesterday()) {
            return 'Ayer';
        }

        return $fecha->isCurrentYear()
            ? $fecha->translatedFormat('j \d\e F')
            : $fecha->translatedFormat('j \d\e F \d\e Y');
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
