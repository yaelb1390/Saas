<?php

declare(strict_types=1);

namespace App\Modules\Core\Http\Controllers;

use App\Modules\Core\Models\Plan;
use App\Modules\Core\Services\SubscriptionService;
use App\Modules\Core\Tenancy\CurrentCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;

/**
 * Cambio de plan DURANTE LA PRUEBA, sin coste y sin pasar por la pasarela.
 *
 * Es lo que hace útil entrar por el plan de entrada: el cliente descubre que necesita CRM, se pasa a
 * Pro y lo prueba con el mismo reloj. No se toca `trial_ends_at`: cambiar de plan no regala días.
 *
 * Fuera de la prueba NO se cambia gratis, y no es un detalle de interfaz. `changePlan()` sobre una
 * suscripción que no está al día arranca un período completo desde hoy sin comprobar ningún pago
 * (ver SubscriptionService::changePlan). Exponer eso al cliente sería regalarle un ciclo entero cada
 * vez que cambiara de plan. Por eso quien no está en prueba acaba en la pasarela.
 */
final class SubscriptionPlanController extends Controller
{
    public function __invoke(
        Plan $plan,
        SubscriptionService $subscriptions,
        CurrentCompany $currentCompany,
    ): RedirectResponse {
        $company = $currentCompany->model();

        abort_if($company === null, 403);

        // Un plan retirado de la venta no se contrata aunque se teclee su dirección.
        if (! $plan->is_active) {
            return back()->with('panel_error', 'Ese plan no está disponible.');
        }

        $subscription = $company->subscription;

        if ($subscription === null) {
            return back()->with('panel_error', 'Tu empresa aún no tiene una suscripción. Escríbenos y te ayudamos.');
        }

        if ($subscription->plan_id === $plan->id) {
            return redirect()->route('panel.account')->with('panel_ok', "Ya estás en el plan «{$plan->name}».");
        }

        // La puerta: solo con la prueba VIGENTE. Ver el porqué en la cabecera de la clase.
        if (! $subscription->isTrialing() || ! $subscription->isUsable()) {
            return redirect()->route('panel.account')->with('panel_error',
                'Tu prueba ya terminó. Para cambiar de plan, realiza el pago del plan que quieras.');
        }

        $subscriptions->changePlan($subscription, $plan);

        return redirect()->route('panel.account')->with('panel_ok',
            "Ahora estás probando el plan «{$plan->name}». Tu prueba mantiene la misma fecha de fin.");
    }
}
