<?php

declare(strict_types=1);

namespace App\Modules\Core\Listeners;

use App\Modules\Core\Events\SubscriptionPaymentFailed;
use App\Modules\Core\Mail\SubscriptionPaymentFailedMail;
use Illuminate\Support\Facades\Mail;

/**
 * Le dice al cliente, en español, que no se pudo cobrar su renovación y cómo arreglarlo. Reemplaza el
 * aviso de Polar, que llega en inglés.
 *
 * Mismas reglas que los demás correos de suscripción: NO va en cola (en Vercel no hay worker y nadie
 * lo recogería) y va envuelto en `rescue` (un SMTP caído no puede hacer que Polar reintente el aviso).
 */
final class SendSubscriptionPaymentFailedEmail
{
    public function handle(SubscriptionPaymentFailed $event): void
    {
        $subscription = $event->subscription;
        $company = $subscription->company;
        $plan = $subscription->plan;

        if ($company === null || $plan === null) {
            return;
        }

        $owner = $company->ownerUser();
        $to = $owner?->email ?? $company->email;

        if (blank($to)) {
            return;
        }

        rescue(fn () => Mail::to($to)->send(new SubscriptionPaymentFailedMail(
            ownerName: (string) ($owner?->name ?? $company->name),
            companyName: (string) $company->name,
            planName: (string) $plan->name,
            planPrice: (string) $plan->price,
            billingCycleLabel: $plan->billing_cycle->label(),
            // Una ruta nuestra, no el portal de Polar: el enlace de una sesión del portal caduca en una
            // hora y este correo se abre días después. La ruta crea la sesión en el momento del clic.
            updateCardUrl: route('panel.account.portal'),
            supportWhatsapp: (string) config('platform.support_whatsapp'),
            supportEmail: (string) config('platform.support_email'),
        )), report: true);
    }
}
