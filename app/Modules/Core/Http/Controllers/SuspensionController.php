<?php

declare(strict_types=1);

namespace App\Modules\Core\Http\Controllers;

use App\Modules\Core\Enums\SubscriptionStatus;
use App\Modules\Core\Services\PolarCheckoutService;
use App\Modules\Core\Tenancy\CurrentCompany;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Pantalla de cuenta suspendida. Se muestra cuando la empresa no puede operar (suspendida o con
 * la suscripción vencida).
 *
 * Distingue POR QUÉ, porque lo que puede hacer el cliente depende de ello:
 *   · `pago`: el plan venció o se canceló (falta de pago). Si quien mira es el propietario y el plan
 *     se puede cobrar en línea, se le ofrece pagar ahí mismo: el webhook de Polar deja la suscripción
 *     activa sola y esta pantalla lo manda de vuelta al panel.
 *   · `operador`: el operador suspendió la suscripción a mano. Pagar la reactivaría y se saltaría esa
 *     decisión, así que solo se ofrece contacto.
 *   · `empresa`: el operador desactivó la empresa. Pagar no la reactiva: solo contacto.
 */
final class SuspensionController extends Controller
{
    public function __invoke(Request $request, CurrentCompany $currentCompany, PolarCheckoutService $checkout): View|RedirectResponse
    {
        $company = $currentCompany->model();

        $subscription = $company?->subscription;
        $blocked = $company !== null
            && (! $company->is_active || ($subscription !== null && ! $subscription->isUsable()));

        // Si la cuenta está al día, no tiene sentido ver este aviso: al panel.
        if (! $blocked) {
            return redirect()->route('dashboard');
        }

        $motivo = match (true) {
            ! $company->is_active => 'empresa',
            $subscription?->status === SubscriptionStatus::Suspended => 'operador',
            default => 'pago',
        };

        $plan = $subscription?->plan;
        $sePuedeCobrar = $motivo === 'pago' && $plan !== null && $plan->isPurchasable() && $checkout->isConfigured();
        $esPropietario = $request->user()?->can('company.manage') ?? false;

        return view('suspended', [
            'company' => $company,
            'motivo' => $motivo,
            'plan' => $plan,
            // Solo el propietario puede pagar (la ruta de cobro exige company.manage).
            'puedePagar' => $sePuedeCobrar && $esPropietario,
            'pagoPendienteDeOtro' => $sePuedeCobrar && ! $esPropietario,
            'whatsapp' => (string) config('platform.support_whatsapp'),
            'email' => (string) config('platform.support_email'),
            'platformName' => (string) config('platform.name'),
        ]);
    }
}
