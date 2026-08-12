<?php

declare(strict_types=1);

namespace App\Modules\Core\Http\Controllers;

use App\Modules\Core\Models\Plan;
use App\Modules\Core\Tenancy\CurrentCompany;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Pantalla de planes. Pública: se puede consultar sin cuenta.
 *
 * Es UNA sola pantalla para los dos públicos —quien todavía no es cliente y quien ya lo es y quiere
 * cambiar de plan— en vez de dos que se parezcan. Lo único que cambia es el botón de cada tarjeta,
 * y esa decisión se toma aquí y no en la vista, para que la plantilla se limite a pintar.
 */
final class PublicPlanController extends Controller
{
    public function __invoke(Request $request, CurrentCompany $currentCompany): View
    {
        $usuario = $request->user();
        $company = $usuario !== null ? $currentCompany->model() : null;
        $subscription = $company?->subscription;

        return view('plans.index', [
            'plans' => Plan::query()->where('is_active', true)->orderBy('price')->get(),

            'planActual' => $subscription?->plan_id,

            // Solo el dueño contrata. Un cajero que llegue aquí ve los planes y ningún botón: la
            // pantalla informa a todo el mundo, pero no todo el mundo compromete a la empresa.
            'puedeContratar' => $usuario?->can('company.manage') ?? false,

            // Durante la prueba el cambio es inmediato y gratis; pagando, hay que pasar por caja.
            // La vista necesita saberlo para no prometer «cambio inmediato» a quien va a pagar.
            'enPrueba' => (bool) $subscription?->isTrialing() && (bool) $subscription?->isUsable(),

            'trialDays' => (int) config('bmos.trial.days'),
            'planDeEntrada' => (string) config('bmos.registration.default_plan_slug'),
        ]);
    }
}
