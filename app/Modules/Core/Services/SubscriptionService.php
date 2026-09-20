<?php

declare(strict_types=1);

namespace App\Modules\Core\Services;

use App\Modules\Core\Enums\SubscriptionStatus;
use App\Modules\Core\Events\SubscriptionCancellationRequested;
use App\Modules\Core\Events\SubscriptionEnded;
use App\Modules\Core\Events\SubscriptionPaymentFailed;
use App\Modules\Core\Events\SubscriptionResumed;
use App\Modules\Core\Models\Company;
use App\Modules\Core\Models\Plan;
use App\Modules\Core\Models\Subscription;
use App\Modules\Core\Models\SystemEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Ciclo de vida de las suscripciones (cobro manual: el operador registra los pagos).
 *
 * Una empresa tiene una sola suscripción, que se muta con el tiempo: alta con prueba o activa,
 * registro de pago (renueva el período), cambio de plan, suspensión, reactivación y baja.
 */
final class SubscriptionService
{
    /**
     * Suscribe (o resuscribe) una empresa a un plan. Con período de prueba si el plan lo ofrece
     * y se solicita; en caso contrario arranca activa con el primer período por pagar.
     */
    public function subscribe(Company $company, Plan $plan, bool $withTrial = true): Subscription
    {
        $now = Carbon::now();
        $trial = $withTrial && $plan->trial_days > 0;

        return Subscription::updateOrCreate(
            ['company_id' => $company->id],
            // Alta desde el panel del operador: nunca marca purge_at (sus pruebas no se auto-borran).
            $trial
                ? [
                    'plan_id' => $plan->id,
                    'status' => SubscriptionStatus::Trialing,
                    'trial_ends_at' => $now->copy()->addDays($plan->trial_days),
                    'current_period_start' => null,
                    'current_period_end' => null,
                    'cancelled_at' => null,
                    'purge_at' => null,
                    'renewal_reminded_at' => null,
                ]
                : [
                    'plan_id' => $plan->id,
                    'status' => SubscriptionStatus::Active,
                    'trial_ends_at' => null,
                    'current_period_start' => $now,
                    'current_period_end' => $plan->billing_cycle->advance($now),
                    'cancelled_at' => null,
                    'purge_at' => null,
                    'renewal_reminded_at' => null,
                ],
        );
    }

    /**
     * Inicia una prueba de registro self-service: la empresa arranca en período de prueba de $days
     * días (duración explícita, no la del plan) y marca `purge_at` = fin de prueba + 24 h. Ese sello
     * es lo que autoriza el borrado automático de los datos si el cliente no contrata un plan; toda
     * acción posterior del operador (pago/cambio de plan) lo limpia.
     *
     * El plan solo define los módulos que se heredan si la empresa no fijó su propia selección; el
     * acceso real durante la prueba lo gobierna `company.modules` (lo que el cliente eligió).
     */
    public function startSelfServiceTrial(Company $company, Plan $plan, int $days): Subscription
    {
        $now = Carbon::now();
        $trialEnds = $now->copy()->addDays($days);

        return Subscription::updateOrCreate(
            ['company_id' => $company->id],
            [
                'plan_id' => $plan->id,
                'status' => SubscriptionStatus::Trialing,
                'trial_ends_at' => $trialEnds,
                'current_period_start' => null,
                'current_period_end' => null,
                'cancelled_at' => null,
                'purge_at' => $trialEnds->copy()->addDay(),
            ],
        );
    }

    /**
     * Registra un pago: activa la suscripción y extiende el período un ciclo. Si aún estaba
     * vigente, el nuevo período se encadena al final del actual (no se pierde tiempo pagado).
     */
    public function registerPayment(Subscription $subscription): Subscription
    {
        $plan = $subscription->plan;
        $now = Carbon::now();

        $base = $subscription->current_period_end !== null && $subscription->current_period_end->isFuture()
            ? $subscription->current_period_end
            : $now;

        $subscription->update([
            'status' => SubscriptionStatus::Active,
            'trial_ends_at' => null,
            'current_period_start' => $subscription->current_period_start ?? $now,
            'current_period_end' => $plan?->billing_cycle->advance($base) ?? $base,
            'cancelled_at' => null,
            'purge_at' => null, // ya contrató/pagó: nunca auto-borrar sus datos
            'renewal_reminded_at' => null, // período nuevo: el aviso de vencimiento vuelve a habilitarse
        ]);

        return $subscription->refresh();
    }

    /**
     * Cambia de plan conservando el período vigente. Si la suscripción no estaba al día, arranca
     * un período nuevo desde hoy.
     */
    public function changePlan(Subscription $subscription, Plan $plan): Subscription
    {
        $data = ['plan_id' => $plan->id];

        if (! $subscription->isUsable()) {
            $now = Carbon::now();
            $data += [
                'status' => SubscriptionStatus::Active,
                'trial_ends_at' => null,
                'current_period_start' => $now,
                'current_period_end' => $plan->billing_cycle->advance($now),
                'cancelled_at' => null,
                'purge_at' => null,
                'renewal_reminded_at' => null,
            ];
        }

        $subscription->update($data);

        return $subscription->refresh();
    }

    public function suspend(Subscription $subscription): Subscription
    {
        $subscription->update(['status' => SubscriptionStatus::Suspended]);

        return $subscription;
    }

    public function cancel(Subscription $subscription): Subscription
    {
        $subscription->update([
            'status' => SubscriptionStatus::Cancelled,
            'cancelled_at' => Carbon::now(),
        ]);

        return $subscription;
    }

    /**
     * Anota que el cliente pidió la baja: deja de renovarse, pero conserva el acceso hasta el fin del
     * período que ya pagó. El corte de verdad llega después, con `subscription.revoked` (`cancel()`).
     *
     * Devuelve `true` solo si ESTA llamada fue la que la marcó. Es lo que evita el correo doble: la
     * baja llega por dos puertas —el botón del panel y el aviso de Polar, que también salta si el
     * cliente cancela desde el portal de Polar— y las dos pasan por aquí. Quien marca primero avisa al
     * cliente; el otro se encuentra la baja ya marcada y no hace nada.
     *
     * La comprobación va dentro de una transacción con la fila bloqueada: sin ello las dos puertas
     * leerían «aún no está marcada» a la vez y el cliente recibiría dos correos.
     */
    public function scheduleCancellation(Subscription $subscription, ?int $userId = null): bool
    {
        $marked = DB::transaction(function () use ($subscription): bool {
            $current = Subscription::query()->lockForUpdate()->find($subscription->getKey());

            if ($current === null || $current->cancelled_at !== null) {
                return false;
            }

            $current->update(['cancelled_at' => Carbon::now()]);

            return true;
        });

        $subscription->refresh();

        if (! $marked) {
            return false;
        }

        SystemEvent::registrar(
            type: 'subscription.cancel_requested',
            message: 'La empresa pidió la baja de su suscripción',
            contexto: [
                'plan' => $subscription->plan?->slug,
                'acceso_hasta' => $subscription->renewsAt()?->toDateString(),
                'origen' => $userId === null ? 'polar' : 'panel',
            ],
            companyId: (int) $subscription->company_id,
            userId: $userId,
        );

        SubscriptionCancellationRequested::dispatch($subscription, $userId);

        return true;
    }

    /**
     * Deshace la baja pedida: la suscripción vuelve a renovarse sola. Pareja de `scheduleCancellation`,
     * con la misma garantía: devuelve `true` solo si ESTA llamada fue la que la deshizo.
     */
    public function unscheduleCancellation(Subscription $subscription, ?int $userId = null): bool
    {
        $undone = DB::transaction(function () use ($subscription): bool {
            $current = Subscription::query()->lockForUpdate()->find($subscription->getKey());

            if ($current === null || $current->cancelled_at === null) {
                return false;
            }

            $current->update(['cancelled_at' => null]);

            return true;
        });

        $subscription->refresh();

        if (! $undone) {
            return false;
        }

        SystemEvent::registrar(
            type: 'subscription.resumed',
            message: 'La empresa reactivó la renovación de su suscripción',
            contexto: [
                'plan' => $subscription->plan?->slug,
                'origen' => $userId === null ? 'polar' : 'panel',
            ],
            companyId: (int) $subscription->company_id,
            userId: $userId,
        );

        SubscriptionResumed::dispatch($subscription, $userId);

        return true;
    }

    /**
     * La suscripción TERMINÓ (Polar la revocó): se retira el acceso y se avisa al cliente.
     *
     * Devuelve `true` solo si ESTA llamada fue la que la terminó. Un segundo aviso de revocación —Polar
     * reintenta, o llegan dos por caminos distintos— se encuentra la suscripción ya terminada y no repite
     * ni el rastro, ni el evento, ni el correo.
     *
     * Antes de retirar el acceso se mira si el cliente había pedido la baja (`cancelled_at`): `cancel()`
     * lo pisa con la fecha de hoy, y después ya no se puede saber si esto lo pidió él o se terminó por
     * otra causa (un cobro que no se pudo hacer, el operador…).
     *
     * Como en `scheduleCancellation`, la comprobación va en una transacción con la fila bloqueada: dos
     * avisos de revocación a la vez leerían «aún no terminó» los dos y mandarían dos correos.
     */
    public function end(Subscription $subscription): bool
    {
        $requestedByCustomer = false;

        $ended = DB::transaction(function () use ($subscription, &$requestedByCustomer): bool {
            $current = Subscription::query()->lockForUpdate()->find($subscription->getKey());

            if ($current === null || $current->status === SubscriptionStatus::Cancelled) {
                return false;
            }

            $requestedByCustomer = $current->cancelled_at !== null;

            $this->cancel($current);

            return true;
        });

        $subscription->refresh();

        if (! $ended) {
            return false;
        }

        SystemEvent::registrar(
            type: 'subscription.ended',
            message: 'La suscripción terminó y se retiró el acceso',
            contexto: [
                'plan' => $subscription->plan?->slug,
                'pidio_la_baja' => $requestedByCustomer,
            ],
            companyId: (int) $subscription->company_id,
        );

        SubscriptionEnded::dispatch($subscription, $requestedByCustomer);

        return true;
    }

    /**
     * Falló el cobro de la renovación (Polar la marcó `past_due`): se avisa al cliente para que actualice
     * su tarjeta. Devuelve `true` solo si se avisó.
     *
     * NO toca el estado ni el período: dar o quitar acceso por un cobro fallido es una decisión de
     * negocio aparte, y hacerlo aquí a ciegas dejaría fuera a un cliente cuya tarjeta se arregla en una
     * hora, o regalaría acceso a quien no piensa pagar. Solo avisa.
     *
     * Un correo al día como mucho por suscripción: Polar reintenta el cobro varias veces y puede volver a
     * avisar en cada intento, y tres correos iguales seguidos parecen un fallo nuestro.
     */
    public function notifyPaymentFailure(Subscription $subscription): bool
    {
        if (! Cache::add("subscription:{$subscription->getKey()}:payment-failed", true, now()->addDay())) {
            return false;
        }

        SystemEvent::registrar(
            type: 'subscription.payment_failed',
            message: 'Falló el cobro de la renovación: el cliente puede perder el acceso',
            contexto: ['plan' => $subscription->plan?->slug],
            // Un cobro que falla es un cliente a punto de irse: el operador tiene que verlo.
            level: SystemEvent::AVISO,
            companyId: (int) $subscription->company_id,
        );

        SubscriptionPaymentFailed::dispatch($subscription);

        return true;
    }
}
