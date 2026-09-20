<?php

declare(strict_types=1);

namespace App\Modules\Core\Events;

use App\Modules\Core\Models\Subscription;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Se dispara UNA vez cuando una suscripción pasa a «baja pedida»: deja de renovarse, pero el cliente
 * conserva el acceso hasta el fin del período que ya pagó.
 *
 * Punto de enganche para automatizaciones (n8n: un mensaje de recuperación, avisar a ventas) y para
 * el correo al cliente. Sale igual venga de donde venga la baja —el botón del panel, el portal de
 * Polar o el panel de Polar—, porque quien lo dispara es el cambio de estado y no la puerta por la que
 * entró. Por eso no hay que acordarse de engancharse a cada camino.
 *
 * `$userId` es quien pulsó el botón, o null si la baja vino de Polar directamente.
 */
final class SubscriptionCancellationRequested
{
    use Dispatchable;

    public function __construct(
        public readonly Subscription $subscription,
        public readonly ?int $userId = null,
    ) {}
}
