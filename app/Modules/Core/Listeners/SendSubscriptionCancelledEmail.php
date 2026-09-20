<?php

declare(strict_types=1);

namespace App\Modules\Core\Listeners;

use App\Modules\Core\Events\SubscriptionCancellationRequested;
use App\Modules\Core\Mail\SubscriptionCancelledMail;
use Illuminate\Support\Facades\Mail;

/**
 * Le dice al cliente, en español y con su marca, que su baja quedó registrada y hasta cuándo conserva
 * el acceso. Reemplaza el aviso genérico de Polar, que llega en inglés.
 *
 * NO es un listener en cola, a propósito: en producción esto corre en Vercel, que no tiene worker de
 * colas. Un correo encolado no lo recogería nadie y el cliente no recibiría nada, sin ningún error.
 *
 * Y va envuelto en `rescue`: si el SMTP está caído, la baja NO puede fallar. Ya está anotada, y
 * cuando llega desde el aviso de Polar, un error aquí haría que Polar lo reintentara. El fallo se
 * reporta para que quede rastro.
 */
final class SendSubscriptionCancelledEmail
{
    public function handle(SubscriptionCancellationRequested $event): void
    {
        $subscription = $event->subscription;
        $company = $subscription->company;
        $plan = $subscription->plan;
        $accessUntil = $subscription->renewsAt();

        // «Sigues con acceso hasta…» necesita una fecha, y solo es verdad si el acceso sigue en pie.
        // Si la revocación llegó antes que la baja, decirlo sería mentirle al cliente.
        if ($company === null || $plan === null || $accessUntil === null || ! $subscription->isUsable()) {
            return;
        }

        $owner = $company->ownerUser();
        $to = $owner?->email ?? $company->email;

        if (blank($to)) {
            return;
        }

        rescue(fn () => Mail::to($to)->send(new SubscriptionCancelledMail(
            ownerName: (string) ($owner?->name ?? $company->name),
            companyName: (string) $company->name,
            planName: (string) $plan->name,
            accessUntil: $accessUntil,
            daysLeft: max(0, (int) now()->startOfDay()->diffInDays($accessUntil->copy()->startOfDay(), false)),
            accountUrl: route('panel.account'),
            supportWhatsapp: (string) config('platform.support_whatsapp'),
            supportEmail: (string) config('platform.support_email'),
        )), report: true);
    }
}
