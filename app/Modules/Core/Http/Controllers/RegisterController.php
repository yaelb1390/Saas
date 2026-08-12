<?php

declare(strict_types=1);

namespace App\Modules\Core\Http\Controllers;

use App\Models\User;
use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Http\Requests\RegisterCompanyRequest;
use App\Modules\Core\Mail\TrialWelcomeMail;
use App\Modules\Core\Models\Plan;
use App\Modules\Core\Services\CompanyOnboardingService;
use App\Modules\Core\Services\SubscriptionService;
use App\Modules\Core\Support\ModuleRegistry;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;

/**
 * Registro self-service: un cliente nuevo crea su empresa y arranca una prueba gratuita del PLAN que
 * elija. Los datos que registre son de prueba y se borran automáticamente 24 h después de vencer la
 * prueba si no contrata un plan (ver TenantDataPurger / trials:purge).
 *
 * Antes se le pedía marcar módulos sueltos de una lista de catorce. Se cambió por el plan porque esa
 * era una decisión que el cliente no podía tomar: aún no conoce el producto y los nombres de los
 * módulos no le dicen cuánto va a pagar. Eligiendo plan prueba exactamente lo que va a comprar, y al
 * terminar la prueba el paso natural es pagar ese mismo plan.
 *
 * Es público (guest). No usa el registro de Fortify porque este solo crea el usuario, no el tenant
 * completo (empresa + roles + suscripción de prueba).
 */
final class RegisterController extends Controller
{
    public function create(): View
    {
        return view('auth.register', [
            // Solo los planes en venta, ordenados de menor a mayor precio para que se lean como una
            // escalera. Un plan inactivo no se ofrece, y la validación lo vuelve a comprobar.
            'plans' => Plan::query()->where('is_active', true)->orderBy('price')->get(),
            'trialDays' => (int) config('bmos.trial.days'),
        ]);
    }

    public function store(
        RegisterCompanyRequest $request,
        CompanyOnboardingService $onboarding,
        SubscriptionService $subscriptions,
    ): RedirectResponse {
        $data = $request->validated();

        // El plan se resuelve ANTES de crear nada: si algo fallara al buscarlo, no queremos una
        // empresa ya creada y sin suscripción. La validación garantiza que existe y está activo.
        $plan = Plan::query()->findOrFail($data['plan_id']);

        // Crea empresa + sucursal + almacén + roles + usuario propietario.
        //
        // `modules: null` a propósito: la empresa NO fija su propia lista y hereda la del plan (ver
        // Company::hasModule). Guardar aquí una copia de los módulos del plan sería congelarla: si
        // mañana el plan gana un módulo, esta empresa se quedaría fuera sin que nadie lo notara.
        $company = $onboarding->register(
            data: new CreateCompanyData(name: $data['company_name'], email: $data['owner_email']),
            owner: [
                'name' => $data['owner_name'],
                'email' => $data['owner_email'],
                'password' => $data['password'],
            ],
            modules: null,
        );

        // La duración la manda la configuración, no `plan->trial_days`: la pantalla promete los
        // mismos días para todos, y elegir plan cambia QUÉ se prueba, no CUÁNTO.
        $subscription = $subscriptions->startSelfServiceTrial($company, $plan, (int) config('bmos.trial.days'));

        // Entra directo a su panel.
        $owner = User::query()->where('email', $data['owner_email'])->firstOrFail();
        Auth::login($owner);
        $request->session()->regenerate();

        // Correo de bienvenida (encolado). Un fallo de correo NUNCA debe romper el alta ya hecha.
        rescue(fn () => Mail::to($owner->email)->send(new TrialWelcomeMail(
            ownerName: (string) $owner->name,
            companyName: (string) $company->name,
            trialDays: (int) config('bmos.trial.days'),
            trialEndsAt: $subscription->trial_ends_at,
            purgeAt: $subscription->purge_at,
            moduleLabels: $this->moduleLabels($plan->moduleKeys()),
            loginUrl: route('login'),
            supportWhatsapp: (string) config('platform.support_whatsapp'),
            supportEmail: (string) config('platform.support_email'),
        )), report: true);

        return redirect()->route('dashboard')->with('panel_ok', '¡Bienvenido! Tu prueba gratuita está activa.');
    }

    /**
     * Traduce las claves de módulo elegidas a sus etiquetas legibles para el correo.
     *
     * @param  array<int, string>  $keys
     * @return array<int, string>
     */
    private function moduleLabels(array $keys): array
    {
        $all = ModuleRegistry::all();

        return array_values(array_map(static fn (string $key): string => $all[$key] ?? $key, $keys));
    }
}
