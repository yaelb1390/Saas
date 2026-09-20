<?php

declare(strict_types=1);

namespace App\Modules\Core\Listeners;

use App\Modules\Core\Events\SubscriptionResumed;
use App\Modules\Core\Mail\SubscriptionResumedMail;
use Illuminate\Support\Facades\Mail;

/**
 * Confirma al cliente que se arrepintió de su baja: la suscripción sigue activa y cuándo se renueva.
 * Reemplaza el aviso genérico de Polar («Your subscription is no longer canceled»).
 *
 * Mismas reglas que `SendSubscriptionCancelledEmail`: sin cola y envuelto en `rescue`.
 */
final class SendSubscriptionResumedEmail
{
    public function handle(SubscriptionResumed $event): void
    {
        $subscription = $event->subscription;
        $company = $subscription->company;
        $plan = $subscription->plan;
        $renewsAt = $subscription->renewsAt();

        if ($company === null || $plan === null || $renewsAt === null || ! $subscription->isUsable()) {
            return;
        }

        $owner = $company->ownerUser();
        $to = $owner?->email ?? $company->email;

        if (blank($to)) {
            return;
        }

        rescue(fn () => Mail::to($to)->send(new SubscriptionResumedMail(
            ownerName: (string) ($owner?->name ?? $company->name),
            companyName: (string) $company->name,
            planName: (string) $plan->name,
            planPrice: (string) $plan->price,
            billingCycleLabel: $plan->billing_cycle->label(),
            renewsAt: $renewsAt,
            accountUrl: route('panel.account'),
            supportWhatsapp: (string) config('platform.support_whatsapp'),
            supportEmail: (string) config('platform.support_email'),
        )), report: true);
    }
}
