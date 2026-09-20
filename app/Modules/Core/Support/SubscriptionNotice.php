<?php

declare(strict_types=1);

namespace App\Modules\Core\Support;

use App\Modules\Core\Models\Subscription;
use Illuminate\Support\Carbon;

/**
 * Aviso que se muestra al usuario cuando su prueba o su período está por vencer.
 *
 * Es la fuente única de la lógica «¿hay que avisar y con qué tono?». La vista solo pinta lo que este
 * objeto decide; así el banner y la ventana emergente nunca discrepan.
 *
 * Niveles: «info» (prueba con margen) · «warning» (por vencer) · «critical» (≤3 días → popup).
 */
final class SubscriptionNotice
{
    public function __construct(
        public readonly string $level,
        public readonly string $message,
        public readonly int $days,
        public readonly Carbon $renewsAt,
        public readonly bool $isTrial,
        public readonly ?Carbon $purgeAt = null,
    ) {}

    /**
     * Construye el aviso para una suscripción, o null si no hay nada que avisar.
     *
     * Devuelve null cuando: no hay suscripción, no es usable (esos casos ya los maneja la página de
     * suspensión / el acceso heredado), o todavía queda margen de sobra.
     */
    public static function for(?Subscription $subscription): ?self
    {
        if ($subscription === null || ! $subscription->isUsable()) {
            return null;
        }

        $renews = $subscription->renewsAt();

        if ($renews === null) {
            return null;
        }

        $days = $subscription->daysUntilRenewal() ?? 0;

        if ($subscription->isTrialing()) {
            $message = "Tu período de prueba termina en {$days} ".self::dias($days)
                ." ({$renews->format('d/m/Y')}). Contacta para activar tu plan y no perder el acceso.";

            // Prueba self-service (con fecha de purga): se advierte que los datos son de prueba y se
            // eliminarán 24 h después de vencer si no se contrata un plan.
            if ($subscription->purge_at !== null) {
                $message .= ' Los datos que registres son de prueba y se eliminarán el '
                    .$subscription->purge_at->format('d/m/Y').' si no activas un plan.';
            }

            return new self(
                level: $days <= 3 ? 'critical' : 'info',
                message: $message,
                days: $days,
                renewsAt: $renews,
                isTrial: true,
                purgeAt: $subscription->purge_at,
            );
        }

        // Una suscripción que Polar renueva SOLA no «vence»: se renovará, y no hay nada que pedirle al
        // cliente. Decirle «Renueva para no perder el acceso» lo llevaba a intentar pagar algo que ya se
        // paga solo o a escribir a soporte preocupado. Su aviso propio es el de «se renovará el…»: ver
        // `renewalNoticeDays()`.
        if ($subscription->renewsAutomatically()) {
            return null;
        }

        // Suscripción de pago: se avisa solo dentro del umbral del ciclo (5/10/30 días).
        $threshold = $subscription->plan?->billing_cycle->noticeThresholdDays() ?? 7;

        if ($days <= $threshold) {
            // Quien pidió la baja SÍ perderá el acceso en esa fecha, pero lo que tiene que hacer no es
            // «renovar»: es reactivar la suscripción que canceló.
            $message = $subscription->endsAtPeriodEnd()
                ? "Tu suscripción termina en {$days} ".self::dias($days)." ({$renews->format('d/m/Y')}). Reactívala para no perder el acceso."
                : "Tu suscripción vence en {$days} ".self::dias($days)." ({$renews->format('d/m/Y')}). Renueva para no perder el acceso.";

            return new self(
                level: $days <= 3 ? 'critical' : 'warning',
                message: $message,
                days: $days,
                renewsAt: $renews,
                isTrial: false,
            );
        }

        return null;
    }

    /**
     * Días que faltan para el cobro automático, si toca AVISAR de él; null si no.
     *
     * Es la pareja de `for()` para la suscripción que Polar renueva sola: `for()` no le dice nada («no
     * vence»), y esto decide cuándo mandarle el aviso de «se renovará el…». Usa el mismo umbral del ciclo
     * (5/10/30 días) para que el aviso salga en el mismo momento en que a las demás les saldría el suyo.
     */
    public static function renewalNoticeDays(Subscription $subscription): ?int
    {
        if (! $subscription->renewsAutomatically()) {
            return null;
        }

        $days = $subscription->daysUntilRenewal();

        if ($days === null || $days < 0) {
            return null;
        }

        $threshold = $subscription->plan?->billing_cycle->noticeThresholdDays() ?? 7;

        return $days <= $threshold ? $days : null;
    }

    /**
     * Clave de descarte: cambia con la fecha de renovación, de modo que un período nuevo hace que
     * el aviso vuelva a mostrarse aunque el usuario lo cerrara antes.
     */
    public function dismissKey(): string
    {
        return $this->level.'-'.$this->renewsAt->format('Ymd');
    }

    private static function dias(int $n): string
    {
        return abs($n) === 1 ? 'día' : 'días';
    }
}
