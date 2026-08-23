<?php

declare(strict_types=1);

namespace App\Modules\WhatsApp\Services;

use App\Modules\AI\Providers\Contracts\AiProvider;
use App\Modules\Core\Support\ChatText;
use App\Modules\Help\Models\AssistantQuestion;
use App\Modules\Help\Services\AssistantQuota;
use App\Modules\WhatsApp\Models\WaBotSetting;
use App\Modules\WhatsApp\Models\WaConversation;
use App\Modules\WhatsApp\Models\WaMessage;
use App\Modules\WhatsApp\Support\ProductLookup;
use Illuminate\Support\Str;
use Throwable;

/**
 * El bot que atiende a los clientes del negocio por WhatsApp.
 *
 * Habla con CLIENTES FINALES sin nadie delante, así que las reglas son más duras que en el asistente
 * del panel: aquí una respuesta inventada no hace perder el tiempo, hace perder una venta o compromete
 * al negocio con algo que no puede cumplir.
 *
 * Tres cosas mandan sobre todo lo demás:
 *
 *  1. **Solo dice lo que el dueño escribió y lo que hay en el catálogo.** Nada de «lo que el modelo
 *     crea saber»: si no está, se aparta.
 *  2. **Se calla y llama a una persona** cuando el cliente lo pide o cuando no sabe. Un bot repitiendo
 *     «no entendí» tres veces a alguien que quiere comprar es peor que no tener bot.
 *  3. **Nunca revienta.** Corre dentro de la petición del webhook de Evolution, que reintenta lo que
 *     falla: una excepción aquí sería el mismo mensaje llegando en bucle.
 */
final class WhatsAppBot
{
    /**
     * Cuántos turnos anteriores viajan al proveedor.
     *
     * Mismo criterio que el asistente del panel: el historial se manda entero en cada consulta, así
     * que con veinte turnos la vigésimo primera pregunta paga veinte veces.
     */
    private const TURNOS = 4;

    /**
     * El freno que protege el número.
     *
     * Evolution conecta por QR usando la sesión de WhatsApp Web, no la API oficial: Meta puede
     * bloquear el número si ve automatización agresiva. Doce respuestas en diez minutos a la MISMA
     * conversación ya no es atender a un cliente —es un bucle, o alguien probando el bot—, y a partir
     * de ahí contesta una persona. Perder el bot en un caso raro es barato; perder el número del
     * negocio no lo es.
     */
    private const MAX_SEGUIDAS = 12;

    private const VENTANA_MINUTOS = 10;

    /**
     * Cómo pide alguien hablar con una persona.
     *
     * Esto se busca DENTRO de la frase —al revés que los saludos—: «ok pero quiero hablar con una
     * persona por favor» es exactamente lo que hay que cazar, y exigir igualdad lo dejaría pasar. El
     * riesgo al revés —apartarse de más— es barato: contesta el dueño.
     *
     * @var list<string>
     */
    private const PIDE_PERSONA = [
        'hablar con alguien', 'hablar con una persona', 'con una persona', 'una persona real',
        'con un humano', 'atencion al cliente', 'quiero un agente', 'hablar con el dueno',
        'hablar con el encargado', 'me atiende alguien', 'hay alguien ahi', 'necesito ayuda de verdad',
    ];

    /**
     * Cortesías. No pasan por la IA, igual que en el asistente del panel.
     *
     * Un saludo tiene una buena respuesta y siempre es la misma: no hay nada que redactar, no se
     * puede inventar nada y no cuesta dinero. Aquí se compara por IGUALDAD, así que «hola, ¿cuánto
     * vale la batida?» NO es un saludo y va a la IA como la pregunta que es.
     *
     * @var list<string>
     */
    private const SALUDOS = [
        'hola', 'holi', 'buenas', 'buenos dias', 'buenas tardes', 'buenas noches', 'saludos',
        'que tal', 'que lo que', 'klk', 'k lo k', 'hey', 'ola', 'buen dia', 'saludos cordiales',
    ];

    /** @var list<string> */
    private const GRACIAS = [
        'gracias', 'muchas gracias', 'mil gracias', 'te lo agradezco', 'ok gracias', 'listo gracias',
        'perfecto', 'excelente', 'genial', 'de lujo', 'ya esta', 'entendido', 'entiendo', 'vale',
    ];

    /** @var list<string> */
    private const DESPEDIDAS = [
        'adios', 'chao', 'chau', 'hasta luego', 'nos vemos', 'bye', 'me voy', 'hasta manana',
    ];

    public function __construct(
        private readonly AiProvider $provider,
        private readonly WhatsAppService $whatsapp,
        private readonly AssistantQuota $cuota,
        private readonly ProductLookup $catalogo,
    ) {}

    /**
     * Atiende un mensaje entrante. Devuelve qué se hizo, para el registro.
     */
    public function atender(WaMessage $entrante): string
    {
        $conversacion = $entrante->conversation;
        $companyId = (int) $entrante->company_id;
        $ajustes = WaBotSetting::paraEmpresa($companyId);

        if (! $ajustes->puedeContestar()) {
            return 'apagado';
        }

        // Ya hay una persona atendiendo: el bot no se mete. Es lo que hace que el traspaso sea de
        // verdad y no un adorno.
        if ($conversacion === null || $conversacion->bot_paused_at !== null) {
            return 'pausado';
        }

        $texto = trim((string) $entrante->body);

        if ($texto === '') {
            return 'sin texto';
        }

        if (ChatText::contieneAlguna($texto, self::PIDE_PERSONA)) {
            $this->pasarAPersona($conversacion, 'lo pidió el cliente');
            $this->enviar($conversacion, $ajustes, 'Claro, ahora mismo te atiende una persona del equipo. Dame un momento.');

            return 'traspasada';
        }

        /*
         * Las cortesías se contestan aquí y no gastan cuota.
         *
         * En el panel el tope cuenta TODAS las preguntas, también las que no llamaron al proveedor,
         * para que nadie descubra que preguntando cosas raras no gasta. Aquí ese razonamiento no
         * aplica y hace daño: quien escribe no es el dueño de la cuenta, es un desconocido. Diez
         * «klk» de un curioso dejarían al negocio sin bot para el cliente que sí iba a comprar.
         *
         * Se cuentan igualmente en `sent_count`, que es lo que mide cuánto trabajó el bot.
         */
        $cortesia = $this->cortesia($texto, $ajustes);

        if ($cortesia !== null) {
            $this->enviar($conversacion, $ajustes, $cortesia);

            return 'cortesía';
        }

        if ($this->demasiadoSeguido($conversacion)) {
            $this->pasarAPersona($conversacion, 'demasiadas respuestas seguidas');

            return 'frenado';
        }

        /*
         * El tope diario, ANTES de llamar al proveedor.
         *
         * Es el mismo contador del asistente del panel, a propósito: quien paga la IA es el operador
         * de la plataforma y le interesa un solo número que mirar, no dos.
         */
        if ($this->cuota->agotada($companyId)) {
            $this->pasarAPersona($conversacion, 'se agotó el tope diario');

            return 'sin cuota';
        }

        $respuesta = $this->redactar($ajustes, $texto, $conversacion);

        if ($respuesta === null) {
            // No sabe. Se aparta en vez de insistir.
            $this->pasarAPersona($conversacion, 'el bot no supo contestar');
            $this->anotar($conversacion, $texto, AssistantQuestion::WHATSAPP_SIN_RESPUESTA);
            $this->enviar($conversacion, $ajustes, 'Esa no te la sé contestar bien. Le paso tu mensaje a una persona del equipo y te responde enseguida.');

            return 'no supo';
        }

        $this->anotar($conversacion, $texto, AssistantQuestion::WHATSAPP_IA);
        $this->enviar($conversacion, $ajustes, $respuesta);

        return 'contestado';
    }

    /**
     * La respuesta de cortesía, o null si esto era una pregunta de verdad.
     */
    private function cortesia(string $texto, WaBotSetting $ajustes): ?string
    {
        if (ChatText::esAlgunaDe($texto, self::SALUDOS)) {
            // El saludo lo escribe el dueño: quien contesta es su negocio, no la plataforma.
            return filled($ajustes->greeting)
                ? (string) $ajustes->greeting
                : '¡Hola! Dime en qué te puedo ayudar y te respondo enseguida.';
        }

        if (ChatText::esAlgunaDe($texto, self::GRACIAS)) {
            return '¡A ti! Cualquier cosa que necesites, me escribes.';
        }

        if (ChatText::esAlgunaDe($texto, self::DESPEDIDAS)) {
            return '¡Hasta luego! Aquí estamos para lo que necesites.';
        }

        return null;
    }

    /**
     * ¿Lleva ya demasiadas respuestas en muy poco rato?
     */
    private function demasiadoSeguido(WaConversation $conversacion): bool
    {
        return $conversacion->messages()
            ->where('sent_by_bot', true)
            ->where('created_at', '>=', now()->subMinutes(self::VENTANA_MINUTOS))
            ->count() >= self::MAX_SEGUIDAS;
    }

    /**
     * Redacta con el proveedor, o null si no puede.
     *
     * Null NO es un fallo técnico necesariamente: también es «el modelo dijo que no lo sabe». Los dos
     * casos acaban igual —pasando a una persona—, que es lo correcto: al cliente le da lo mismo por
     * qué el bot no supo.
     */
    private function redactar(WaBotSetting $ajustes, string $pregunta, WaConversation $conversacion): ?string
    {
        // Sin proveedor que redacte de verdad no se contesta NADA. El proveedor local devuelve una
        // plantilla con el contexto pegado detrás: mandarle eso a un cliente sería peor que callar.
        if (! $this->provider->redactaRespuestas()) {
            return null;
        }

        $mensajes = [['role' => 'system', 'content' => $this->instrucciones($ajustes, $pregunta)]];

        foreach ($this->ultimosTurnos($conversacion) as $turno) {
            $mensajes[] = $turno;
        }

        $mensajes[] = ['role' => 'user', 'content' => $pregunta];

        try {
            $respuesta = trim($this->provider->chat($mensajes));
        } catch (Throwable $e) {
            report($e);

            return null;
        }

        /*
         * La palabra clave con la que el modelo declara que no sabe.
         *
         * Se le pide que la escriba sola, sin adornos, para poder distinguir «no sé» de una respuesta
         * de verdad sin adivinar por el texto. Si contestara «no estoy seguro, pero creo que...», eso
         * es justo lo que no queremos que le llegue a un cliente.
         */
        if ($respuesta === '' || str_contains(mb_strtolower($respuesta), 'no_lo_se')) {
            return null;
        }

        return $respuesta;
    }

    private function instrucciones(WaBotSetting $ajustes, string $pregunta): string
    {
        $productos = $this->catalogo->buscar($pregunta);

        $catalogo = $productos === []
            ? 'No se encontró ningún producto que coincida con lo que preguntó.'
            : collect($productos)->map(static fn (array $p): string => sprintf(
                '- %s: %s%s%s',
                $p['nombre'],
                number_format((float) $p['precio'], 2),
                $p['hay'] ? '' : ' (HOY NO HAY)',
                filled($p['descripcion']) ? ' — '.Str::limit((string) $p['descripcion'], 120) : '',
            ))->implode("\n");

        $negocio = (string) $ajustes->business_info;

        return <<<TXT
        Atiendes por WhatsApp a los clientes de este negocio. Escribes EN SU NOMBRE.

        Reglas, por orden de importancia:
        - Responde ÚNICAMENTE con la información de abajo. Si lo que preguntan no está ahí, responde
          exactamente NO_LO_SE y nada más. No supongas horarios, precios, plazos ni condiciones: el
          cliente va a tomar una decisión con lo que le digas y el negocio va a tener que cumplirlo.
        - Los precios salen SOLO de la lista de productos. Si no está en la lista, no tiene precio.
        - Si un producto dice HOY NO HAY, dilo claro y ofrece los otros que sí haya.
        - Sé breve: es WhatsApp, no un correo. Dos o tres frases.
        - Habla en español de República Dominicana, de tú, cercano y sin tecnicismos. Nada de
          «estimado cliente».
        - No prometas nada que no esté escrito abajo: ni descuentos, ni envíos gratis, ni plazos.

        SOBRE EL NEGOCIO:
        {$negocio}

        PRODUCTOS QUE COINCIDEN CON SU PREGUNTA:
        {$catalogo}
        TXT;
    }

    /**
     * Lo ya hablado, como turnos de verdad.
     *
     * @return list<array{role: string, content: string}>
     */
    private function ultimosTurnos(WaConversation $conversacion): array
    {
        // El último es el mensaje que estamos atendiendo: se salta, porque va aparte al final.
        $mensajes = $conversacion->messages()->latest('id')->limit(self::TURNOS * 2 + 1)->get()
            ->skip(1)->reverse();

        return $mensajes->map(static fn (WaMessage $m): array => [
            'role' => $m->direction->value === 'inbound' ? 'user' : 'assistant',
            'content' => Str::limit((string) $m->body, 800, ''),
        ])->values()->all();
    }

    /**
     * Aparta al bot de esta conversación hasta que una persona lo reanude.
     */
    private function pasarAPersona(WaConversation $conversacion, string $motivo): void
    {
        $conversacion->forceFill(['bot_paused_at' => now()])->save();

        AssistantQuestion::create([
            'company_id' => $conversacion->company_id,
            'question' => Str::limit('[WhatsApp] '.$motivo, 490, ''),
            'answered_by' => AssistantQuestion::WHATSAPP_PERSONA,
        ]);
    }

    /**
     * Manda el mensaje y lo deja marcado como del bot.
     *
     * La marca es lo que permite distinguir en la bandeja lo que contestó el bot de lo que escribió
     * una persona, y es lo que cuenta el freno de {@see demasiadoSeguido()}.
     */
    private function enviar(WaConversation $conversacion, WaBotSetting $ajustes, string $texto): void
    {
        $mensaje = $this->whatsapp->sendText((string) $conversacion->phone, $texto);
        $mensaje->forceFill(['sent_by_bot' => true])->save();

        $ajustes->forceFill(['sent_count' => (int) $ajustes->sent_count + 1, 'last_sent_at' => now()])->save();
    }

    /**
     * Deja constancia de la pregunta para el tope diario.
     *
     * Solo lo llaman los caminos que SÍ pasan por el proveedor: las cortesías no cuentan, y el
     * porqué está explicado arriba, donde se contestan.
     */
    private function anotar(WaConversation $conversacion, string $pregunta, string $origen): void
    {
        AssistantQuestion::create([
            'company_id' => $conversacion->company_id,
            'question' => Str::limit($pregunta, 490, ''),
            'answered_by' => $origen,
        ]);
    }
}
