<?php

declare(strict_types=1);

namespace App\Modules\AI\Http\Controllers;

use App\Modules\AI\Models\AiDocumentChunk;
use App\Modules\AI\Models\AiSetting;
use App\Modules\AI\Providers\Contracts\AiProvider;
use App\Modules\AI\Services\RagService;
use App\Modules\AI\Support\CatalogoDeModelos;
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
            // Cuántas preguntas al día puede hacer cada empresa al asistente. El cero lo apaga de
            // hecho para todas, que es una forma legítima de cerrar el grifo sin ir empresa por
            // empresa; el tope de arriba evita que un dedazo abra la mano del todo.
            'daily_limit' => ['nullable', 'integer', 'min:0', 'max:1000'],
        ]);

        $ajustes = AiSetting::actual();

        $ajustes->fill([
            'provider' => $datos['provider'],
            // Un campo opcional que NO se envia no llega en el array validado, y leerlo a secas
            // es un 500 que ademas deja el guardado a medias sin decir nada.
            'chat_model' => ($datos['chat_model'] ?? null) ?: $this->modeloPorOmision($datos['provider'], 'chat'),
            'embedding_model' => ($datos['embedding_model'] ?? null) ?: $this->modeloPorOmision($datos['provider'], 'embedding'),
            'embedding_dimensions' => ($datos['embedding_dimensions'] ?? null) ?: 768,
            // El cero es un valor válido y querido, así que NO se puede usar «?:» aquí: convertiría
            // «ninguna pregunta» en «cincuenta».
            'daily_limit' => $datos['daily_limit'] ?? 50,
        ]);

        /*
         * La clave solo se toca si viene escrita, o si se pide borrarla marcando la casilla.
         *
         * Antes la condición era `$request->has('api_key')`, y eso se cumplía SIEMPRE: un campo de
         * contraseña viaja en cada envío aunque esté vacío. Resultado: cualquier guardado normal
         * —cambiar el modelo, subir el tope diario— borraba la clave y dejaba la IA apagada para
         * todas las empresas sin decir una palabra, justo lo contrario de lo que promete el propio
         * formulario («déjalo vacío para conservarla»). Comprobado: la clave estaba y tras un
         * guardado con el campo en blanco ya no estaba.
         *
         * El comentario anterior daba por hecho que el campo no se enviaba si estaba vacío. No es
         * así, y por eso borrar se pide ahora de forma explícita: quitar una clave es una decisión,
         * no algo que deba pasar por omisión.
         */
        if ($request->filled('api_key')) {
            $ajustes->api_key = $datos['api_key'];
        } elseif ($request->boolean('borrar_api_key')) {
            $ajustes->api_key = null;
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

    /**
     * Qué modelo se guarda cuando el formulario no manda ninguno.
     *
     * Delega en el catálogo en vez de repetir la tabla aquí. Cuando eran dos listas ya se habían
     * separado: esta decía `gemini-2.0-flash` y el catálogo de `config/ai.php`, `gemini-2.5-flash`.
     */
    private function modeloPorOmision(string $proveedor, string $tipo): ?string
    {
        return $tipo === 'chat'
            ? CatalogoDeModelos::chatPorOmision($proveedor)
            : CatalogoDeModelos::embeddingPorOmision($proveedor);
    }
}
