<?php

declare(strict_types=1);

namespace App\Modules\Core\Http\Controllers;

use App\Modules\Core\Models\Plan;
use App\Modules\Core\Services\PolarCheckoutService;
use App\Modules\Core\Tenancy\CurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Abre el cobro de la suscripción.
 *
 * Responde de dos formas a la MISMA petición, y las dos importan:
 *
 *   · JSON  → devuelve la dirección para abrir el pago en una ventana sobre el propio panel, sin que
 *     el cliente salga de su sistema. Es el camino normal.
 *   · Redirección → el formulario de siempre, que lleva a la pasarela. Es lo que ocurre si el
 *     JavaScript no cargó, falló o el navegador lo tiene desactivado.
 *
 * El segundo camino NO es un resto del pasado: es una pantalla de pago, y si el cliente no puede
 * pagar porque un script no cargó, el fallo se mide en dinero. Por eso el botón sigue siendo un
 * formulario de verdad y el JavaScript solo lo intercepta.
 *
 * Lo que este controlador NO hace, en ninguno de los dos caminos, es activar la suscripción. Eso lo
 * hace el aviso de pago de Polar cuando el dinero entra. Ni la dirección de retorno ni el evento de
 * «pago correcto» del navegador valen como prueba: las dos se pueden fabricar a mano.
 */
final class SubscriptionCheckoutController extends Controller
{
    public function __invoke(
        Request $request,
        Plan $plan,
        PolarCheckoutService $checkout,
        CurrentCompany $currentCompany,
    ): RedirectResponse|JsonResponse {
        $company = $currentCompany->model();

        abort_if($company === null, 403);

        // Un plan sin enlazar con la pasarela no se puede cobrar en línea. Se corta aquí y no en la
        // vista: ocultar un botón no impide que alguien escriba la dirección.
        if (! $plan->isPurchasable() || ! $checkout->isConfigured()) {
            return $this->fallo($request, 'Ese plan todavía no se puede contratar en línea. Escríbenos y lo activamos.');
        }

        $owner = $company->ownerUser();
        $email = (string) ($owner?->email ?? $company->email);

        if (blank($email)) {
            return $this->fallo($request, 'Añade un correo de contacto a tu empresa antes de pagar.');
        }

        $url = $checkout->createFor(
            company: $company,
            plan: $plan,
            email: $email,
            successUrl: route('panel.account', ['pago' => 'recibido']),
        );

        if ($url === null) {
            return $this->fallo($request, 'No pudimos abrir la pasarela de pago. Inténtalo de nuevo o escríbenos.');
        }

        if ($request->expectsJson()) {
            return response()->json(['url' => $url]);
        }

        return redirect()->away($url);
    }

    /**
     * El mismo motivo, dicho por el canal que corresponda.
     *
     * Sin esta simetría, un plan sin enlazar daría un aviso claro por el formulario y una ventana en
     * blanco por el otro camino: el cliente vería abrirse algo vacío y no sabría si es su conexión,
     * su tarjeta o el sistema.
     */
    private function fallo(Request $request, string $mensaje): RedirectResponse|JsonResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $mensaje], 422);
        }

        return back()->with('panel_error', $mensaje);
    }
}
