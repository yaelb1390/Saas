<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Core\Enums\SubscriptionStatus;
use App\Modules\Core\Mail\SubscriptionCardExpiringMail;
use App\Modules\Core\Mail\SubscriptionEndingSoonMail;
use App\Modules\Core\Mail\SubscriptionExpiringMail;
use App\Modules\Core\Mail\SubscriptionRenewalNoticeMail;
use App\Modules\Core\Models\Company;
use App\Modules\Core\Models\Plan;
use App\Modules\Core\Models\Subscription;
use App\Modules\Core\Services\PolarSubscriptionService;
use App\Modules\Core\Support\CardExpiry;
use App\Modules\Core\Support\SubscriptionNotice;
use Illuminate\Console\Command;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Avisa por correo a las suscripciones de PAGO que están por vencer, usando el MISMO umbral que el
 * banner en pantalla (SubscriptionNotice: 5/10/30 días según el ciclo). Envía una sola vez por período:
 * marca `renewal_reminded_at` y este se limpia al renovar/pagar. Las pruebas se excluyen.
 *
 * Según la suscripción, el aviso es uno de cuatro:
 *  - la que Polar cobra sola: «se renovará el… y se cobrará tanto» —o, si su tarjeta va a fallar, el aviso
 *    de tarjeta por vencer, que ocupa su lugar—;
 *  - la que ya pidió la baja: «tu acceso termina el… y aún puedes reactivarla»;
 *  - la demás (asignada a mano): «vence, contacta para renovar».
 *
 * Lo dispara el scheduler (o el endpoint /tareas/avisar-vencimientos en Vercel); también a mano:
 * `php artisan subscriptions:remind-expiring`.
 */
final class SendExpiringSubscriptionReminders extends Command
{
    protected $signature = 'subscriptions:remind-expiring
                            {--simular : Dice a quién avisaría, sin mandar ningún correo}';

    protected $description = 'Avisa por correo a las suscripciones de pago que están por vencer (una vez por período).';

    public function handle(PolarSubscriptionService $polar): int
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
             * cobrará tanto». La demás (asignada a mano, o con la baja ya pedida) sí termina, y sigue con
             * un aviso de fin. Las pruebas quedan fuera de las dos.
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
             * El simulacro no manda NI marca, y tampoco consulta a Polar.
             *
             * No marcar es lo importante: `renewal_reminded_at` es lo que impide avisar dos veces en
             * el mismo período, así que ponerlo tras un simulacro dejaría a ese cliente sin recibir
             * el aviso NUNCA, que es exactamente lo contrario de para qué existe la tarea.
             *
             * Y no consultar a Polar porque «simular» no debe tocar nada fuera: una consulta fallida deja
             * rastro en el registro del sistema, y un simulacro con un token de otro entorno lo llenaría.
             */
            if ($simular) {
                $sent++;
                $this->line("Se avisaría a «{$company->name}» ({$to}), le quedan {$days} días"
                    .($renewalDays !== null ? ' (se renueva sola).' : '.'));

                continue;
            }

            /*
             * La tarjeta se mira SOLO aquí, cuando toca avisar de la renovación: una petición a Polar por
             * suscripción y período. Un barrido diario de todas sería una petición por cliente y por día, en
             * una función de Vercel con el tiempo limitado. Y es el momento útil: si la tarjeta no va a servir
             * el día del cobro, este es el correo que tiene que decirlo.
             *
             * Si Polar no contesta, `cardOnFile` devuelve null y sale el aviso normal: el de la tarjeta es un
             * extra y no puede retrasar ni impedir el de siempre.
             */
            $card = $renewalDays !== null ? $polar->cardOnFile($subscription) : null;

            [$mail, $kind] = $this->mailFor(
                $subscription, $company, $plan,
                ownerName: (string) ($owner !== null ? $owner->name : $company->name),
                days: $days,
                autoRenews: $renewalDays !== null,
                card: $card,
            );

            // Solo se marca como avisada si el correo SALIÓ. Antes se marcaba justo después de
            // llamar a send(), que con la cola solo encolaba y nunca fallaba; ahora que el envío es
            // real, marcar a ciegas dejaría la suscripción como «ya avisada» tras un fallo y ese
            // cliente no recibiría el aviso NUNCA, que es justo lo contrario de lo que hace falta.
            //
            // Y el fallo de un destinatario no puede abortar el recorrido: los siguientes se
            // quedarían sin avisar por culpa de una dirección mal escrita.
            try {
                Mail::to($to)->send($mail);
            } catch (Throwable $e) {
                report($e);
                $this->error("No se pudo avisar a «{$company->name}» ({$to}): {$e->getMessage()}");

                continue; // sin marcar: se reintentará en la próxima corrida
            }

            $subscription->update(['renewal_reminded_at' => now()]);
            $sent++;

            $this->line("{$kind} enviado a «{$company->name}» ({$to}).");
        }

        $this->info($simular
            ? "Se avisaría a {$sent} suscripciones."
            : "Avisos de vencimiento enviados: {$sent}.");

        return self::SUCCESS;
    }

    /**
     * Elige y arma el correo que le toca a esta suscripción.
     *
     * @return array{0: Mailable, 1: string} El correo y cómo llamarlo en el resumen de la corrida.
     */
    private function mailFor(
        Subscription $subscription,
        Company $company,
        Plan $plan,
        string $ownerName,
        int $days,
        bool $autoRenews,
        ?CardExpiry $card,
    ): array {
        $renewsAt = $subscription->current_period_end;
        $supportWhatsapp = (string) config('platform.support_whatsapp');
        $supportEmail = (string) config('platform.support_email');

        if ($autoRenews) {
            // La tarjeta que no servirá el día del cobro, o que caduca justo después, sustituye al aviso
            // normal: es el mismo momento y dos correos seguidos sobre lo mismo confunden.
            if ($card !== null && $card->atRisk($renewsAt)) {
                return [new SubscriptionCardExpiringMail(
                    ownerName: $ownerName,
                    companyName: (string) $company->name,
                    planName: (string) $plan->name,
                    planPrice: (string) $plan->price,
                    billingCycleLabel: $plan->billing_cycle->label(),
                    cardBrand: $card->brandLabel(),
                    cardLast4: $card->last4,
                    cardExpiry: $card->label(),
                    renewsAt: $renewsAt,
                    failsAtRenewal: $card->failsAt($renewsAt),
                    accountUrl: route('panel.account'),
                    updateCardUrl: route('panel.account.portal'),
                    supportWhatsapp: $supportWhatsapp,
                    supportEmail: $supportEmail,
                ), 'Aviso de tarjeta por vencer'];
            }

            return [new SubscriptionRenewalNoticeMail(
                ownerName: $ownerName,
                companyName: (string) $company->name,
                planName: (string) $plan->name,
                planPrice: (string) $plan->price,
                billingCycleLabel: $plan->billing_cycle->label(),
                renewsAt: $renewsAt,
                daysLeft: max(0, $days),
                accountUrl: route('panel.account'),
                updateCardUrl: route('panel.account.portal'),
                supportWhatsapp: $supportWhatsapp,
                supportEmail: $supportEmail,
            ), 'Aviso de renovación'];
        }

        // Quien ya pidió la baja no tiene nada que renovar ni a quién escribir: lo que puede hacer es
        // arrepentirse con un clic desde su panel.
        if ($subscription->endsAtPeriodEnd()) {
            return [new SubscriptionEndingSoonMail(
                ownerName: $ownerName,
                companyName: (string) $company->name,
                planName: (string) $plan->name,
                accessUntil: $renewsAt,
                daysLeft: max(0, $days),
                accountUrl: route('panel.account'),
                supportWhatsapp: $supportWhatsapp,
                supportEmail: $supportEmail,
            ), 'Aviso de fin de acceso'];
        }

        return [new SubscriptionExpiringMail(
            ownerName: $ownerName,
            companyName: (string) $company->name,
            planName: (string) $plan->name,
            renewsAt: $renewsAt,
            daysLeft: max(0, $days),
            loginUrl: route('login'),
            supportWhatsapp: $supportWhatsapp,
            supportEmail: $supportEmail,
        ), 'Aviso de vencimiento'];
    }
}
