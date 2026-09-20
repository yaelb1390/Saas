<?php

declare(strict_types=1);

namespace App\Modules\Core\Http\Controllers;

use App\Modules\Core\Services\PolarSubscriptionService;
use App\Modules\Core\Tenancy\CurrentCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Lleva al cliente al portal de pagos de Polar, donde cambia su tarjeta y ve sus facturas.
 *
 * Existe porque el enlace de una sesión del portal caduca en una hora: no se puede poner en un correo
 * («No pudimos cobrar tu suscripción → Actualizar mi tarjeta»), que se abre días después. El correo apunta
 * aquí, y aquí se pide la sesión en el momento del clic.
 *
 * Como en cancelar y reactivar, la suscripción sale de la empresa activa y nunca de la petición: no hay
 * identificador que manipular para abrir el portal de otra empresa.
 */
final class SubscriptionPortalController extends Controller
{
    public function __invoke(
        Request $request,
        PolarSubscriptionService $polar,
        CurrentCompany $currentCompany,
    ): RedirectResponse {
        $company = $currentCompany->model();

        abort_if($company === null, 403);

        $subscription = $company->subscription;

        // Sin cliente en Polar (una suscripción asignada a mano, o una prueba) no hay portal que abrir.
        if ($subscription === null || blank($subscription->polar_customer_id)) {
            return redirect()->route('panel.account')
                ->with('panel_error', 'Tu suscripción no se paga con tarjeta desde aquí. Escríbenos y lo gestionamos.');
        }

        $url = $polar->customerPortalUrl($subscription, route('panel.account'));

        if ($url === null) {
            return redirect()->route('panel.account')
                ->with('panel_error', 'No pudimos abrir el portal de pagos ahora mismo. Inténtalo de nuevo en unos minutos o escríbenos.');
        }

        return redirect()->away($url);
    }
}
