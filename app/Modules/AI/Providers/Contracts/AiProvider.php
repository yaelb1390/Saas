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
}
