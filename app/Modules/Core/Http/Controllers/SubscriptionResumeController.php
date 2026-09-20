<?php

declare(strict_types=1);

namespace App\Modules\Core\Http\Controllers;

use App\Modules\Core\Services\PolarSubscriptionService;
use App\Modules\Core\Tenancy\CurrentCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * El cliente se arrepiente de su baja: la suscripción vuelve a renovarse sola.
 *
 * Solo sirve mientras el período pagado siga vigente. Pasada esa fecha la suscripción ya está
 * revocada y hay que contratar de nuevo, que es otra pantalla.
 *
 * Igual que al cancelar, la suscripción sale de la empresa activa y no de la petición.
 */
final class SubscriptionResumeController extends Controller
{
    public function __invoke(
        Request $request,
        PolarSubscriptionService $polar,
        CurrentCompany $currentCompany,
    ): RedirectResponse {
        $company = $currentCompany->model();

        abort_if($company === null, 403);

        $subscription = $company->subscription;

        if ($subscription === null || ! ($subscription->renewsAutomatically() || $subscription->endsAtPeriodEnd())) {
            return back()->with('panel_error', 'Tu suscripción no se puede reactivar desde aquí. Escríbenos y lo gestionamos.');
        }

        if (! $polar->resume($subscription, $request->user()?->id)) {
            return back()->with('panel_error', 'No pudimos reactivar tu suscripción ahora mismo. Inténtalo de nuevo en unos minutos o escríbenos.');
        }

        return redirect()->route('panel.account')->with(
            'panel_ok',
            'Tu suscripción sigue activa y se renovará el '.$subscription->renewsAt()?->format('d/m/Y').'.',
        );
    }
}
