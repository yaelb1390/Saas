<?php

declare(strict_types=1);

namespace App\Modules\Core\Http\Controllers;

use App\Modules\Core\Tenancy\CurrentCompany;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;

/**
 * Pantalla de cuenta suspendida. Se muestra cuando la empresa no puede operar (suspendida o con
 * la suscripción vencida) e informa cómo contactar al operador para regularizar el pago.
 */
final class SuspensionController extends Controller
{
    public function __invoke(CurrentCompany $currentCompany): View|RedirectResponse
    {
        $company = $currentCompany->model();

        $subscription = $company?->subscription;
        $blocked = $company !== null
            && (! $company->is_active || ($subscription !== null && ! $subscription->isUsable()));

        // Si la cuenta está al día, no tiene sentido ver este aviso: al panel.
        if (! $blocked) {
            return redirect()->route('dashboard');
        }

        // Motivo legible según la causa del bloqueo.
        $reason = match (true) {
            $subscription !== null && ! $subscription->isUsable() => 'Tu suscripción no está al día.',
            default => 'Tu cuenta ha sido suspendida.',
        };

        return view('suspended', [
            'company' => $company,
            'reason' => $reason,
            'plan' => $subscription?->plan,
            'whatsapp' => (string) config('platform.support_whatsapp'),
            'email' => (string) config('platform.support_email'),
            'platformName' => (string) config('platform.name'),
        ]);
    }
}
