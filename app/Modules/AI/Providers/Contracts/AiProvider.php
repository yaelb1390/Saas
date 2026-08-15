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
}
