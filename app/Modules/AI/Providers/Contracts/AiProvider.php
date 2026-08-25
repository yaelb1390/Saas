<?php

declare(strict_types=1);

namespace App\Modules\AI\Providers\Contracts;

/**
 * Abstracción del proveedor de IA. Permite intercambiar OpenAI, Claude o un proveedor local
 * (determinista, para dev/tests) sin acoplar el dominio a un proveedor concreto.
 */
interface AiProvider
{
    /**
     * Genera el embedding (vector) de un texto.
     *
     * @return array<int, float>
     */
    public function embed(string $text): array;

    /**
     * Completa un chat a partir de una lista de mensajes.
     *
     * @param  array<int, array{role: string, content: string}>  $messages
     */
    public function chat(array $messages): string;

    /**
     * Clasifica el sentimiento de un texto.
     *
     * @return array{sentiment: string, score: float}
     */
    public function classifySentiment(string $text): array;

    /**
     * ¿Este proveedor redacta texto de verdad?
     *
     * El proveedor local no: su `chat()` devuelve una plantilla determinista con el contexto pegado
     * detrás. Sirve para tests y para que nada reviente sin clave de API, pero enseñárselo a un
     * cliente como respuesta parece que el sistema está roto.
     *
     * Quien necesite saberlo pregunta AQUÍ y no rehace la lógica de `AiServiceProvider` mirando la
     * configuración: si mañana cambia cómo se elige el proveedor, cambia en un sitio.
     */
    public function redactaRespuestas(): bool;

    /**
     * ¿Este proveedor sabe convertir un audio en texto?
     *
     * Se pregunta igual que `redactaRespuestas()`: quien llama no tiene por qué saber qué proveedor
     * hay puesto ni cuál de ellos entiende audio. Si dice que no, la nota de voz se guarda con su
     * rótulo y la atiende una persona —que es lo correcto: mejor eso que inventarse lo que dijo—.
     */
    public function puedeTranscribir(): bool;

    /**
     * Convierte un audio en texto.
     *
     * Recibe el audio EN BASE64 y no una dirección: llega así desde WhatsApp y aquí no hay dónde
     * guardarlo —en producción el disco es de solo lectura—, así que pasarlo en memoria evita
     * inventar un almacenamiento intermedio para algo que se usa una vez y se tira.
     *
     * @param  string  $mimeType  lo que dice WhatsApp del archivo; los audios suelen ser audio/ogg
     */
    public function transcribe(string $audioBase64, string $mimeType): string;
}
