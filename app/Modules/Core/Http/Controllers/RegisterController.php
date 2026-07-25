<?php

declare(strict_types=1);

namespace App\Modules\Core\Http\Controllers;

use App\Models\User;
use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Http\Requests\RegisterCompanyRequest;
use App\Modules\Core\Models\Plan;
use App\Modules\Core\Services\CompanyOnboardingService;
use App\Modules\Core\Services\SubscriptionService;
use App\Modules\Core\Support\ModuleRegistry;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;

/**
 * Registro self-service: un cliente nuevo crea su empresa y arranca una prueba gratuita eligiendo
 * qué módulos probar. Los datos que registre son de prueba y se borran automáticamente 24 h después
 * de vencer la prueba si no contrata un plan (ver TenantDataPurger / trials:purge).
 *
 * Es público (guest). No usa el registro de Fortify porque este solo crea el usuario, no el tenant
 * completo (empresa + roles + suscripción de prueba).
 */
final class RegisterController extends Controller
{
    public function create(): View
    {
        return view('auth.register', [
            'modules' => ModuleRegistry::all(),
            'trialDays' => (int) config('bmos.trial.days'),
        ]);
    }

    public function store(
        RegisterCompanyRequest $request,
        CompanyOnboardingService $onboarding,
        SubscriptionService $subscriptions,
    ): RedirectResponse {
        $data = $request->validated();
        $modules = ModuleRegistry::sanitize($data['modules']);

        // Crea empresa + sucursal + almacén + roles + usuario propietario, con los módulos elegidos.
        $company = $onboarding->register(
            data: new CreateCompanyData(name: $data['company_name'], email: $data['owner_email']),
            owner: [
                'name' => $data['owner_name'],
                'email' => $data['owner_email'],
                'password' => $data['password'],
            ],
            modules: $modules,
        );

        // Prueba gratuita: el plan solo aporta módulos heredados; el acceso lo gobierna company.modules.
        $plan = Plan::query()->where('slug', config('bmos.trial.plan_slug'))->firstOrFail();
        $subscriptions->startSelfServiceTrial($company, $plan, (int) config('bmos.trial.days'));

        // Entra directo a su panel.
        $owner = User::query()->where('email', $data['owner_email'])->firstOrFail();
        Auth::login($owner);
        $request->session()->regenerate();

        return redirect()->route('dashboard')->with('panel_ok', '¡Bienvenido! Tu prueba gratuita está activa.');
    }
}
