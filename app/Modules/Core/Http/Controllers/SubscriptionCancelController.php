<?php

declare(strict_types=1);

namespace App\Modules\Core\Http\Controllers;

use App\Modules\Core\Services\PolarSubscriptionService;
use App\Modules\Core\Tenancy\CurrentCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * El cliente cancela su suscripción: deja de renovarse, pero conserva el acceso hasta el fin del
 * período que ya pagó.
 *
 * La suscripción sale SIEMPRE de la empresa activa y nunca de la petición: no hay identificador que
 * manipular, así que nadie puede cancelar la de otra empresa.
 */
final class SubscriptionCancelController extends Controller
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
            return back()->with('panel_error', 'Tu suscripción no se puede cancelar desde aquí. Escríbenos y lo gestionamos.');
        }

        if (! $polar->cancelAtPeriodEnd($subscription, $request->user()?->id)) {
            return back()->with('panel_error', 'No pudimos cancelar tu suscripción ahora mismo. Inténtalo de nuevo en unos minutos o escríbenos.');
        }

        return redirect()->route('panel.account')->with(
            'panel_ok',
            'Cancelaste tu suscripción. Sigues con acceso completo hasta el '
                .$subscription->renewsAt()?->format('d/m/Y').' y después no se cobrará más.',
        );
    }
}
