<?php

declare(strict_types=1);

namespace App\Modules\AI\Services;

use App\Modules\AI\Models\AiDocument;
use App\Modules\AI\Models\AiDocumentChunk;
use App\Modules\AI\Providers\Contracts\AiProvider;
use App\Modules\AI\Support\TextChunker;
use App\Modules\AI\Support\Vector;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Pipeline de RAG (Retrieval-Augmented Generation):
 *   - index():    trocea el documento, genera embeddings y los guarda (aislados por empresa).
 *   - retrieve(): embebe la consulta y recupera los chunks más similares (coseno).
 *   - answer():   arma el prompt con el contexto recuperado y consulta al proveedor de IA.
 *
 * La búsqueda por similitud se hace en PHP sobre embeddings JSON, portable a cualquier Postgres.
 * Para grandes volúmenes, sustituir retrieve() por una consulta con pgvector.
 */
final class RagService
{
    public function __construct(private readonly AiProvider $provider) {}

    public function index(string $title, string $content, ?string $source = null): AiDocument
    {
        return DB::transaction(function () use ($title, $content, $source): AiDocument {
            $document = AiDocument::create([
                'title' => $title,
                'source' => $source,
                'content' => $content,
            ]);

            foreach (TextChunker::chunk($content) as $position => $chunk) {
                $document->chunks()->create([
                    'company_id' => $document->company_id,
                    'position' => $position,
                    'content' => $chunk,
                    'embedding' => $this->provider->embed($chunk),
                ]);
            }

            return $document;
        });
    }

    /**
     * Recupera los chunks más relevantes para una consulta, ordenados por similitud descendente.
     *
     * @return Collection<int, AiDocumentChunk>
     */
    public function retrieve(string $query, int $k = 3): Collection
    {
        $queryEmbedding = $this->provider->embed($query);

        return AiDocumentChunk::query()
            ->get()
            ->map(function (AiDocumentChunk $chunk) use ($queryEmbedding): AiDocumentChunk {
                $embedding = array_map('floatval', (array) $chunk->embedding);
                $chunk->similarity = Vector::cosine($queryEmbedding, $embedding);

                return $chunk;
            })
            ->sortByDesc('similarity')
            ->take($k)
            ->values();
    }

    /**
     * Responde una pregunta usando el contexto recuperado.
     *
     * @return array{answer: string, sources: Collection<int, AiDocumentChunk>}
     */
    public function answer(string $query, int $k = 3): array
    {
        $sources = $this->retrieve($query, $k);
        $context = $sources->pluck('content')->implode("\n---\n");

        $answer = $this->provider->chat([
            ['role' => 'system', 'content' => "Responde usando únicamente este contexto:\n{$context}"],
            ['role' => 'user', 'content' => $query],
        ]);

        return ['answer' => $answer, 'sources' => $sources];
    }
}
