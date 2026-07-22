<?php

declare(strict_types=1);

namespace App\Modules\Core\Enums;

enum SubscriptionStatus: string
{
    case Trialing = 'trialing';   // en período de prueba
    case Active = 'active';       // al día
    case PastDue = 'past_due';    // venció y no ha pagado (aún con gracia visible)
    case Suspended = 'suspended'; // cortada por falta de pago
    case Cancelled = 'cancelled'; // dada de baja

    public function label(): string
    {
        return match ($this) {
            self::Trialing => 'Prueba',
            self::Active => 'Activa',
            self::PastDue => 'Vencida',
            self::Suspended => 'Suspendida',
            self::Cancelled => 'Cancelada',
        };
    }

    public function badge(): string
    {
        return match ($this) {
            self::Active => 'badge-green',
            self::Trialing => 'badge-blue',
            self::PastDue => 'badge-amber',
            self::Suspended, self::Cancelled => 'badge-red',
        };
    }
}
