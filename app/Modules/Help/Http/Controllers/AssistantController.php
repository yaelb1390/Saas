<?php

declare(strict_types=1);

namespace App\Modules\Help\Http\Controllers;

use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\Help\Http\Requests\AskAssistantRequest;
use App\Modules\Help\Services\AssistantAnswerer;
use App\Modules\Help\Services\AssistantQuota;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * El asistente de la burbuja: una pregunta, una respuesta, sin salir de la pantalla.
 *
 * Todo el trabajo de verdad está en `AssistantAnswerer`; aquí solo se comprueba quién puede preguntar
 * y se traduce a JSON.
 */
final class AssistantController extends Controller
{
    public function ask(
        AskAssistantRequest $request,
        CurrentCompany $actual,
        AssistantQuota $cuota,
        AssistantAnswerer $asistente,
    ): JsonResponse {
        $empresa = $actual->model();

        /*
         * Se comprueba aquí y no solo al pintar el botón.
         *
         * Ocultar la burbuja no cierra nada: la dirección se puede llamar a mano desde la consola del
         * navegador, y entonces una empresa a la que se le apagó el asistente —o una suspendida—
         * seguiría gastando de tu cuota.
         */
        abort_unless($empresa !== null && $empresa->usaAsistente(), 403);

        if ($cuota->agotada($empresa->id)) {
            /*
             * 200 y no 429 a propósito. No es un error de la petición: es una respuesta legítima que
             * la burbuja tiene que pintar como un mensaje más. Con un código de error, el JavaScript
             * lo trataría como «se rompió» y el usuario no sabría qué pasó.
             */
            return response()->json([
                'respuesta' => 'Por hoy se acabaron las preguntas del asistente para tu empresa. Vuelve mañana, o escríbenos si es urgente.',
                'agotada' => true,
                'articulo' => null,
                'restantes' => 0,
            ]);
        }

        $resultado = $asistente->responder(
            (string) $request->validated('pregunta'),
            $request->user(),
            (int) $empresa->id,
        );

        return response()->json($resultado + ['restantes' => $cuota->restantes($empresa->id)]);
    }

    /**
     * Empezar de cero.
     *
     * Hace falta de verdad: el hilo vive en la sesión y va con el usuario de pantalla en pantalla, así
     * que sin esto una conversación sobre las ventas de ayer seguiría dando contexto a una pregunta de
     * inventario tres horas después.
     */
    public function reset(CurrentCompany $actual): JsonResponse
    {
        $empresa = $actual->model();

        abort_unless($empresa !== null && $empresa->usaAsistente(), 403);

        session()->forget(AssistantAnswerer::claveDeHilo($empresa->id));

        return response()->json(['ok' => true]);
    }
}
