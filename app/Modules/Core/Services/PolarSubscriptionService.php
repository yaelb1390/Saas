<?php

declare(strict_types=1);

namespace App\Modules\Core\Services;

use App\Modules\Core\Models\Subscription;
use App\Modules\Core\Models\SystemEvent;
use App\Modules\Core\Support\CardExpiry;
use Illuminate\Http\Client\ConnectionException;
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
     * La dirección del portal de Polar del cliente, donde cambia su tarjeta y ve sus facturas.
     *
     * Se pide a Polar EN EL MOMENTO de usarla, nunca se guarda ni se pone en un correo: el enlace de una
     * sesión del portal caduca en una hora, y un correo se puede abrir días después. Por eso el botón
     * «Actualizar mi tarjeta» apunta a una ruta de la app, que llama a esto y redirige.
     *
     * @return string|null La dirección, o null si no se pudo (sin pasarela, la suscripción no viene de
     *                     Polar, o Polar no contestó bien).
     */
    public function customerPortalUrl(Subscription $subscription, string $returnUrl): ?string
    {
        if (! $this->polar->isConfigured() || blank($subscription->polar_customer_id)) {
            return null;
        }

        // La barra final importa: sin ella Polar responde una redirección y la petición se pierde.
        $response = $this->polar->http()->post($this->polar->url('/v1/customer-sessions/'), [
            'customer_id' => (string) $subscription->polar_customer_id,
            'return_url' => $returnUrl,
        ]);

        if (! $response->successful()) {
            SystemEvent::registrar(
                type: 'integration.failed',
                message: 'Polar: no se pudo abrir el portal de pagos del cliente',
                contexto: [
                    'estado' => $response->status(),
                    'respuesta' => mb_substr($response->body(), 0, 300),
                ],
                level: SystemEvent::AVISO,
                companyId: (int) $subscription->company_id,
            );

            return null;
        }

        $url = $response->json('customer_portal_url');

        return is_string($url) && $url !== '' ? $url : null;
    }

    /**
     * La tarjeta con la que Polar cobrará la renovación, para avisar si va a fallar por culpa de ella.
     *
     * Es de SOLO LECTURA y nunca lanza: un aviso es un extra, y que Polar no conteste no puede impedir que
     * el cliente reciba el aviso de renovación de siempre. Por eso devuelve null tanto si el cliente no
     * tiene tarjeta como si no se pudo saber, y el llamante sigue con lo normal. Si fue un fallo (no un
     * «no hay tarjeta») deja rastro para el operador.
     *
     * Solo se llama en el momento del aviso de renovación —una vez por suscripción y período—, no en un
     * barrido diario de todas: cada llamada es una petición a Polar y el recordatorio corre en una función
     * de Vercel con el tiempo limitado.
     */
    public function cardOnFile(Subscription $subscription): ?CardExpiry
    {
        if (! $this->polar->isConfigured() || blank($subscription->polar_customer_id)) {
            return null;
        }

        try {
            // Tiempo corto a propósito: si Polar tarda, el aviso normal sale igual y no se retrasa el resto.
            $response = $this->polar->http()->timeout(8)->get(
                $this->polar->url('/v1/customers/'.rawurlencode((string) $subscription->polar_customer_id).'/payment-methods'),
                ['limit' => 100],
            );
        } catch (ConnectionException $e) {
            $this->registerCardLookupFailure($subscription, ['error' => 'Polar no contestó a tiempo']);

            return null;
        }

        if (! $response->successful()) {
            $this->registerCardLookupFailure($subscription, [
                'estado' => $response->status(),
                'respuesta' => mb_substr($response->body(), 0, 300),
            ]);

            return null;
        }

        $items = $response->json('items');

        return CardExpiry::fromPaymentMethods(is_array($items) ? $items : []);
    }

    /**
     * @param  array<string, mixed>  $contexto
     */
    private function registerCardLookupFailure(Subscription $subscription, array $contexto): void
    {
        SystemEvent::registrar(
            type: 'integration.failed',
            message: 'Polar: no se pudo consultar la tarjeta del cliente',
            contexto: $contexto,
            level: SystemEvent::AVISO,
            companyId: (int) $subscription->company_id,
        );
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
