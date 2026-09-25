<?php

declare(strict_types=1);

namespace App\Modules\SocialCommerce\Http\Controllers;

use App\Modules\Core\Models\SystemEvent;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\SocialCommerce\Models\Settings;
use App\Modules\SocialCommerce\Models\WebhookEvent;
use App\Modules\SocialCommerce\Services\WebhookEventProcessor;
use App\Modules\SocialCommerce\Services\WebhookSignature;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Throwable;

/**
 * Recibe los avisos `message.received` del webhook PROPIO de Social Commerce (no el de `Social`,
 * arquitectura sección 2).
 *
 * Mismo doble cierre que el resto de webhooks del proyecto:
 *  1. La EMPRESA sale de un token opaco en la URL, nunca del cuerpo.
 *  2. La FIRMA (HMAC-SHA256) demuestra que lo mandó Zernio.
 *
 * Idempotencia por `social_commerce_webhook_events.event_id`, con el mismo patrón de
 * «inserta-primero-y-confía-en-la-restricción-única» que `PolarWebhookHandler::claim()`, porque un
 * `exists()` previo tiene la misma condición de carrera bajo entregas simultáneas.
 */
final class WebhookController extends Controller
{
    public function __invoke(Request $request, string $token, WebhookEventProcessor $processor, CurrentCompany $currentCompany): JsonResponse
    {
        $ajustes = Settings::withoutGlobalScopes()->with('company')->where('webhook_token', $token)->first();

        // Mismo 401 genérico tanto si el token no existe como si la firma falla: distinguir los
        // dos casos le diría a quien prueba direcciones cuándo acertó con una.
        if ($ajustes === null || $ajustes->company === null) {
            SystemEvent::registrar(
                type: 'webhook.rejected',
                message: 'Aviso de Social Commerce rechazado: la dirección no corresponde a ninguna empresa',
                level: SystemEvent::AVISO,
            );

            abort(401, 'Aviso no autorizado.');
        }

        $firma = new WebhookSignature((string) $ajustes->webhook_secret);

        if (! $firma->verify((string) $request->header('X-Zernio-Signature', ''), $request->getContent())) {
            SystemEvent::registrar(
                type: 'webhook.rejected',
                message: 'Aviso de Social Commerce rechazado: firma no válida',
                level: SystemEvent::AVISO,
                companyId: (int) $ajustes->company_id,
            );

            abort(401, 'Aviso no autorizado.');
        }

        $body = (array) $request->json()->all();
        $eventId = $this->idDeEvento($body);

        $evento = $this->reclamar($eventId, $body);

        if ($evento === null) {
            // Ya se procesó: es un reintento de Zernio. Se contesta bien sin repetir nada.
            return response()->json(['ok' => true, 'resultado' => 'repetido']);
        }

        $currentCompany->set((int) $ajustes->company_id);

        try {
            $resultado = $processor->handle($ajustes->company, $body);
            $evento->resolveAs(WebhookEvent::RESULT_APPLIED, $resultado, (int) $ajustes->company_id);
        } catch (Throwable $e) {
            // Un fallo nuestro no puede devolver 500: Zernio reintentaría y convertiría un error
            // en un bucle. Se deja constancia y se contesta que quedó recibido, igual que hace
            // ZernioWebhookController con la bienvenida.
            report($e);
            $evento->resolveAs(WebhookEvent::RESULT_UNRESOLVED, 'Fallo al procesar: '.$e->getMessage(), (int) $ajustes->company_id);

            return response()->json(['ok' => true, 'resultado' => 'error'], 200);
        }

        return response()->json(['ok' => true, 'resultado' => $resultado]);
    }

    /**
     * Reserva el aviso en la bitácora. Devuelve null si ya estaba —la señal de reintento—, igual
     * que `PolarWebhookHandler::claim()`.
     *
     * @param  array<string, mixed>  $body
     */
    private function reclamar(string $eventId, array $body): ?WebhookEvent
    {
        try {
            return WebhookEvent::create([
                'event_id' => $eventId,
                'type' => 'message.received',
                'result' => 'received',
                'payload' => $body,
            ]);
        } catch (QueryException $e) {
            if (WebhookEvent::query()->where('event_id', $eventId)->exists()) {
                return null;
            }

            throw $e;
        }
    }

    /**
     * Zernio no manda un identificador de entrega único a nivel del aviso (a diferencia de
     * Polar, que sí trae `webhook-id`): se construye uno estable a partir del identificador de
     * mensaje de la plataforma, o de la conversación+dirección+texto cuando falta, para que dos
     * entregas del mismo aviso produzcan la misma clave.
     *
     * @param  array<string, mixed>  $body
     */
    private function idDeEvento(array $body): string
    {
        $platformMessageId = data_get($body, 'message.platformMessageId');

        if (is_string($platformMessageId) && $platformMessageId !== '') {
            return 'msg:'.$platformMessageId;
        }

        $conversacion = (string) data_get($body, 'message.conversationId', '');
        $direccion = (string) data_get($body, 'message.direction', '');
        $texto = (string) data_get($body, 'message.text', '');

        return 'hash:'.hash('sha256', $conversacion.'|'.$direccion.'|'.$texto);
    }
}
