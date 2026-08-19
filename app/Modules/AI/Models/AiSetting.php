<?php

declare(strict_types=1);

namespace App\Modules\AI\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Los ajustes de IA de la plataforma. UNA SOLA FILA.
 *
 * No lleva `company_id` a propósito: la clave es del operador, no de cada empresa. Un dueño de
 * colmado no va a sacar una clave en Google AI Studio, y hacérselo obligatorio dejaría el módulo
 * apagado para todo el mundo, que es justo lo que pasa con Zernio.
 *
 * Si algún día hiciera falta clave por empresa, hay que tocar DOS sitios: esta tabla gana un
 * `company_id`, y el `singleton` de `AiServiceProvider` tiene que bajar a `scoped` — con un
 * singleton, la primera empresa que resolviera el proveedor se lo impondría a las siguientes dentro
 * de la misma petición.
 */
final class AiSetting extends Model
{
    /** Los que hacen embeddings Y chat, o sea los que sirven para el asistente entero. */
    public const COMPLETOS = ['gemini', 'openai'];

    protected $fillable = [
        'provider',
        'api_key',
        'chat_model',
        'embedding_model',
        'embedding_dimensions',
    ];

    protected function casts(): array
    {
        return [
            // Cifrada en reposo: quien la tenga gasta de tu cuota, así que no puede quedar legible
            // para nadie que abra la tabla.
            'api_key' => 'encrypted',
            'embedding_dimensions' => 'integer',
        ];
    }

    /**
     * La fila única, creándola si no existe.
     *
     * No se cachea. Es una consulta de una fila por clave primaria, y solo ocurre cuando algo usa la
     * IA; una caché aquí solo añadiría la posibilidad de quedarse vieja justo después de cambiar la
     * clave, que es el peor momento posible.
     */
    public static function actual(): self
    {
        return self::query()->firstOrCreate([], [
            'provider' => 'local',
            'embedding_dimensions' => 768,
        ]);
    }

    /** ¿Hay una clave puesta? Sin ella el asistente no redacta nada de verdad. */
    public function configurado(): bool
    {
        return $this->provider !== 'local' && filled($this->api_key);
    }

    /** ¿Este proveedor sabe indexar documentos? Anthropic no genera embeddings. */
    public function puedeIndexar(): bool
    {
        return in_array($this->provider, self::COMPLETOS, true);
    }

    /**
     * La firma con la que se marcan los fragmentos indexados.
     *
     * @return array{provider: string, dimensions: int}
     */
    public function firma(): array
    {
        return [
            'provider' => (string) $this->provider,
            'dimensions' => (int) $this->embedding_dimensions,
        ];
    }
}
