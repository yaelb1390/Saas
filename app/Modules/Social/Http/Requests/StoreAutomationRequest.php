<?php

declare(strict_types=1);

namespace App\Modules\Social\Http\Requests;

use App\Modules\Social\Enums\AutomationTrigger;
use App\Modules\Social\Enums\KeywordMatch;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Lo que se manda al crear o cambiar una respuesta automática.
 *
 * Aquí se aplican las reglas que la API impone de verdad —comprobadas contra ella, no leídas en el
 * manual— para que el cliente vea el motivo en su formulario y no un fallo genérico diez segundos
 * después:
 *
 *   · El mensaje privado baja de ~1000 a 640 caracteres cuando lleva botón.
 *   · «Responder también por privado» exige al menos una palabra clave: sin ninguna significaría
 *     «responde a cualquier mensaje», y Zernio lo rechaza.
 *   · Un botón sin dirección no sirve de nada.
 */
final class StoreAutomationRequest extends FormRequest
{
    /** Tope de Zernio cuando el mensaje lleva botones. */
    private const MAX_DM_CON_BOTON = 640;

    private const MAX_DM = 1000;

    /**
     * Cuántas versiones alternativas admite Zernio, además de la principal.
     *
     * Son 5, o sea 6 textos distintos rotando. El tope es de la API y no una decisión nuestra:
     * mandar una sexta hace que rechace la automatización entera.
     */
    private const MAX_VARIACIONES = 5;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:80'],
            'account_id' => ['required', 'string'],
            // Vacío = en todas las publicaciones. Si viene, trae los DOS identificadores unidos por
            // «|»: el de Zernio y el de la red, que es lo que la API exige para apuntar a una sola.
            'post' => ['nullable', 'string', 'max:200'],
            // Qué la dispara: un comentario o la respuesta a una historia. Solo se elige al CREAR;
            // cambiarlo después desata la automatización de su publicación (lo dice su
            // especificación), así que al editar no se ofrece.
            'trigger' => ['nullable', Rule::enum(AutomationTrigger::class)],
            'keywords' => ['required', 'string', 'max:500'],
            'match_mode' => ['nullable', Rule::enum(KeywordMatch::class)],
            'comment_reply' => ['nullable', 'string', 'max:500'],
            'dm_message' => ['required', 'string', 'max:'.($this->llevaBoton() ? self::MAX_DM_CON_BOTON : self::MAX_DM)],
            // Versiones alternativas. Mismo tope de caracteres que la principal: Zernio manda una
            // cualquiera de las seis, así que una que se pase rechazaría la automatización entera.
            'dm_variations' => ['sometimes', 'array', 'max:'.self::MAX_VARIACIONES],
            'dm_variations.*' => ['nullable', 'string', 'max:'.($this->llevaBoton() ? self::MAX_DM_CON_BOTON : self::MAX_DM)],
            'reply_variations' => ['sometimes', 'array', 'max:'.self::MAX_VARIACIONES],
            'reply_variations.*' => ['nullable', 'string', 'max:500'],
            'button_title' => ['nullable', 'string', 'max:20'],
            // Un botón sin dirección es un botón que no lleva a ningún sitio: peor que no ponerlo,
            // porque el cliente lo pulsa y no pasa nada.
            'button_url' => ['nullable', 'required_with:button_title', 'url', 'max:2000'],
            // Cuánto espera antes de contestar. El tope de 86400 (24 h) es de la API.
            'dm_delay' => ['nullable', 'integer', 'min:0', 'max:86400'],
            // Tolerancia a erratas. Solo la admite el modo «palabra suelta»; con los otros, la API
            // la ignora.
            'typo_tolerance' => ['sometimes', 'boolean'],
            'also_in_dms' => ['sometimes', 'boolean'],
            'follow_gate' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            // Sin palabras clave, «responder también por privado» contestaría a TODO mensaje que
            // entre. La API lo rechaza; se corta aquí para que el motivo se lea en el formulario.
            if ($this->boolean('also_in_dms') && $this->palabras() === []) {
                $v->errors()->add('keywords', 'Para responder también por privado hace falta al menos una palabra clave: sin ninguna contestaría a todos los mensajes.');
            }

            /*
             * En las historias, «responder también por privado» lo rechaza la API con un 400:
             * «alsoMatchInDms is not available on story_reply automations (they already trigger on
             * DMs)». Comprobado contra su servidor.
             *
             * Se corta aquí para que el motivo se lea donde está la casilla, y porque el motivo no
             * es una carencia: es que en las historias ya contesta a los privados siempre.
             */
            if ($this->boolean('also_in_dms') && ! $this->disparador()->admiteRespuestaEnPrivados()) {
                $v->errors()->add('also_in_dms', 'En las historias esto ya funciona solo: si alguien te escribe esa palabra por privado, también le contesta.');
            }

            // Las versiones de la respuesta pública rotan JUNTO a la principal, no en su lugar. Sin
            // principal no hay nada con qué alternar, y quedaría media función encendida.
            if ($this->variaciones('reply_variations') !== [] && blank($this->input('comment_reply'))) {
                $v->errors()->add('comment_reply', 'Para tener varias respuestas públicas, escribe primero la principal.');
            }
        });
    }

    public function attributes(): array
    {
        return [
            'name' => 'nombre', 'account_id' => 'cuenta', 'keywords' => 'palabras clave',
            'dm_message' => 'mensaje privado', 'comment_reply' => 'respuesta pública',
            'button_title' => 'texto del botón', 'button_url' => 'enlace del botón',
        ];
    }

    public function messages(): array
    {
        return [
            'button_url.required_with' => 'Un botón necesita a dónde llevar: pon el enlace.',
            'dm_message.max' => $this->llevaBoton()
                ? 'Con botón, el mensaje privado admite 640 caracteres como mucho.'
                : 'El mensaje privado admite 1000 caracteres como mucho.',
        ];
    }

    /**
     * Las palabras clave, separadas por comas y sin huecos.
     *
     * @return array<int, string>
     */
    public function palabras(): array
    {
        return array_values(array_filter(array_map(
            static fn (string $p): string => trim($p),
            explode(',', (string) $this->input('keywords', '')),
        ), static fn (string $p): bool => $p !== ''));
    }

    /** El disparador elegido, o el de por omisión. */
    public function disparador(): AutomationTrigger
    {
        return AutomationTrigger::tryFrom((string) $this->input('trigger', ''))
            ?? AutomationTrigger::POR_OMISION;
    }

    public function llevaBoton(): bool
    {
        return filled($this->input('button_title'));
    }

    /**
     * Las versiones alternativas de un campo, sin los huecos que deja el formulario.
     *
     * Se filtran los vacíos porque las casillas se añaden y se borran en pantalla: si alguien abre
     * tres y solo escribe en la primera, mandar dos cadenas vacías haría que Zernio contestara con
     * un mensaje en blanco una de cada tres veces.
     *
     * @return array<int, string>
     */
    public function variaciones(string $campo): array
    {
        return array_values(array_filter(array_map(
            static fn (mixed $v): string => trim((string) $v),
            (array) $this->input($campo, []),
        ), static fn (string $v): bool => $v !== ''));
    }

    /**
     * El cuerpo tal como lo espera Zernio.
     *
     * Lo que no se ofrece en el formulario no se manda: la API acepta ausentes los retrasos y las
     * palabras excluidas, y mandarlos vacíos sería decirle que los queremos vacíos.
     *
     * @return array<string, mixed>
     */
    public function paraZernio(): array
    {
        $cuerpo = [
            'name' => (string) $this->input('name'),
            'accountId' => (string) $this->input('account_id'),
            'trigger' => $this->disparador()->value,
            'keywords' => $this->palabras(),
            // Por omisión la palabra suelta, NO la de la API: ver KeywordMatch.
            'matchMode' => (string) ($this->input('match_mode') ?: KeywordMatch::POR_OMISION->value),
            'dmMessage' => (string) $this->input('dm_message'),
            'alsoMatchInDms' => $this->boolean('also_in_dms'),
            'isActive' => $this->boolean('is_active'),
        ];

        if (filled($this->input('comment_reply'))) {
            $cuerpo['commentReply'] = (string) $this->input('comment_reply');
        }

        /*
         * Aceptar la palabra aunque venga con un dedazo.
         *
         * No es un adorno: en el registro de una automatización real, tres de los comentarios que
         * NO cazaron ninguna palabra eran «Infomacion» y «imformacion» —clientes escribiendo con
         * prisa desde el móvil— y se quedaron sin respuesta.
         *
         * La API solo lo aplica con «palabra suelta»; mandarlo con los otros modos no hace nada,
         * así que se manda solo cuando sirve para que el cuerpo diga la verdad.
         */
        if ($cuerpo['matchMode'] === KeywordMatch::Word->value) {
            $cuerpo['typoTolerance'] = $this->boolean('typo_tolerance');
        }

        /*
         * Varias versiones del mismo mensaje, para que no salga siempre el mismo texto.
         *
         * NO es un adorno: Instagram y Facebook detectan el mensaje idéntico repetido y limitan o
         * bloquean la cuenta por spam. La respuesta pública es aún más visible —queda escrita bajo
         * cada comentario, a la vista de todos— y ahí un texto calcado se nota a simple vista.
         *
         * Zernio elige una al azar entre la principal y sus alternativas, y lo hace por separado
         * para el privado y para el comentario, así que las combinaciones se multiplican.
         *
         * Se omiten cuando no hay ninguna: mandar un array vacío sería pedirle explícitamente que
         * no varíe, y no es lo mismo que no opinar.
         */
        if (($dm = $this->variaciones('dm_variations')) !== []) {
            $cuerpo['dmMessageVariations'] = $dm;
        }

        if (($publicas = $this->variaciones('reply_variations')) !== []) {
            $cuerpo['commentReplyVariations'] = $publicas;
        }

        /*
         * Esperar un poco antes de contestar.
         *
         * Contestar en el mismo segundo, siempre, es tan reconocible como el texto repetido: ninguna
         * persona responde en cuatro décimas a cualquier hora. Un retraso rompe esa firma.
         *
         * OJO: el retraso es FIJO, la API no ofrece variarlo al azar. Ayuda, pero no imita a una
         * persona; lo que de verdad rompe el patrón son las versiones del mensaje.
         *
         * No hace falta tocar `commentReplyDelaySeconds`: Zernio nunca publica la respuesta pública
         * antes de mandar el privado, así que la sube sola hasta este valor. Un segundo control solo
         * podría retrasarla MÁS, y no hay motivo para quererlo.
         *
         * El cero se omite en vez de mandarse: es el valor por omisión de la API y decirlo no aporta.
         */
        if (($espera = (int) $this->input('dm_delay', 0)) > 0) {
            $cuerpo['dmDelaySeconds'] = $espera;
        }

        /*
         * Una publicación concreta, o ninguna.
         *
         * Ausente significa «en cualquier publicación», que es lo que quiere la mayoría: la
         * automatización sigue funcionando en las fotos que suba mañana. Mandar los campos vacíos NO
         * es lo mismo —sería pedir «la publicación con identificador vacío»— así que se omiten.
         *
         * Van los DOS identificadores porque la API pide el suyo (`postId`) además del de la red.
         */
        [$postId, $platformPostId] = array_pad(explode('|', (string) $this->input('post', ''), 2), 2, null);

        // En una historia, `platformPostId` sería el id de la HISTORIA, no el de una publicación:
        // mandar ahí el de una foto la ataría a algo que no existe como historia.
        if ($this->disparador()->admitePublicacion() && filled($postId) && filled($platformPostId)) {
            $cuerpo['postId'] = (string) $postId;
            $cuerpo['platformPostId'] = (string) $platformPostId;
        }

        if ($this->llevaBoton()) {
            $cuerpo['buttons'] = [[
                'type' => 'url',
                'title' => (string) $this->input('button_title'),
                'url' => (string) $this->input('button_url'),
            ]];
        }

        // Los textos del «solo a quien me siga» tienen valores por omisión sensatos en Zernio: se
        // manda el objeto vacío para encenderlo y no se piden en pantalla.
        if ($this->boolean('follow_gate')) {
            $cuerpo['followGate'] = new \stdClass;
        }

        return $cuerpo;
    }
}
