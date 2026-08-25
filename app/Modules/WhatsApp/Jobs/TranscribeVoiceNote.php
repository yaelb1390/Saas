<?php

declare(strict_types=1);

namespace App\Modules\WhatsApp\Jobs;

use App\Modules\AI\Providers\Contracts\AiProvider;
use App\Modules\Core\Support\DbTable;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\WhatsApp\Models\WaBotSetting;
use App\Modules\WhatsApp\Models\WaMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Convierte la nota de voz de un cliente en texto y lo escribe en el mensaje que ya está guardado.
 *
 * VA EN COLA, y no es un capricho. Bajar el audio de Evolution y pasarlo por el modelo son varios
 * segundos; hacerlo dentro del webhook lo dejaría colgado, y Evolution reintenta lo que tarda: el
 * cliente acabaría recibiendo la misma respuesta dos y tres veces. Fuera de la petición, el webhook
 * contesta al instante y el mensaje ya está en la bandeja aunque esto tarde o falle.
 *
 * NO crea un mensaje nuevo: reescribe el cuerpo del que ya existe. Un segundo mensaje con la
 * transcripción dejaría la conversación diciendo las cosas dos veces, y el dueño no sabría cuál de
 * las dos filas es la que mandó su cliente.
 */
final class TranscribeVoiceNote implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Dos intentos, no más.
     *
     * Si el audio no se pudo bajar o el modelo no lo entendió, insistir cinco veces no lo va a
     * arreglar y mientras tanto el cliente sigue esperando. Con dos se cubre el corte de red
     * pasajero y se deja de gastar.
     */
    public int $tries = 2;

    public int $backoff = 5;

    /** La misma espera que usa el listener cuando la empresa no ha configurado ninguna. */
    private const ESPERA_POR_OMISION = 8;

    public function __construct(public readonly WaMessage $message) {}

    public function handle(CurrentCompany $currentCompany): void
    {
        // La empresa PRIMERO: sin ella, el proveedor de IA se resolvería con la clave de otra o con
        // ninguna. Es el mismo tropiezo que ya costó caro en el envío de mensajes.
        $currentCompany->set((int) $this->message->company_id);

        $proveedor = app(AiProvider::class);

        if (! $proveedor->puedeTranscribir()) {
            $this->queLoAtiendaUnaPersona();

            return;
        }

        $audio = $this->bajarElAudio();

        if ($audio === null) {
            $this->queLoAtiendaUnaPersona();

            return;
        }

        $texto = trim($proveedor->transcribe($audio['base64'], $audio['mime']));

        /*
         * Si no se entiende nada, se deja el rótulo.
         *
         * Escribir «(no se entiende)» en la bandeja sería peor que el rótulo: parece que el cliente
         * dijo eso. Con «🎤 Nota de voz» el dueño sabe que hay un audio y lo escucha él.
         */
        if ($texto === '' || str_contains(mb_strtolower($texto), 'no se entiende')) {
            $this->queLoAtiendaUnaPersona();

            return;
        }

        $this->message->forceFill([
            'body' => $texto,
            // Sigue siendo un audio: la bandeja lo marca como tal para que se sepa que ese texto es
            // una transcripción y no algo que el cliente escribió.
            'type' => 'audio',
        ])->save();

        /*
         * AHORA sí se encola la respuesta, y no antes.
         *
         * Este es el único punto del sistema que sabe que ya hay algo que contestar. El listener no
         * puede saberlo —tendría que adivinar cuánto tarda el proveedor— y adivinar era exactamente
         * el fallo: cuando se quedaba corto, el bot le contestaba al rótulo.
         *
         * La espera de siempre se conserva por si el cliente manda un audio y luego escribe algo más:
         * se contesta a las dos cosas juntas.
         */
        ResponderAlCliente::dispatch($this->message)->delay(now()->addSeconds($this->espera()));
    }

    /**
     * No se pudo leer el audio: se aparta el bot y se marca para que lo coja alguien.
     *
     * Es lo que hace que en la bandeja aparezca «Te espera» junto a esa conversación. Sin esto, un
     * audio que no se pudo transcribir se quedaría ahí abajo, callado y sin distinguirse de los que
     * ya están atendidos, hasta que el dueño bajara a mirar por casualidad.
     *
     * NO se le manda nada al cliente. Un «no te entendí» automático a quien acaba de mandar una nota
     * de voz suele provocar OTRA nota de voz para aclararse, que tampoco se va a poder transcribir.
     */
    private function queLoAtiendaUnaPersona(): void
    {
        $conversacion = $this->message->conversation;

        if ($conversacion !== null && $conversacion->bot_paused_at === null) {
            $conversacion->forceFill(['bot_paused_at' => now()])->save();
        }
    }

    /** Los segundos que espera el bot antes de contestar, según lo que haya pedido la empresa. */
    private function espera(): int
    {
        if (! DbTable::tieneColumna('wa_bot_settings', 'group_seconds')) {
            return self::ESPERA_POR_OMISION;
        }

        $ajustes = WaBotSetting::withoutGlobalScopes()
            ->where('company_id', $this->message->company_id)
            ->first();

        return max(0, (int) ($ajustes?->group_seconds ?? self::ESPERA_POR_OMISION));
    }

    /**
     * Le pide el audio a Evolution.
     *
     * La llave del mensaje se RECONSTRUYE en vez de guardarla: Evolution necesita el identificador,
     * el destinatario y si es propio, y las tres cosas ya están —el identificador en `external_id` y
     * el teléfono en la conversación—. Guardar una copia habría sido una columna más que mantener
     * sincronizada con lo mismo.
     *
     * @return array{base64: string, mime: string}|null
     */
    private function bajarElAudio(): ?array
    {
        $base = rtrim((string) config('evolution.base_url'), '/');
        $instancia = $this->nombreDeInstancia();

        if ($base === '' || $instancia === '' || blank($this->message->external_id)) {
            return null;
        }

        try {
            $respuesta = Http::withHeaders(['apikey' => (string) config('evolution.api_key')])
                ->timeout(30)
                ->post($base.'/chat/getBase64FromMediaMessage/'.$instancia, [
                    'message' => [
                        'key' => [
                            'id' => (string) $this->message->external_id,
                            'remoteJid' => $this->message->conversation?->phone.'@s.whatsapp.net',
                            'fromMe' => false,
                        ],
                    ],
                    // Que lo convierta él: así llega en un formato que el modelo entiende sin que
                    // aquí haya que tocar bytes.
                    'convertToMp4' => false,
                ]);

            if (! $respuesta->successful()) {
                return null;
            }

            $base64 = (string) $respuesta->json('base64', '');

            if ($base64 === '') {
                return null;
            }

            return [
                'base64' => $base64,
                'mime' => (string) $respuesta->json('mimetype', 'audio/ogg'),
            ];
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * El nombre que Evolution le dio a la instancia de esta empresa.
     *
     * Es el MISMO criterio que usa EvolutionGateway al enviar —el slug de la empresa, y si no lo
     * tiene, el de la configuración—. Calcularlo de otra manera aquí funcionaría hasta el día en que
     * una empresa no tuviera slug, y entonces se pediría el audio a una instancia que no existe.
     */
    private function nombreDeInstancia(): string
    {
        $slug = $this->message->conversation?->company?->slug;

        return (string) ($slug ?? config('evolution.instance') ?? 'default');
    }

    /**
     * Si se agotan los intentos, no pasa nada malo.
     *
     * El mensaje sigue en la bandeja con su rótulo y el dueño puede escuchar el audio en su teléfono.
     * Se deja constancia para saber que la transcripción está fallando, que es distinto de que nadie
     * la haya pedido.
     */
    public function failed(Throwable $e): void
    {
        report($e);
    }
}
