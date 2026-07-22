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
            return new self(
                level: $days <= 3 ? 'critical' : 'info',
                message: "Tu período de prueba termina en {$days} ".self::dias($days)
                    ." ({$renews->format('d/m/Y')}). Contacta para activar tu plan y no perder el acceso.",
                days: $days,
                renewsAt: $renews,
                isTrial: true,
            );
        }

        // Suscripción de pago: se avisa solo dentro del umbral del ciclo (5/10/30 días).
        $threshold = $subscription->plan?->billing_cycle->noticeThresholdDays() ?? 7;

        if ($days <= $threshold) {
            return new self(
                level: $days <= 3 ? 'critical' : 'warning',
                message: "Tu suscripción vence en {$days} ".self::dias($days)
                    ." ({$renews->format('d/m/Y')}). Renueva para no perder el acceso.",
                days: $days,
                renewsAt: $renews,
                isTrial: false,
            );
        }

        return null;
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
