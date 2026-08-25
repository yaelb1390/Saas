<?php

declare(strict_types=1);

namespace App\Modules\WhatsApp\Listeners;

use App\Modules\Core\Support\DbTable;
use App\Modules\WhatsApp\Events\WhatsAppMessageReceived;
use App\Modules\WhatsApp\Jobs\ResponderAlCliente;
use App\Modules\WhatsApp\Jobs\TranscribeVoiceNote;
use App\Modules\WhatsApp\Models\WaBotSetting;
use App\Modules\WhatsApp\Models\WaMessage;
use App\Modules\WhatsApp\Support\MensajeEntrante;

/**
 * Cuando entra un mensaje de un cliente, decidir qué se hace con él.
 *
 * Este listener corre DENTRO del webhook de Evolution, así que lo único que puede hacer es repartir
 * trabajo y salir rápido. Evolution reintenta lo que tarda: una petición lenta acaba mandando el
 * mismo mensaje otra vez, y el cliente recibiendo la misma respuesta dos y tres veces.
 *
 * Antes contestaba aquí mismo, en la propia petición, porque en producción no hay procesos en
 * segundo plano. Eso ponía un techo a lo que se podía hacer: nada que tardara —transcribir un audio,
 * esperar a que el cliente termine de escribir— cabía dentro. Ahora se encola.
 *
 * >>> ESTO EXIGE UN WORKER. Con `QUEUE_CONNECTION=sync` los trabajos corren dentro de la petición y
 *     el aplazamiento no aplaza nada: el agrupado deja de funcionar y la transcripción vuelve a
 *     alargar el webhook. En local hay worker; producción necesita uno antes de que esto llegue.
 */
final class ReplyToCustomer
{
    /** Cuánto se espera por si el cliente sigue escribiendo, cuando no hay nada configurado. */
    private const ESPERA_POR_OMISION = 8;

    public function handle(WhatsAppMessageReceived $evento): void
    {
        $mensaje = $evento->message;

        /*
         * Una nota de voz se manda a transcribir Y AQUÍ NO SE ENCOLA NINGUNA RESPUESTA.
         *
         * La respuesta la encola el propio trabajo de transcripción cuando ya hay texto que leer.
         * Encolarla también aquí, con un margen de segundos por delante, parecía lo natural y era una
         * carrera: si la transcripción tardaba un poco más que el margen, el bot contestaba al rótulo
         * «🎤 Nota de voz» —no a lo que dijo el cliente— y, al no entenderlo, apartaba la conversación
         * para una persona. Resultado: una transcripción LENTA dejaba el bot apagado con ese cliente
         * para el resto de la conversación, aunque el texto llegara medio segundo después.
         *
         * No hay margen que arregle eso, porque el que acierta hoy falla el día que el proveedor va
         * despacio. Se arregla quitando la carrera: contesta quien sabe que ya hay algo que contestar.
         */
        if ($mensaje->type === 'audio') {
            TranscribeVoiceNote::dispatch($mensaje);

            return;
        }

        /*
         * Lo que no se puede leer —una foto sin pie, una ubicación— se queda en la bandeja y lo
         * atiende una persona. El bot no tiene con qué responder: inventaría algo sobre una imagen
         * que no ha visto.
         */
        if (in_array($mensaje->type, MensajeEntrante::sinTextoQueLeer(), true)) {
            return;
        }

        // Y el caso normal: se espera unos segundos por si sigue escribiendo. Ver ResponderAlCliente.
        ResponderAlCliente::dispatch($mensaje)->delay(now()->addSeconds($this->espera($mensaje)));
    }

    /**
     * Cuántos segundos se espera antes de contestar.
     *
     * Lo decide cada negocio: una ferretería que contesta pedidos largos querrá más margen que un
     * colmado. Cero desactiva la espera y contesta al momento, como antes.
     */
    private function espera(WaMessage $mensaje): int
    {
        /*
         * La tabla puede NO EXISTIR, y eso no es una rareza de laboratorio.
         *
         * Aquí las migraciones se aplican a mano y el despliegue no las corre: entre que sale el
         * código y alguien migra pasan minutos o un fin de semana entero. Preguntar por la columna
         * sin comprobarlo lanzaría desde dentro del webhook, Evolution reintentaría el mensaje en
         * bucle y el negocio dejaría de recibir lo que le escriben sus clientes.
         *
         * El try/catch que cubría esto se fue con la respuesta al trabajo en cola. Aquí ya no hay
         * red debajo, así que la comprobación tiene que ser explícita.
         */
        if (! DbTable::tieneColumna('wa_bot_settings', 'group_seconds')) {
            return self::ESPERA_POR_OMISION;
        }

        /*
         * Sin el filtro automático por empresa: aquí no hay empresa activa —esto corre dentro del
         * webhook, sin sesión— y el mensaje ya dice de quién es. El company_id del mensaje es la
         * autoridad; dejar que el scope decida sería depender de un contexto que no existe.
         */
        $ajustes = WaBotSetting::withoutGlobalScopes()
            ->where('company_id', $mensaje->company_id)
            ->first();

        return max(0, (int) ($ajustes?->group_seconds ?? self::ESPERA_POR_OMISION));
    }
}
