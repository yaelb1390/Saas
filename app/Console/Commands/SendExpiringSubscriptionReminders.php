<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Core\Enums\SubscriptionStatus;
use App\Modules\Core\Mail\SubscriptionExpiringMail;
use App\Modules\Core\Mail\SubscriptionRenewalNoticeMail;
use App\Modules\Core\Models\Subscription;
use App\Modules\Core\Support\SubscriptionNotice;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Avisa por correo a las suscripciones de PAGO que están por vencer, usando el MISMO umbral que el
 * banner en pantalla (SubscriptionNotice: 5/10/30 días según el ciclo). A la que Polar cobra sola le
 * avisa de que SE RENOVARÁ (y cuánto se cobra); a la demás, de que VENCE. Envía una sola vez por
 * período: marca `renewal_reminded_at` y este se limpia al renovar/pagar. Las pruebas se excluyen.
 *
 * Lo dispara el scheduler (o el endpoint /tareas/avisar-vencimientos en Vercel); también a mano:
 * `php artisan subscriptions:remind-expiring`.
 */
final class SendExpiringSubscriptionReminders extends Command
{
    protected $signature = 'subscriptions:remind-expiring
                            {--simular : Dice a quién avisaría, sin mandar ningún correo}';

    protected $description = 'Avisa por correo a las suscripciones de pago que están por vencer (una vez por período).';

    public function handle(): int
    {
        $simular = (bool) $this->option('simular');

        // Candidatas: activas, con período con fin y sin aviso enviado este período. Sin scope de empresa.
        $candidates = Subscription::query()
            ->with(['company', 'plan'])
            ->where('status', SubscriptionStatus::Active)
            ->whereNotNull('current_period_end')
            ->whereNull('renewal_reminded_at')
            ->get();

        $sent = 0;

        foreach ($candidates as $subscription) {
            /*
             * Dos clases de suscripción de pago, dos avisos distintos, ambos con el mismo umbral del ciclo.
             *
             * La que Polar cobra sola no «vence»: se renueva. Decirle «Renueva a tiempo» la llevaba a pagar
             * de nuevo algo que ya se paga solo, o a escribir preocupada. Su aviso es «se renovará el… y se
             * cobrará tanto». La demás (asignada a mano, o con la baja ya pedida) sí vence, y sigue con el
             * aviso de siempre. Las pruebas quedan fuera de las dos.
             */
            $renewalDays = SubscriptionNotice::renewalNoticeDays($subscription);
            $notice = $renewalDays === null ? SubscriptionNotice::for($subscription) : null;

            if ($renewalDays === null && ($notice === null || $notice->isTrial)) {
                continue;
            }

            $days = $renewalDays ?? (int) $notice?->days;

            $company = $subscription->company;
            $plan = $subscription->plan;

            if ($company === null || $plan === null) {
                continue;
            }

            $owner = $company->ownerUser();
            $to = $owner !== null ? $owner->email : $company->email;

            if (blank($to)) {
                // Sin destinatario: se marca para no reintentar en cada corrida.
                if (! $simular) {
                    $subscription->update(['renewal_reminded_at' => now()]);
                }

                continue;
            }

            /*
             * El simulacro no manda NI marca.
             *
             * No marcar es lo importante: `renewal_reminded_at` es lo que impide avisar dos veces en
             * el mismo período, así que ponerlo tras un simulacro dejaría a ese cliente sin recibir
             * el aviso NUNCA, que es exactamente lo contrario de para qué existe la tarea.
             */
            if ($simular) {
                $sent++;
                $this->line("Se avisaría a «{$company->name}» ({$to}), le quedan {$days} días"
                    .($renewalDays !== null ? ' (se renueva sola).' : '.'));

                continue;
            }

            // Solo se marca como avisada si el correo SALIÓ. Antes se marcaba justo después de
            // llamar a send(), que con la cola solo encolaba y nunca fallaba; ahora que el envío es
            // real, marcar a ciegas dejaría la suscripción como «ya avisada» tras un fallo y ese
            // cliente no recibiría el aviso NUNCA, que es justo lo contrario de lo que hace falta.
            //
            // Y el fallo de un destinatario no puede abortar el recorrido: los siguientes se
            // quedarían sin avisar por culpa de una dirección mal escrita.
            $ownerName = (string) ($owner !== null ? $owner->name : $company->name);
            $supportWhatsapp = (string) config('platform.support_whatsapp');
            $supportEmail = (string) config('platform.support_email');

            try {
                Mail::to($to)->send($renewalDays !== null
                    ? new SubscriptionRenewalNoticeMail(
                        ownerName: $ownerName,
                        companyName: (string) $company->name,
                        planName: (string) $plan->name,
                        planPrice: (string) $plan->price,
                        billingCycleLabel: $plan->billing_cycle->label(),
                        renewsAt: $subscription->current_period_end,
                        daysLeft: max(0, $days),
                        accountUrl: route('panel.account'),
                        updateCardUrl: route('panel.account.portal'),
                        supportWhatsapp: $supportWhatsapp,
                        supportEmail: $supportEmail,
                    )
                    : new SubscriptionExpiringMail(
                        ownerName: $ownerName,
                        companyName: (string) $company->name,
                        planName: (string) $plan->name,
                        renewsAt: $subscription->current_period_end,
                        daysLeft: max(0, $days),
                        loginUrl: route('login'),
                        supportWhatsapp: $supportWhatsapp,
                        supportEmail: $supportEmail,
                    ));
            } catch (Throwable $e) {
                report($e);
                $this->error("No se pudo avisar a «{$company->name}» ({$to}): {$e->getMessage()}");

                continue; // sin marcar: se reintentará en la próxima corrida
            }

            $subscription->update(['renewal_reminded_at' => now()]);
            $sent++;

            $this->line(($renewalDays !== null ? 'Aviso de renovación' : 'Aviso de vencimiento')
                ." enviado a «{$company->name}» ({$to}).");
        }

        $this->info($simular
            ? "Se avisaría a {$sent} suscripciones."
            : "Avisos de vencimiento enviados: {$sent}.");

        return self::SUCCESS;
    }
}
