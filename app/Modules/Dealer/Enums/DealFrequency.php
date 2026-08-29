<?php

declare(strict_types=1);

namespace App\Modules\Dealer\Enums;

use Illuminate\Support\Carbon;

/**
 * Cada cuánto paga el cliente que compró financiado por el propio dealer.
 *
 * No hay «diario», al revés que en préstamos: un carro no se cobra a diario. Ofrecer una frecuencia
 * que nadie usa solo sirve para que alguien la elija por error.
 */
enum DealFrequency: string
{
    case Weekly = 'weekly';
    case Biweekly = 'biweekly';
    case Monthly = 'monthly';

    public function label(): string
    {
        return match ($this) {
            self::Weekly => 'Semanal',
            self::Biweekly => 'Quincenal',
            self::Monthly => 'Mensual',
        };
    }

    /**
     * Avanza al siguiente vencimiento.
     *
     * `addMonthNoOverflow` para que un trato firmado un día 31 no salte al mes siguiente: el cliente
     * reclamaría con razón si su cuota de febrero venciera en marzo.
     */
    public function advance(Carbon $from): Carbon
    {
        return match ($this) {
            self::Weekly => $from->copy()->addWeek(),
            self::Biweekly => $from->copy()->addDays(15),
            self::Monthly => $from->copy()->addMonthNoOverflow(),
        };
    }
}
