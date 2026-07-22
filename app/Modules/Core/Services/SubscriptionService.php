<?php

declare(strict_types=1);

namespace App\Modules\Core\Services;

use App\Modules\Core\Enums\SubscriptionStatus;
use App\Modules\Core\Models\Company;
use App\Modules\Core\Models\Plan;
use App\Modules\Core\Models\Subscription;
use Illuminate\Support\Carbon;

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
            $trial
                ? [
                    'plan_id' => $plan->id,
                    'status' => SubscriptionStatus::Trialing,
                    'trial_ends_at' => $now->copy()->addDays($plan->trial_days),
                    'current_period_start' => null,
                    'current_period_end' => null,
                    'cancelled_at' => null,
                ]
                : [
                    'plan_id' => $plan->id,
                    'status' => SubscriptionStatus::Active,
                    'trial_ends_at' => null,
                    'current_period_start' => $now,
                    'current_period_end' => $plan->billing_cycle->advance($now),
                    'cancelled_at' => null,
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
}
