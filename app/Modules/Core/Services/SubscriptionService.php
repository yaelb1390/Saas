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
                ]
                : [
                    'plan_id' => $plan->id,
                    'status' => SubscriptionStatus::Active,
                    'trial_ends_at' => null,
                    'current_period_start' => $now,
                    'current_period_end' => $plan->billing_cycle->advance($now),
                    'cancelled_at' => null,
                    'purge_at' => null,
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
