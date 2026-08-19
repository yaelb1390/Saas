<?php

declare(strict_types=1);

namespace App\Modules\AI\Services;

use App\Modules\AI\Models\AiDocument;
use App\Modules\AI\Models\AiDocumentChunk;
use App\Modules\AI\Models\AiSetting;
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
 *
 * CADA FRAGMENTO RECUERDA CON QUÉ SE INDEXÓ, y solo se compara lo que casa con el proveedor de
 * ahora. Sin eso, cambiar de proveedor no rompía nada visible: devolvía resultados calculados sobre
 * vectores de espacios distintos, con su porcentaje y todo.
 */
final class RagService
{
    public function __construct(private readonly AiProvider $provider) {}

    public function index(string $title, string $content, ?string $source = null): AiDocument
    {
        $firma = AiSetting::actual()->firma();

        return DB::transaction(function () use ($title, $content, $source, $firma): AiDocument {
            $document = AiDocument::create([
                'title' => $title,
                'source' => $source,
                'content' => $content,
            ]);

            foreach (TextChunker::chunk($content) as $position => $chunk) {
                $embedding = $this->provider->embed($chunk);

                $document->chunks()->create([
                    'company_id' => $document->company_id,
                    'position' => $position,
                    'content' => $chunk,
                    'embedding' => $embedding,
                    'provider' => $firma['provider'],
                    // El tamaño real de lo que devolvió, no el que se pidió: si el proveedor
                    // ignorase la petición, es esto lo que hay guardado y lo que hay que comparar.
                    'dimensions' => count($embedding),
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
        $firma = AiSetting::actual()->firma();

        return AiDocumentChunk::query()
            // Solo lo indexado con el proveedor de ahora y del mismo tamaño. Lo demás no se mezcla:
            // `Vector::cosine` lanzaría excepción, y con razón.
            ->where('provider', $firma['provider'])
            ->where('dimensions', count($queryEmbedding))
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
     * Cuántos fragmentos quedaron indexados con otro proveedor.
     *
     * Es lo que permite decir en pantalla «tienes 12 documentos que hay que reindexar» en vez de
     * dejar que el asistente conteste «no encontré nada» sin explicar por qué.
     */
    public function desfasados(): int
    {
        $firma = AiSetting::actual()->firma();

        return AiDocumentChunk::query()
            ->where(fn ($q) => $q->where('provider', '!=', $firma['provider'])
                ->orWhere('dimensions', '!=', $firma['dimensions']))
            ->count();
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

    /**
     * Vuelve a indexar documentos con el proveedor de ahora, POR TANDAS.
     *
     * Por tandas y no de golpe porque en producción no hay procesos en segundo plano: reindexar
     * cincuenta documentos en una sola petición se agotaría a mitad y dejaría la mitad convertida y
     * la mitad no, que es peor que no empezar.
     *
     * @return array{convertidos: int, quedan: int}
     */
    public function reindexar(int $porTanda = 5): array
    {
        $firma = AiSetting::actual()->firma();

        $documentos = AiDocument::query()
            ->whereHas('chunks', fn ($q) => $q->where('provider', '!=', $firma['provider'])
                ->orWhere('dimensions', '!=', $firma['dimensions']))
            ->limit($porTanda)
            ->get();

        foreach ($documentos as $documento) {
            DB::transaction(function () use ($documento, $firma): void {
                $documento->chunks()->delete();

                foreach (TextChunker::chunk((string) $documento->content) as $position => $chunk) {
                    $embedding = $this->provider->embed($chunk);

                    $documento->chunks()->create([
                        'company_id' => $documento->company_id,
                        'position' => $position,
                        'content' => $chunk,
                        'embedding' => $embedding,
                        'provider' => $firma['provider'],
                        'dimensions' => count($embedding),
                    ]);
                }
            });
        }

        return ['convertidos' => $documentos->count(), 'quedan' => $this->documentosDesfasados()];
    }

    /** Cuántos DOCUMENTOS (no fragmentos) están indexados con otro proveedor. */
    public function documentosDesfasados(): int
    {
        $firma = AiSetting::actual()->firma();

        return AiDocument::query()
            ->whereHas('chunks', fn ($q) => $q->where('provider', '!=', $firma['provider'])
                ->orWhere('dimensions', '!=', $firma['dimensions']))
            ->count();
    }
}
