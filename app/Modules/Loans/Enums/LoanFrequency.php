<?php

declare(strict_types=1);

namespace App\Modules\Loans\Enums;

use Illuminate\Support\Carbon;

/**
 * Frecuencia de cobro de un préstamo. El prestamista dominicano cobra a diario ("presta diario"),
 * por semana, por quincena o por mes. Define cómo se espacian los vencimientos de las cuotas.
 */
enum LoanFrequency: string
{
    case Daily = 'daily';
    case Weekly = 'weekly';
    case Biweekly = 'biweekly';
    case Monthly = 'monthly';

    public function label(): string
    {
        return match ($this) {
            self::Daily => 'Diario',
            self::Weekly => 'Semanal',
            self::Biweekly => 'Quincenal',
            self::Monthly => 'Mensual',
        };
    }

    /**
     * Avanza una fecha un período de esta frecuencia (para generar el calendario de cuotas).
     * addMonthNoOverflow evita que un préstamo iniciado el 31 salte a inicios del mes siguiente.
     */
    public function advance(Carbon $from): Carbon
    {
        return match ($this) {
            self::Daily => $from->copy()->addDay(),
            self::Weekly => $from->copy()->addWeek(),
            self::Biweekly => $from->copy()->addDays(15),
            self::Monthly => $from->copy()->addMonthNoOverflow(),
        };
    }
}
