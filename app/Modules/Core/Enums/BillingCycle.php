<?php

declare(strict_types=1);

namespace App\Modules\Core\Enums;

use Illuminate\Support\Carbon;

enum BillingCycle: string
{
    case Monthly = 'monthly';
    case Quarterly = 'quarterly';
    case Yearly = 'yearly';

    public function label(): string
    {
        return match ($this) {
            self::Monthly => 'Mensual',
            self::Quarterly => 'Trimestral',
            self::Yearly => 'Anual',
        };
    }

    /**
     * Avanza una fecha un período de este ciclo (para calcular la próxima renovación).
     */
    public function advance(Carbon $from): Carbon
    {
        return match ($this) {
            self::Monthly => $from->copy()->addMonthNoOverflow(),
            self::Quarterly => $from->copy()->addMonthsNoOverflow(3),
            self::Yearly => $from->copy()->addYear(),
        };
    }

    /**
     * Con cuántos días de anticipación se empieza a avisar del vencimiento.
     *
     * Escala con el ciclo: un plan anual merece más margen para reaccionar que uno mensual. Es la
     * fuente única del umbral; el aviso al usuario lo consulta desde aquí.
     */
    public function noticeThresholdDays(): int
    {
        return match ($this) {
            self::Monthly => 5,
            self::Quarterly => 10,
            self::Yearly => 30,
        };
    }
}
