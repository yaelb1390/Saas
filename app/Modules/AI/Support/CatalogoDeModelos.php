<?php

declare(strict_types=1);

namespace App\Modules\AI\Support;

/**
 * Qué modelos ofrece cada proveedor de IA, y cuáles se eligen si nadie dice otra cosa.
 *
 * Existe porque el dato estaba en dos sitios que ya se habían separado: el formulario ofrecía campos
 * de texto libre y el controlador tenía su propia tabla de omisiones, donde Gemini apuntaba a
 * `gemini-2.0-flash` mientras `config/ai.php` apuntaba a `gemini-2.5-flash`. Con texto libre, además,
 * nada impedía guardar un modelo de Gemini con el proveedor OpenAI —pasó, y así estaba la pantalla
 * cuando se pidió esto—: se guarda sin protestar y falla luego, al llamar, con un error del
 * proveedor que no dice que la culpa sea de la combinación.
 *
 * Lo que NO hace es cerrar la puerta a modelos nuevos. Los proveedores sacan uno cada pocas semanas,
 * y una lista cerrada obligaría a tocar código para poder usarlo. Por eso el formulario ofrece estos
 * y además deja escribir a mano; esta lista es el atajo para el caso normal, no una aduana.
 */
final class CatalogoDeModelos
{
    /**
     * @return array<string, array{chat: list<string>, embedding: list<string>, dimensiones: int|null}>
     */
    public static function todos(): array
    {
        return [
            'gemini' => [
                'chat' => ['gemini-2.5-flash', 'gemini-2.5-pro', 'gemini-2.0-flash'],
                'embedding' => ['gemini-embedding-001'],
                'dimensiones' => 768,
            ],
            'openai' => [
                'chat' => ['gpt-4o-mini', 'gpt-4o'],
                'embedding' => ['text-embedding-3-small', 'text-embedding-3-large'],
                // El tamaño nativo de `text-embedding-3-small`. Cambiarlo obliga a reindexar, así que
                // el formulario avisa cuando se toca.
                'dimensiones' => 1536,
            ],
            'anthropic' => [
                'chat' => ['claude-sonnet-5', 'claude-opus-5', 'claude-haiku-4-5-20251001'],
                // Vacío a propósito: Anthropic no genera embeddings. No es que falte por rellenar,
                // es que con Claude el asistente no puede indexar documentos.
                'embedding' => [],
                'dimensiones' => null,
            ],
            'local' => ['chat' => [], 'embedding' => [], 'dimensiones' => null],
        ];
    }

    /** El primero de la lista es el recomendado: es el que se pone solo al elegir proveedor. */
    public static function chatPorOmision(string $proveedor): ?string
    {
        return self::todos()[$proveedor]['chat'][0] ?? null;
    }

    public static function embeddingPorOmision(string $proveedor): ?string
    {
        return self::todos()[$proveedor]['embedding'][0] ?? null;
    }

    public static function dimensionesPorOmision(string $proveedor): ?int
    {
        return self::todos()[$proveedor]['dimensiones'] ?? null;
    }
}
