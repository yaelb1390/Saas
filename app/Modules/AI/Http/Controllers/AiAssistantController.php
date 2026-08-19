<?php

declare(strict_types=1);

namespace App\Modules\AI\Http\Controllers;

use App\Modules\AI\Http\Requests\AskAssistantRequest;
use App\Modules\AI\Http\Requests\StoreAiDocumentRequest;
use App\Modules\AI\Models\AiDocument;
use App\Modules\AI\Models\AiDocumentChunk;
use App\Modules\AI\Services\RagService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use Throwable;

/**
 * Interfaz interactiva del módulo de IA: preguntar a la base de conocimiento (RAG),
 * indexar nuevos documentos y eliminarlos. La lógica vive en RagService; el proveedor
 * de IA (local/OpenAI/Anthropic) es intercambiable.
 */
final class AiAssistantController extends Controller
{
    public function ask(AskAssistantRequest $request, RagService $rag): RedirectResponse
    {
        $query = $request->validated()['query'];

        /*
         * El proveedor puede fallar por mil motivos que no son culpa de nadie: clave vencida, cuota
         * agotada, corte de red. Antes NO habia un solo try/catch en todo el camino de la IA y
         * cualquiera de esos casos devolvia una pagina 500. Se dice el motivo y se sigue.
         */
        try {
            $result = $rag->answer($query);
        } catch (Throwable $e) {
            report($e);

            return back()
                ->with('ai_query', $query)
                ->with('panel_error', 'La IA no pudo responder ahora mismo. Si acaba de cambiarse la clave o el proveedor, avisa al administrador.');
        }

        // Se aplana a datos serializables para poder mostrarlos tras el redirect (PRG).
        $sources = $result['sources']
            ->map(fn (AiDocumentChunk $chunk): array => [
                'title' => $chunk->document->title,
                'excerpt' => Str::limit($chunk->content, 180),
                'similarity' => round((float) $chunk->similarity, 3),
            ])
            ->all();

        return back()
            ->with('ai_query', $query)
            ->with('ai_answer', $result['answer'])
            ->with('ai_sources', $sources);
    }

    public function store(StoreAiDocumentRequest $request, RagService $rag): RedirectResponse
    {
        $data = $request->validated();

        try {
            $rag->index($data['title'], $data['content'], $data['source'] ?? null);
        } catch (Throwable $e) {
            report($e);

            return back()->withInput()->with('panel_error', 'No se pudo indexar: la IA no respondio. Revisa la clave del proveedor.');
        }

        return back()->with('panel_ok', 'Documento indexado en la base de conocimiento.');
    }

    public function destroy(AiDocument $document): RedirectResponse
    {
        // El route model binding ya aísla el documento por la empresa activa.
        $document->chunks()->delete();
        $document->delete();

        return back()->with('panel_ok', 'Documento eliminado de la base de conocimiento.');
    }
}
