<?php

declare(strict_types=1);

namespace App\Modules\Core\Http\Controllers;

use App\Modules\Core\Services\PolarWebhookHandler;
use App\Modules\Core\Support\PolarSignature;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Recibe los avisos de Polar (pagos, altas y bajas de suscripción).
 *
 * Puerta abierta a internet y sin sesión: la firma es lo ÚNICO que separa un aviso legítimo de
 * alguien regalándose una suscripción con un POST. Por eso se verifica antes de mirar el cuerpo, y
 * sin secreto configurado no se acepta absolutamente nada.
 *
 * Se responde 202 en cuanto se procesa. Polar corta a los 10 segundos y reintenta hasta 10 veces
 * ante cualquier respuesta que no sea 2xx; de ahí que el registro del evento sea idempotente.
 */
final class PolarWebhookController extends Controller
{
    public function __invoke(Request $request, PolarWebhookHandler $handler): JsonResponse
    {
        $signature = PolarSignature::fromConfig();

        // Sin secreto no se puede distinguir a Polar de cualquier otro: se rechaza todo. Callar y
        // devolver 200 sería peor: los avisos se darían por entregados y los pagos se perderían
        // en silencio.
        abort_unless($signature->isConfigured(), 503, 'El webhook de Polar no está configurado.');

        $eventId = (string) $request->header('webhook-id', '');

        // El cuerpo CRUDO, sin decodificar: la firma se calculó sobre estos bytes exactos.
        $payload = $request->getContent();

        $verified = $signature->verify(
            id: $eventId,
            timestamp: (string) $request->header('webhook-timestamp', ''),
            signatureHeader: (string) $request->header('webhook-signature', ''),
            payload: $payload,
        );

        abort_unless($verified, 401, 'Firma del webhook no válida.');

        $body = json_decode($payload, true);

        abort_unless(is_array($body), 400, 'Cuerpo del webhook ilegible.');

        $event = $handler->handle($eventId, $body);

        return response()->json([
            'ok' => true,
            'result' => $event->result,
        ], 202);
    }
}
