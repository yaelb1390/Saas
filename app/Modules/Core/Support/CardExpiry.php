<?php

declare(strict_types=1);

namespace App\Modules\Core\Support;

use Illuminate\Support\Carbon;

/**
 * La tarjeta con la que Polar cobra la renovación: cuál es y hasta cuándo vale.
 *
 * Una tarjeta vale hasta el ÚLTIMO DÍA del mes que dice («10/2026» sirve hasta el 31 de octubre, incluido),
 * no hasta el día 1. Confundirlo daría el aviso de «vencida» durante todo el mes en que aún funciona.
 *
 * Es una pieza pura —sin red ni base de datos— para poder probar a fondo la decisión que importa:
 * ¿va a fallar el cobro por culpa de la tarjeta?
 */
final class CardExpiry
{
    public function __construct(
        public readonly string $brand,
        public readonly string $last4,
        public readonly int $month,
        public readonly int $year,
    ) {}

    /**
     * La tarjeta que Polar usará, según lo que devuelve su API (`GET /v1/customers/{id}/payment-methods`).
     *
     * Polar no dice qué tarjeta se cobra en cada renovación, pero sí cuál es la predeterminada del cliente.
     * Sin predeterminada se toma la añadida más recientemente: es la que el cliente acaba de usar. Los
     * métodos que no son tarjeta (no traen fecha de vencimiento) se ignoran.
     *
     * @param  array<int, mixed>  $methods  Los `items` de la respuesta.
     */
    public static function fromPaymentMethods(array $methods): ?self
    {
        $cards = [];

        foreach ($methods as $method) {
            $card = is_array($method) ? self::fromPaymentMethod($method) : null;

            if ($card !== null) {
                $cards[] = [
                    'card' => $card,
                    'default' => ($method['is_default'] ?? false) === true,
                    'created' => (string) ($method['created_at'] ?? ''),
                ];
            }
        }

        if ($cards === []) {
            return null;
        }

        // Primero la predeterminada; entre iguales, la más reciente (las fechas ISO se ordenan como texto).
        usort($cards, fn (array $a, array $b): int => [$b['default'], $b['created']] <=> [$a['default'], $a['created']]);

        return $cards[0]['card'];
    }

    /**
     * @param  array<string, mixed>  $method
     */
    private static function fromPaymentMethod(array $method): ?self
    {
        if (($method['type'] ?? null) !== 'card') {
            return null;
        }

        $meta = $method['method_metadata'] ?? null;
        $month = is_array($meta) ? ($meta['exp_month'] ?? null) : null;
        $year = is_array($meta) ? ($meta['exp_year'] ?? null) : null;

        // Sin una fecha creíble no se puede decir nada, y avisar a ciegas sería peor que callar.
        if (! is_int($month) || ! is_int($year) || $month < 1 || $month > 12 || $year < 2000 || $year > 2200) {
            return null;
        }

        return new self(
            brand: (string) ($meta['brand'] ?? ''),
            last4: (string) ($meta['last4'] ?? ''),
            month: $month,
            year: $year,
        );
    }

    /** El último instante en que la tarjeta sirve: el final del mes de vencimiento. */
    public function lastValidDay(): Carbon
    {
        return Carbon::create($this->year, $this->month, 1)->endOfMonth();
    }

    /** Como suele escribirse en la tarjeta: «10/2026». */
    public function label(): string
    {
        return sprintf('%02d/%d', $this->month, $this->year);
    }

    /** «Visa», «Mastercard», «American Express»… Vacío si Polar no dijo la marca. */
    public function brandLabel(): string
    {
        return match (mb_strtolower($this->brand)) {
            '' => '',
            'amex' => 'American Express',
            'diners' => 'Diners Club',
            'unionpay' => 'UnionPay',
            default => ucwords(str_replace('_', ' ', mb_strtolower($this->brand))),
        };
    }

    /** ¿La tarjeta ya no servirá el día del cobro? Es el caso grave: ese cobro va a fallar. */
    public function failsAt(Carbon $chargeDate): bool
    {
        return $this->lastValidDay()->lt($chargeDate);
    }

    /**
     * ¿Hay que avisar? Sí si la tarjeta falla el día del cobro o caduca en el mes siguiente.
     *
     * Con un plan mensual «en el mes siguiente» es justo «antes del cobro que viene»: se avisa con unos 30
     * días de margen para cambiarla ANTES de que falle, y no cuando ya falló. Es un mes de calendario y no
     * «30 días» porque octubre tiene 31: con 30, una tarjeta que vence el 31/10 no saltaría con el cobro del
     * 1 de octubre y fallaría el del 1 de noviembre sin aviso. Con un plan anual lo que se avisa es una
     * tarjeta que caduca justo después de renovar.
     */
    public function atRisk(Carbon $chargeDate): bool
    {
        return $this->lastValidDay()->lt($chargeDate->copy()->addMonthNoOverflow());
    }
}
