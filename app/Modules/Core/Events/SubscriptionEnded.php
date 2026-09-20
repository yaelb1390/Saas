<?php

declare(strict_types=1);

namespace App\Modules\Core\Events;

use App\Modules\Core\Models\Subscription;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * La suscripción TERMINÓ: Polar la revocó y la app retiró el acceso.
 *
 * `$requestedByCustomer` distingue el fin que el cliente pidió (canceló y llegó el final del período que
 * ya había pagado) del que no pidió (no se pudo cobrar la renovación, o la dio de baja el operador). El
 * correo dice cosas distintas en cada caso, y una automatización (n8n: una oferta para recuperar al
 * cliente) también las trataría distinto.
 *
 * Se dispara UNA vez: ver `SubscriptionService::end()`.
 */
final class SubscriptionEnded
{
    use Dispatchable;

    public function __construct(
        public readonly Subscription $subscription,
        public readonly bool $requestedByCustomer,
    ) {}
}
