<?php

declare(strict_types=1);

namespace App\Modules\AI\Providers;

use App\Modules\AI\Providers\Contracts\AiProvider;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Proveedor Gemini (Google): embeddings Y chat.
 *
 * Es el que sirve para el módulo entero. Anthropic no genera embeddings —su `embed()` lanza una
 * excepción— así que con Claude el asistente RAG no puede ni indexar ni buscar; solo redactaría.
 *
 * Las formas de las peticiones están comprobadas contra la documentación de Google, no de memoria:
 * con Zernio, fiarse del manual costó cuatro fallos que solo aparecieron en producción.
 */
final class GeminiProvider implements AiProvider
{
    private const BASE = 'https://generativelanguage.googleapis.com/v1beta';

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(private readonly array $config) {}

    /**
     * El vector de un texto.
     *
     * Se manda SIEMPRE `outputDimensionality`. El tamaño del vector pasa a ser una decisión nuestra
     * y no lo que el modelo devuelva por omisión: si Google lo cambia mañana, todo lo ya indexado
     * dejaría de casar sin que nadie viera un error.
     *
     * @return array<int, float>
     */
    public function embed(string $text): array
    {
        $modelo = (string) $this->config['embedding_model'];

        $response = $this->client()
            ->post("/models/{$modelo}:embedContent", [
                'content' => ['parts' => [['text' => $text]]],
                'outputDimensionality' => (int) $this->config['embedding_dimensions'],
            ])
            ->throw();

        return array_map('floatval', $response->json('embedding.values', []));
    }

    /**
     * Gemini separa la instrucción del sistema del hilo, y llama «model» a lo que otros llaman
     * «assistant». Se traduce aquí para que el resto del módulo siga hablando en el formato común.
     *
     * @param  array<int, array{role: string, content: string}>  $messages
     */
    public function chat(array $messages): string
    {
        $sistema = '';
        $hilo = [];

        foreach ($messages as $mensaje) {
            if ($mensaje['role'] === 'system') {
                $sistema .= ($sistema === '' ? '' : "\n").$mensaje['content'];

                continue;
            }

            $hilo[] = [
                'role' => $mensaje['role'] === 'assistant' ? 'model' : 'user',
                'parts' => [['text' => $mensaje['content']]],
            ];
        }

        $modelo = (string) $this->config['chat_model'];

        $response = $this->client()
            ->post("/models/{$modelo}:generateContent", array_filter([
                'system_instruction' => $sistema !== '' ? ['parts' => [['text' => $sistema]]] : null,
                'contents' => $hilo,
            ]))
            ->throw();

        return (string) $response->json('candidates.0.content.parts.0.text', '');
    }

    public function classifySentiment(string $text): array
    {
        $answer = $this->chat([
            ['role' => 'system', 'content' => 'Clasifica el sentimiento del mensaje. Responde SOLO una palabra: positive, neutral o negative.'],
            ['role' => 'user', 'content' => $text],
        ]);

        $sentiment = strtolower(trim($answer));
        $sentiment = in_array($sentiment, ['positive', 'neutral', 'negative'], true) ? $sentiment : 'neutral';

        return ['sentiment' => $sentiment, 'score' => 0.0];
    }

    /** Sí: solo se instancia cuando hay clave. */
    public function puedeTranscribir(): bool
    {
        return true;
    }

    /**
     * Transcribe con el mismo modelo de chat.
     *
     * Gemini 2.5 Flash entiende audio de forma nativa, así que no hace falta otro proveedor, otra
     * clave ni otro servicio: se manda una parte de audio junto a la instrucción, igual que se manda
     * texto. El audio viaja en la propia petición y no se guarda en ninguna parte.
     *
     * La instrucción pide SOLO la transcripción. Sin eso, el modelo tiende a describir el audio
     * («el hablante pregunta por el precio…») y lo que hace falta aquí son las palabras que dijo el
     * cliente, que son las que va a leer el dueño y las que va a contestar el bot.
     */
    public function transcribe(string $audioBase64, string $mimeType): string
    {
        $modelo = (string) $this->config['chat_model'];

        $response = $this->client()
            ->post("/models/{$modelo}:generateContent", [
                'contents' => [[
                    'parts' => [
                        ['text' => 'Transcribe este audio literalmente. Devuelve SOLO lo que se dice, '
                            .'sin comillas, sin comentarios y sin describir el audio. Está en español '
                            .'dominicano. Si no se entiende nada, responde exactamente: (no se entiende)'],
                        ['inline_data' => ['mime_type' => $mimeType, 'data' => $audioBase64]],
                    ],
                ]],
            ]);

        return trim((string) $response->json('candidates.0.content.parts.0.text', ''));
    }

    public function redactaRespuestas(): bool
    {
        return true;
    }

    /**
     * La clave va en la CABECERA, nunca en la URL.
     *
     * Google documenta también `?key=...`, y es una mala idea: una clave en la barra de direcciones
     * acaba en los registros de acceso del servidor, en el historial del navegador y en cualquier
     * informe de error que incluya la URL.
     */
    private function client(): PendingRequest
    {
        return Http::withHeaders(['x-goog-api-key' => (string) $this->config['api_key']])
            ->acceptJson()
            ->timeout(30)
            ->baseUrl(self::BASE);
    }
}
