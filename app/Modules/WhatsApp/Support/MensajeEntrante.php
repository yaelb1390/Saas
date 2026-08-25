<?php

declare(strict_types=1);

namespace App\Modules\WhatsApp\Support;

/**
 * Qué llegó por WhatsApp: de qué clase es y qué se puede leer de ello.
 *
 * Existe porque antes el webhook solo sabía leer texto:
 *
 *     return $message['conversation'] ?? ($message['extendedTextMessage']['text'] ?? null);
 *
 * Cualquier otra cosa —una nota de voz, una foto, un documento, una ubicación— salía `null`, y el
 * webhook la DESCARTABA. No es que el bot no supiera contestarla: es que el mensaje no llegaba a
 * existir. Ni se guardaba, ni aparecía en la bandeja, ni nadie se enteraba. Para el dueño era
 * exactamente igual que si el cliente nunca hubiera escrito, y en República Dominicana media
 * clientela habla por audio.
 *
 * Ahora todo lo que entra se guarda. Lo que no se pueda entender se queda con un rótulo honesto
 * —«🎤 nota de voz»— y lo atiende una persona, que es infinitamente mejor que perderlo.
 */
final readonly class MensajeEntrante
{
    /** El rótulo que se guarda cuando no hay texto que leer. Se ve así en la bandeja. */
    public const ROTULOS = [
        'audio' => '🎤 Nota de voz',
        'image' => '📷 Foto',
        'video' => '🎬 Vídeo',
        'document' => '📎 Documento',
        'location' => '📍 Ubicación',
        'contact' => '👤 Contacto',
        'sticker' => '🙂 Sticker',
    ];

    /**
     * @param  string  $tipo  text, audio, image, video, document, location, contact, sticker
     * @param  string  $cuerpo  lo que se guarda y se enseña
     * @param  bool  $transcribible  ¿tiene sentido mandarlo a transcribir?
     */
    private function __construct(
        public string $tipo,
        public string $cuerpo,
        public bool $transcribible = false,
    ) {}

    /**
     * Lee lo que mandó Evolution.
     *
     * Devuelve null solo cuando NO HAY MENSAJE —un acuse de lectura, un evento de sistema—, no
     * cuando el mensaje es de un tipo que no sabemos leer. Esa distinción es justo el fallo que se
     * está corrigiendo.
     *
     * @param  array<string, mixed>  $message
     */
    public static function desdeEvolution(array $message): ?self
    {
        // Texto, tal cual estaba: es el caso común y no cambia.
        $texto = $message['conversation'] ?? ($message['extendedTextMessage']['text'] ?? null);

        if (is_string($texto) && trim($texto) !== '') {
            return new self('text', trim($texto));
        }

        /*
         * La nota de voz. `audioMessage` cubre las dos: el audio grabado con el micrófono (ptt) y un
         * archivo de audio adjunto. Se tratan igual porque el cliente no distingue entre las dos y
         * el bot tampoco necesita hacerlo.
         */
        if (isset($message['audioMessage'])) {
            return new self('audio', self::ROTULOS['audio'], transcribible: true);
        }

        /*
         * Una foto con pie de texto ES un mensaje de texto para lo que nos importa: «¿cuánto cuesta
         * esta?» acompañando una foto se contesta leyendo el pie. Sin pie, se rotula y ya.
         */
        if (isset($message['imageMessage'])) {
            $pie = trim((string) ($message['imageMessage']['caption'] ?? ''));

            return new self('image', $pie !== '' ? $pie : self::ROTULOS['image']);
        }

        if (isset($message['videoMessage'])) {
            $pie = trim((string) ($message['videoMessage']['caption'] ?? ''));

            return new self('video', $pie !== '' ? $pie : self::ROTULOS['video']);
        }

        if (isset($message['documentMessage'])) {
            $nombre = trim((string) ($message['documentMessage']['fileName'] ?? ''));

            return new self('document', $nombre !== '' ? self::ROTULOS['document'].': '.$nombre : self::ROTULOS['document']);
        }

        if (isset($message['locationMessage'])) {
            return new self('location', self::ROTULOS['location']);
        }

        if (isset($message['contactMessage']) || isset($message['contactsArrayMessage'])) {
            return new self('contact', self::ROTULOS['contact']);
        }

        if (isset($message['stickerMessage'])) {
            return new self('sticker', self::ROTULOS['sticker']);
        }

        /*
         * Ni texto ni nada reconocible: aquí sí se ignora.
         *
         * Son los acuses de recibo, las reacciones y los eventos de protocolo, que llegan por el
         * mismo sitio y no son mensajes de nadie. Guardarlos llenaría la bandeja de ruido.
         */
        return null;
    }

    /**
     * ¿El bot puede intentar contestarlo?
     *
     * Solo lo que se lee como texto. Una foto sin pie o una ubicación se guardan y se enseñan, pero
     * el bot no tiene con qué responder: intentarlo daría una respuesta inventada sobre algo que no
     * ha visto. Se aparta y lo atiende una persona.
     */
    public function loPuedeContestarElBot(): bool
    {
        return $this->tipo === 'text'
            || (in_array($this->tipo, ['image', 'video'], true) && ! $this->esUnRotulo());
    }

    /**
     * Los tipos que NUNCA traen texto que el bot pueda contestar.
     *
     * Se guardan y se enseñan en la bandeja —eso es lo importante, antes se perdían— pero el bot se
     * aparta: no tiene con qué responder a una foto que no ha visto ni a una ubicación, e intentarlo
     * daría una respuesta inventada sobre algo que desconoce.
     *
     * @return array<int, string>
     */
    public static function sinTextoQueLeer(): array
    {
        return ['location', 'contact', 'sticker', 'document'];
    }

    /** ¿El cuerpo es solo el rótulo, sin nada que leer? */
    public function esUnRotulo(): bool
    {
        return in_array($this->cuerpo, self::ROTULOS, true)
            || str_starts_with($this->cuerpo, self::ROTULOS['document']);
    }
}
