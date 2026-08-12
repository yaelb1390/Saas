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
 * El alta NO pregunta el plan: entra todo el mundo por el de configuración (el más sencillo) y lo
 * cambia luego desde su panel. Antes se pedía marcar módulos sueltos, y después elegir plan entre
 * tres tarjetas; las dos versiones exigían una decisión que el cliente no puede tomar todavía —aún
 * no ha visto el producto— justo en el punto donde más gente abandona. Quien quiera comparar antes
 * de decidir tiene la pantalla pública de planes, enlazada desde el formulario.
 *
 * Es público (guest). No usa el registro de Fortify porque este solo crea el usuario, no el tenant
 * completo (empresa + roles + suscripción de prueba).
 */
final class RegisterController extends Controller
{
    public function create(): View
    {
        return view('auth.register', [
            'trialDays' => (int) config('bmos.trial.days'),
        ]);
    }

    /**
     * Plan con el que arranca toda prueba.
     *
     * Si el configurado no existe o está inactivo, se cae al plan activo más barato: un catálogo mal
     * configurado no puede dejar sin alta a un cliente que ya rellenó el formulario.
     */
    private function planDeRegistro(): ?Plan
    {
        $porConfiguracion = Plan::query()
            ->where('slug', (string) config('bmos.registration.default_plan_slug'))
            ->where('is_active', true)
            ->first();

        return $porConfiguracion ?? Plan::query()->where('is_active', true)->orderBy('price')->first();
    }

    public function store(
        RegisterCompanyRequest $request,
        CompanyOnboardingService $onboarding,
        SubscriptionService $subscriptions,
    ): RedirectResponse {
        $data = $request->validated();

        // El plan se resuelve ANTES de crear nada: si no hubiera ninguno, no queremos una empresa ya
        // creada y sin suscripción, que es lo que dejaba el `firstOrFail()` que había aquí.
        $plan = $this->planDeRegistro();

        if ($plan === null) {
            return back()->withInput()->with('panel_error',
                'No hay planes disponibles ahora mismo. Escríbenos y te damos de alta a mano.');
        }

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

        // La duración la manda la configuración, no `plan->trial_days`: la pantalla promete unos días
        // concretos y siguen siendo esos aunque luego cambie de plan durante la prueba.
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
