<?php

declare(strict_types=1);

namespace App\Modules\Core\Http\Controllers;

use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Http\Requests\StoreCompanyRequest;
use App\Modules\Core\Http\Requests\UpdateCompanyModulesRequest;
use App\Modules\Core\Models\Company;
use App\Modules\Core\Models\Plan;
use App\Modules\Core\Services\CompanyOnboardingService;
use App\Modules\Core\Services\SubscriptionService;
use App\Modules\Core\Support\ModuleRegistry;
use App\Modules\POS\Support\PosProfile;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;

/**
 * Panel del operador de la plataforma (super administrador): alta de empresas y qué módulos
 * tiene contratada cada una. Las rutas exigen la habilidad «platform.manage», que ningún rol de
 * empresa posee: solo el super admin la atraviesa (Gate::before).
 *
 * Aquí NO se aplica el aislamiento por empresa: el super admin ve y administra todas.
 */
final class CompanyAdminController extends Controller
{
    public function index(): View
    {
        // Presets de cada tipo de negocio, para que el selector aplique sus opciones recomendadas.
        $posPresets = [];
        foreach (array_keys(PosProfile::types()) as $type) {
            $posPresets[$type] = PosProfile::defaults($type);
        }

        return view('panel.admin.companies', [
            'companies' => Company::query()->with('subscription.plan')->withCount('users')->orderBy('name')->get(),
            'modules' => ModuleRegistry::all(),
            'plans' => Plan::query()->where('is_active', true)->orderBy('price')->get(),
            'posTypes' => PosProfile::types(),
            'posOptionLabels' => PosProfile::optionLabels(),
            'posPresets' => $posPresets,
        ]);
    }

    public function store(StoreCompanyRequest $request, CompanyOnboardingService $onboarding): RedirectResponse
    {
        $data = $request->validated();

        $company = $onboarding->register(
            data: new CreateCompanyData(
                name: $data['name'],
                taxId: $data['tax_id'] ?? null,
                email: $data['email'] ?? null,
            ),
            owner: [
                'name' => $data['owner_name'],
                'email' => $data['owner_email'],
                'password' => $data['owner_password'],
            ],
            modules: $data['modules'] ?? null,
        );

        return back()->with('panel_ok', "Empresa «{$company->name}» creada con su propietario.");
    }

    public function updateModules(UpdateCompanyModulesRequest $request, Company $company): RedirectResponse
    {
        // «Volver al plan»: la empresa deja de tener ajuste manual y hereda los módulos de su plan
        // (o el plan completo si no está suscrita).
        if ($request->boolean('follow_plan')) {
            $company->update(['modules' => null]);

            return back()->with('panel_ok', "«{$company->name}» vuelve a los módulos de su plan.");
        }

        $selected = ModuleRegistry::sanitize($request->validated()['modules'] ?? []);

        // NULL significa «heredar»: los módulos del plan si está suscrita, o todos si no lo está.
        // Por eso, si el operador selecciona exactamente ese conjunto de referencia, lo guardamos
        // como NULL para que la empresa siga al plan en lugar de quedar «congelada» con una copia.
        $reference = $company->subscription?->plan?->moduleKeys() ?? ModuleRegistry::keys();
        $matchesReference = count($selected) === count($reference) && array_diff($reference, $selected) === [];

        $company->update(['modules' => $matchesReference ? null : $selected]);

        return back()->with('panel_ok', "Módulos de «{$company->name}» actualizados.");
    }

    /**
     * Define el tipo de negocio y las opciones del POS de una empresa. Es una decisión del operador
     * de la plataforma: por eso vive aquí (super admin) y no en el panel de la empresa.
     */
    public function updatePosProfile(Request $request, Company $company): RedirectResponse
    {
        $data = $request->validate([
            'profile' => ['required', 'string', Rule::in(array_keys(PosProfile::types()))],
            'options' => ['nullable', 'array'],
        ]);

        // Solo llegan marcadas las opciones activas; las demás quedan explícitamente en false.
        $sent = is_array($data['options'] ?? null) ? $data['options'] : [];
        $options = [];
        foreach (PosProfile::optionKeys() as $key) {
            $options[$key] = (bool) ($sent[$key] ?? false);
        }

        $settings = $company->settings ?? [];
        $settings['pos'] = ['profile' => $data['profile'], 'options' => $options];
        $company->update(['settings' => $settings]);

        return back()->with('panel_ok', "«{$company->name}» configurada como ".PosProfile::label($data['profile']).'.');
    }

    public function toggleActive(Company $company): RedirectResponse
    {
        $company->update(['is_active' => ! $company->is_active]);

        return back()->with('panel_ok', $company->is_active
            ? "«{$company->name}» reactivada."
            : "«{$company->name}» suspendida.");
    }

    /**
     * Suscribe o cambia de plan a una empresa.
     *
     * - Si se pide «con prueba» y el plan la ofrece, se (re)inicia el período de prueba —también en
     *   empresas que ya tenían suscripción, que es justo lo que el operador espera al marcarla—.
     * - En caso contrario: si ya había suscripción, se cambia el plan conservando el período; si no,
     *   se crea activa.
     */
    public function subscribe(Request $request, Company $company, SubscriptionService $subscriptions): RedirectResponse
    {
        $data = $request->validate([
            'plan_id' => ['required', 'integer', 'exists:plans,id'],
            'with_trial' => ['sometimes', 'boolean'],
        ]);

        $plan = Plan::findOrFail($data['plan_id']);
        $existing = $company->subscription;

        // La casilla solo llega si está marcada; por defecto NO se asume prueba. Además el plan debe
        // ofrecerla (trial_days > 0) para que tenga efecto.
        $wantsTrial = $request->boolean('with_trial') && $plan->trial_days > 0;

        if ($existing !== null && ! $wantsTrial) {
            $subscriptions->changePlan($existing, $plan);
        } else {
            // Alta nueva, o reinicio explícito a período de prueba sobre una suscripción existente.
            $subscriptions->subscribe($company, $plan, $wantsTrial);
        }

        $message = $wantsTrial
            ? "«{$company->name}» en prueba de {$plan->trial_days} días con el plan {$plan->name}."
            : "«{$company->name}» suscrita al plan {$plan->name}.";

        return back()->with('panel_ok', $message);
    }

    public function registerPayment(Company $company, SubscriptionService $subscriptions): RedirectResponse
    {
        $subscription = $company->subscription;
        abort_if($subscription === null, 404);

        $subscriptions->registerPayment($subscription);

        return back()->with('panel_ok', "Pago registrado para «{$company->name}». Suscripción al día.");
    }

    public function suspendSubscription(Company $company, SubscriptionService $subscriptions): RedirectResponse
    {
        $subscription = $company->subscription;
        abort_if($subscription === null, 404);

        $subscriptions->suspend($subscription);

        return back()->with('panel_ok', "Suscripción de «{$company->name}» suspendida.");
    }
}
