<?php

declare(strict_types=1);

namespace App\Modules\AI\Http\Controllers;

use App\Modules\AI\Models\AiDocumentChunk;
use App\Modules\AI\Models\AiSetting;
use App\Modules\AI\Providers\Contracts\AiProvider;
use App\Modules\AI\Services\RagService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * Los ajustes de IA de la plataforma: qué proveedor y con qué clave.
 *
 * Vive en el panel del operador y no en el de la empresa porque la clave es una sola para todos. Un
 * dueño de colmado no va a sacar una clave en Google AI Studio, y exigírselo dejaría el módulo
 * apagado para todo el mundo.
 */
final class AiSettingsController extends Controller
{
    /** Cuántos documentos se reindexan por petición: en producción no hay procesos de fondo. */
    private const POR_TANDA = 5;

    public function index(RagService $rag): View
    {
        $ajustes = AiSetting::actual();

        return view('panel.admin.ai', [
            'ajustes' => $ajustes,
            // Los fragmentos indexados con otro proveedor. Sin esta cuenta, el asistente contestaría
            // «no encontré nada» sin que nadie pudiera saber que hay que reindexar.
            'desfasados' => $this->desfasadosDeTodas($ajustes),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $datos = $request->validate([
            'provider' => ['required', Rule::in(['local', 'gemini', 'openai', 'anthropic'])],
            'api_key' => ['nullable', 'string', 'max:500'],
            'chat_model' => ['nullable', 'string', 'max:100'],
            'embedding_model' => ['nullable', 'string', 'max:100'],
            'embedding_dimensions' => ['nullable', 'integer', 'min:64', 'max:3072'],
        ]);

        $ajustes = AiSetting::actual();

        $ajustes->fill([
            'provider' => $datos['provider'],
            // Un campo opcional que NO se envia no llega en el array validado, y leerlo a secas
            // es un 500 que ademas deja el guardado a medias sin decir nada.
            'chat_model' => ($datos['chat_model'] ?? null) ?: $this->modeloPorOmision($datos['provider'], 'chat'),
            'embedding_model' => ($datos['embedding_model'] ?? null) ?: $this->modeloPorOmision($datos['provider'], 'embedding'),
            'embedding_dimensions' => ($datos['embedding_dimensions'] ?? null) ?: 768,
        ]);

        // Vacío = borrar la clave y apagar la IA. Si no se manda nada, se conserva la que había: el
        // formulario nunca devuelve la clave al HTML, así que un guardado normal llega sin ella.
        if ($request->has('api_key')) {
            $ajustes->api_key = ($datos['api_key'] ?? null) ?: null;
        }

        $ajustes->save();

        return back()->with('panel_ok', $ajustes->configurado()
            ? 'Ajustes guardados. Prueba la conexión para confirmar que la clave sirve.'
            : 'IA apagada: sin clave, el asistente no redacta respuestas.');
    }

    /**
     * Una llamada de verdad al proveedor, y su motivo literal si falla.
     *
     * Es lo que convierte «no funciona» en «la clave está vencida» o «se agotó la cuota». Probar
     * contra la API real es lo que destapó cuatro discrepancias con el manual de Zernio.
     */
    public function probar(AiProvider $provider): RedirectResponse
    {
        $ajustes = AiSetting::actual();

        if (! $ajustes->configurado()) {
            return back()->with('panel_error', 'Primero pon una clave.');
        }

        try {
            $vector = $ajustes->puedeIndexar() ? $provider->embed('prueba de conexión') : [];
            $respuesta = trim($provider->chat([
                ['role' => 'user', 'content' => 'Responde solo con la palabra: listo'],
            ]));
        } catch (Throwable $e) {
            return back()->with('panel_error', 'No se pudo hablar con el proveedor: '.$this->motivo($e));
        }

        if ($respuesta === '') {
            return back()->with('panel_error', 'El proveedor respondió, pero vacío. Revisa el modelo de chat.');
        }

        return back()->with('panel_ok', $ajustes->puedeIndexar()
            ? "Conexión correcta. Contestó «{$respuesta}» y devolvió un vector de ".count($vector).' dimensiones.'
            : "Conexión correcta. Contestó «{$respuesta}». Este proveedor no indexa documentos.");
    }

    /** Reindexa una tanda con el proveedor de ahora. */
    public function reindexar(RagService $rag): RedirectResponse
    {
        try {
            $resultado = $rag->reindexar(self::POR_TANDA);
        } catch (Throwable $e) {
            return back()->with('panel_error', 'No se pudo reindexar: '.$this->motivo($e));
        }

        if ($resultado['convertidos'] === 0) {
            return back()->with('panel_ok', 'No quedaba nada por reindexar.');
        }

        return back()->with('panel_ok', $resultado['quedan'] > 0
            ? "Reindexados {$resultado['convertidos']}. Quedan {$resultado['quedan']}: vuelve a pulsar."
            : "Reindexados {$resultado['convertidos']}. Ya está todo al día.");
    }

    /**
     * Fragmentos desfasados de TODAS las empresas.
     *
     * Sin el ámbito de empresa a propósito: esta pantalla es del operador, que necesita el total de
     * la plataforma y no el de la empresa que tenga abierta.
     */
    private function desfasadosDeTodas(AiSetting $ajustes): int
    {
        return AiDocumentChunk::query()
            ->withoutGlobalScopes()
            ->where(fn ($q) => $q->where('provider', '!=', $ajustes->provider)
                ->orWhere('dimensions', '!=', $ajustes->embedding_dimensions))
            ->count();
    }

    /** El motivo, corto y sin arrastrar la clave dentro del mensaje. */
    private function motivo(Throwable $e): string
    {
        $motivo = str($e->getMessage())->limit(300)->toString();

        return preg_replace('/(AIza|sk-|sk_)[A-Za-z0-9_\-]{8,}/', '***', $motivo) ?? 'error desconocido';
    }

    private function modeloPorOmision(string $proveedor, string $tipo): ?string
    {
        return match ([$proveedor, $tipo]) {
            ['gemini', 'chat'] => 'gemini-2.0-flash',
            ['gemini', 'embedding'] => 'gemini-embedding-001',
            ['openai', 'chat'] => 'gpt-4o-mini',
            ['openai', 'embedding'] => 'text-embedding-3-small',
            ['anthropic', 'chat'] => 'claude-sonnet-5',
            default => null,
        };
    }
}
