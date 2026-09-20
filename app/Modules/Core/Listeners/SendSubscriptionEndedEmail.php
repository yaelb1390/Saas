<?php

declare(strict_types=1);

namespace App\Modules\Core\Listeners;

use App\Modules\Core\Events\SubscriptionEnded;
use App\Modules\Core\Mail\SubscriptionEndedMail;
use Illuminate\Support\Facades\Mail;

/**
 * Le dice al cliente, en español, que su suscripción terminó y que sus datos siguen guardados. Hasta
 * ahora la aplicación retiraba el acceso sin decir nada, y el único aviso era el de Polar, en inglés.
 *
 * Mismas reglas que los demás correos de suscripción: sin cola y con `rescue`. No exige que la
 * suscripción siga «usable»: justo acaba de dejar de serlo, y de eso trata el correo.
 */
final class SendSubscriptionEndedEmail
{
    public function handle(SubscriptionEnded $event): void
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

        rescue(fn () => Mail::to($to)->send(new SubscriptionEndedMail(
            ownerName: (string) ($owner?->name ?? $company->name),
            companyName: (string) $company->name,
            planName: (string) $plan->name,
            requestedByCustomer: $event->requestedByCustomer,
            resubscribeUrl: route('panel.account'),
            supportWhatsapp: (string) config('platform.support_whatsapp'),
            supportEmail: (string) config('platform.support_email'),
        )), report: true);
    }
}
