<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Core\Enums\SubscriptionStatus;
use App\Modules\Core\Mail\SubscriptionExpiringMail;
use App\Modules\Core\Models\Subscription;
use App\Modules\Core\Support\SubscriptionNotice;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * Avisa por correo a las suscripciones de PAGO que están por vencer, usando el MISMO umbral que el
 * banner en pantalla (SubscriptionNotice: 5/10/30 días según el ciclo). Envía una sola vez por
 * período: marca `renewal_reminded_at` y este se limpia al renovar/pagar. Las pruebas se excluyen.
 *
 * Lo dispara el scheduler (o el endpoint /tareas/avisar-vencimientos en Vercel); también a mano:
 * `php artisan subscriptions:remind-expiring`.
 */
final class SendExpiringSubscriptionReminders extends Command
{
    protected $signature = 'subscriptions:remind-expiring';

    protected $description = 'Avisa por correo a las suscripciones de pago que están por vencer (una vez por período).';

    public function handle(): int
    {
        // Candidatas: activas, con período con fin y sin aviso enviado este período. Sin scope de empresa.
        $candidates = Subscription::query()
            ->with(['company', 'plan'])
            ->where('status', SubscriptionStatus::Active)
            ->whereNotNull('current_period_end')
            ->whereNull('renewal_reminded_at')
            ->get();

        $sent = 0;

        foreach ($candidates as $subscription) {
            // Misma decisión que el banner: no-nula solo dentro del umbral. Excluye pruebas.
            $notice = SubscriptionNotice::for($subscription);

            if ($notice === null || $notice->isTrial) {
                continue;
            }

            $company = $subscription->company;
            $plan = $subscription->plan;

            if ($company === null || $plan === null) {
                continue;
            }

            $owner = $company->ownerUser();
            $to = $owner !== null ? $owner->email : $company->email;

            if (blank($to)) {
                // Sin destinatario: se marca para no reintentar en cada corrida.
                $subscription->update(['renewal_reminded_at' => now()]);

                continue;
            }

            Mail::to($to)->send(new SubscriptionExpiringMail(
                ownerName: (string) ($owner !== null ? $owner->name : $company->name),
                companyName: (string) $company->name,
                planName: (string) $plan->name,
                renewsAt: $subscription->current_period_end,
                daysLeft: max(0, $notice->days),
                loginUrl: route('login'),
                supportWhatsapp: (string) config('platform.support_whatsapp'),
                supportEmail: (string) config('platform.support_email'),
            ));

            $subscription->update(['renewal_reminded_at' => now()]);
            $sent++;

            $this->line("Aviso de vencimiento enviado a «{$company->name}» ({$to}).");
        }

        $this->info("Avisos de vencimiento enviados: {$sent}.");

        return self::SUCCESS;
    }
}
