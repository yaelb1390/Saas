<?php

declare(strict_types=1);

namespace App\Modules\Core\Http\Controllers;

use App\Modules\Core\Tenancy\CurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * En qué estado está la suscripción de la empresa, para que la pantalla de cuenta pueda enterarse
 * sola de que el pago ya se confirmó.
 *
 * Con el pago fuera del sistema, lo único que se podía hacer al volver era pedirle al cliente que
 * recargara la página. Ahora que paga sin salir, la pantalla puede preguntar cada pocos segundos y
 * refrescarse cuando el aviso de Polar llegue —que es lo que de verdad activa el plan—.
 *
 * ES SOLO LECTURA, y eso no es un detalle: consultarlo mil veces no acerca ni un paso a tener el plan
 * activo. El navegador puede pedir esto cuanto quiera; quien decide sigue siendo el webhook.
 */
final class SubscriptionStatusController extends Controller
{
    public function __invoke(CurrentCompany $currentCompany): JsonResponse
    {
        $company = $currentCompany->model();

        abort_if($company === null, 403);

        $subscription = $company->subscription;

        return response()->json([
            // «Da acceso ahora mismo», que es la pregunta que le importa a quien acaba de pagar.
            'activa' => (bool) $subscription?->isUsable(),
            'prueba' => (bool) $subscription?->isTrialing(),
            'hasta' => $subscription?->renewsAt()?->toDateString(),
            'plan' => $subscription?->plan?->name,
        ]);
    }
}
