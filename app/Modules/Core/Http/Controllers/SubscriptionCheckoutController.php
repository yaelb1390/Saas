<?php

declare(strict_types=1);

namespace App\Modules\Core\Http\Controllers;

use App\Modules\Core\Models\Plan;
use App\Modules\Core\Services\PolarCheckoutService;
use App\Modules\Core\Tenancy\CurrentCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;

/**
 * Abre el cobro de la suscripción y manda al cliente a la pasarela.
 *
 * Solo abre el cobro. La suscripción la activa el webhook cuando Polar confirma el pago: al volver
 * de la pasarela no se toca nada, porque esa dirección de retorno se puede escribir a mano.
 */
final class SubscriptionCheckoutController extends Controller
{
    public function __invoke(
        Plan $plan,
        PolarCheckoutService $checkout,
        CurrentCompany $currentCompany,
    ): RedirectResponse {
        $company = $currentCompany->model();

        abort_if($company === null, 403);

        // Un plan sin enlazar con la pasarela no se puede cobrar en línea. Se corta aquí y no en la
        // vista: ocultar un botón no impide que alguien escriba la dirección.
        if (! $plan->isPurchasable() || ! $checkout->isConfigured()) {
            return back()->with('panel_error', 'Ese plan todavía no se puede contratar en línea. Escríbenos y lo activamos.');
        }

        $owner = $company->ownerUser();
        $email = (string) ($owner?->email ?? $company->email);

        if (blank($email)) {
            return back()->with('panel_error', 'Añade un correo de contacto a tu empresa antes de pagar.');
        }

        $url = $checkout->createFor(
            company: $company,
            plan: $plan,
            email: $email,
            successUrl: route('panel.account', ['pago' => 'recibido']),
        );

        if ($url === null) {
            return back()->with('panel_error', 'No pudimos abrir la pasarela de pago. Inténtalo de nuevo o escríbenos.');
        }

        return redirect()->away($url);
    }
}
