<?php

declare(strict_types=1);

namespace App\Modules\Dealer\Enums;

/**
 * Cómo está una cuota del financiamiento del dealer.
 *
 * Es un enum propio y no el de Préstamos a propósito: el dealer financia sin tener ese módulo
 * contratado, y apuntar a él acoplaría dos cosas que se venden por separado. Lo que sí se comparte
 * de verdad —el reparto del dinero— vive en `PlanDeCuotas`, que es donde importa no duplicar.
 */
enum DealInstallmentStatus: string
{
    case Pending = 'pending';
    case Partial = 'partial';
    case Paid = 'paid';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pendiente',
            self::Partial => 'Abonada',
            self::Paid => 'Saldada',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Pending => 'badge-gray',
            self::Partial => 'badge-amber',
            self::Paid => 'badge-green',
        };
    }
}
