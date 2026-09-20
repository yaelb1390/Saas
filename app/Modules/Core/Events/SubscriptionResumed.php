<?php

declare(strict_types=1);

namespace App\Modules\Core\Events;

use App\Modules\Core\Models\Subscription;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Se dispara UNA vez cuando el cliente se arrepiente de su baja y la suscripción vuelve a renovarse.
 *
 * Es la pareja de `SubscriptionCancellationRequested`, con la misma garantía: sale por el cambio de
 * estado, no por la puerta que se usó. `$userId` es null si vino de Polar directamente.
 */
final class SubscriptionResumed
{
    use Dispatchable;

    public function __construct(
        public readonly Subscription $subscription,
        public readonly ?int $userId = null,
    ) {}
}
