<?php

declare(strict_types=1);

namespace App\Modules\Core\Events;

use App\Modules\Core\Models\Subscription;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Falló el cobro de la renovación de una suscripción: Polar la marcó `past_due`. El cliente todavía
 * puede recuperarla actualizando su tarjeta.
 *
 * Punto de enganche para el correo al cliente y para automatizaciones (n8n: avisar por WhatsApp a
 * quien no abre el correo). Se dispara como mucho una vez al día por suscripción, aunque Polar
 * reintente el cobro y vuelva a avisar: ver `SubscriptionService::notifyPaymentFailure()`.
 */
final class SubscriptionPaymentFailed
{
    use Dispatchable;

    public function __construct(public readonly Subscription $subscription) {}
}
