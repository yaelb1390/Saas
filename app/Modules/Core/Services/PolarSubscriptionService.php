<?php

declare(strict_types=1);

namespace App\Modules\Core\Services;

use App\Modules\Core\Models\Subscription;
use App\Modules\Core\Models\SystemEvent;
use Illuminate\Support\Facades\Log;

/**
 * Deja que el cliente cancele su suscripción —o se arrepienta— sin escribirnos.
 *
 * Cancelar es dejar de renovar, NO cortar el acceso: la suscripción sigue activa hasta el final del
 * período que ya pagó, y solo entonces Polar la revoca y la app retira el acceso. Cortar antes le
 * quitaría un servicio por el que ya pagó.
 *
 * Polar es la fuente de verdad: primero se le pide a él y, solo si lo acepta, se anota aquí. Así la
 * app nunca dice «cancelada» de algo que Polar va a seguir cobrando. Cuando llega el aviso de Polar
 * (`subscription.canceled` / `subscription.uncanceled`) repite la misma anotación, y da igual: es
 * idempotente. También recoge las bajas hechas directamente en Polar.
 */
final class PolarSubscriptionService
{
    public function __construct(
        private readonly PolarClient $polar,
        private readonly SubscriptionService $subscriptions,
    ) {}

    /**
     * Cancela la renovación: la suscripción sigue hasta el fin del período pagado.
     *
     * @return bool `true` si quedó cancelada (o ya lo estaba); `false` si no se pudo.
     */
    public function cancelAtPeriodEnd(Subscription $subscription, ?int $userId = null): bool
    {
        // Ya pedida: nada que hacer, y no se molesta a Polar. Un doble clic no debe dar un error.
        if ($subscription->endsAtPeriodEnd()) {
            return true;
        }

        // Solo se cancela lo que Polar cobra solo. Una suscripción en prueba o asignada a mano no
        // tiene nada que cancelar allí, y mandar la petición no tendría a qué suscripción apuntar.
        if (! $subscription->renewsAutomatically()) {
            return false;
        }

        if (! $this->askPolar($subscription, cancel: true)) {
            return false;
        }

        // La anotación, el rastro en el registro del sistema, el evento y el correo al cliente los
        // resuelve `SubscriptionService`, y a la vez para todas las puertas de entrada. Si el aviso de
        // Polar llegó antes que esta línea, ya está anotada y no se envía un segundo correo.
        $this->subscriptions->scheduleCancellation($subscription, $userId);

        return true;
    }

    /**
     * Deshace la baja pedida: la suscripción vuelve a renovarse sola.
     *
     * Solo tiene sentido mientras el período pagado siga vigente. Pasada esa fecha, Polar ya la
     * revocó y el cliente tiene que contratar de nuevo.
     *
     * @return bool `true` si quedó renovándose (o ya lo hacía); `false` si no se pudo.
     */
    public function resume(Subscription $subscription, ?int $userId = null): bool
    {
        if ($subscription->renewsAutomatically()) {
            return true;
        }

        if (! $subscription->endsAtPeriodEnd()) {
            return false;
        }

        if (! $this->askPolar($subscription, cancel: false)) {
            return false;
        }

        // Igual que al cancelar: `SubscriptionService` anota, deja rastro y avisa al cliente una sola vez.
        $this->subscriptions->unscheduleCancellation($subscription, $userId);

        return true;
    }

    /**
     * Le pide a Polar que cancele (o deje de cancelar) la renovación.
     *
     * Polar contesta con un ERROR cuando la suscripción ya está en el estado que se pide: 403
     * `AlreadyCanceledSubscription` al cancelar una ya cancelada, y 409 `SubscriptionNotScheduledToCancel`
     * al reactivar una que no lo estaba (comprobado contra su API real). Para el cliente eso es un
     * éxito —lo que quería ya es verdad—, y tratarlo como fallo le enseñaría un error tras un doble
     * clic o cuando la app va por detrás de Polar.
     */
    private function askPolar(Subscription $subscription, bool $cancel): bool
    {
        if (! $this->polar->isConfigured() || blank($subscription->polar_subscription_id)) {
            return false;
        }

        $response = $this->polar->http()->patch(
            $this->polar->url('/v1/subscriptions/'.rawurlencode((string) $subscription->polar_subscription_id)),
            ['cancel_at_period_end' => $cancel],
        );

        if ($response->successful()) {
            return true;
        }

        $yaEnEseEstado = $cancel ? 'AlreadyCanceledSubscription' : 'SubscriptionNotScheduledToCancel';

        if ($response->json('error') === $yaEnEseEstado) {
            return true;
        }

        // Un fallo aquí no debe reventar la pantalla: el cliente verá un aviso y podrá escribirnos.
        // Se registra el motivo porque suele ser algo concreto y arreglable (token sin permiso de
        // escritura, suscripción que no existe en ese entorno).
        SystemEvent::registrar(
            type: 'integration.failed',
            message: $cancel
                ? 'Polar: no se pudo cancelar la suscripción'
                : 'Polar: no se pudo reactivar la suscripción',
            contexto: [
                'estado' => $response->status(),
                'respuesta' => mb_substr($response->body(), 0, 300),
            ],
            level: SystemEvent::AVISO,
            companyId: (int) $subscription->company_id,
        );

        Log::error('Polar: no se pudo cambiar la renovación de la suscripción', [
            'empresa' => $subscription->company_id,
            'cancelar' => $cancel,
            'estado' => $response->status(),
            'respuesta' => mb_substr($response->body(), 0, 500),
        ]);

        return false;
    }
}
