<?php

declare(strict_types=1);

namespace App\Modules\Core\Http\Controllers;

use App\Modules\Core\Http\Requests\StorePlanRequest;
use App\Modules\Core\Models\Plan;
use App\Modules\Core\Support\ModuleRegistry;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;

/**
 * Gestión de los planes de suscripción (super administrador). Los planes viven en la plataforma;
 * las rutas exigen «platform.manage».
 */
final class PlanController extends Controller
{
    public function index(): View
    {
        return view('panel.admin.plans', [
            'plans' => Plan::query()->withCount('subscriptions')->orderBy('price')->get(),
            'modules' => ModuleRegistry::all(),
        ]);
    }

    public function store(StorePlanRequest $request): RedirectResponse
    {
        Plan::create($this->normalize($request));

        return back()->with('panel_ok', 'Plan creado.');
    }

    public function update(StorePlanRequest $request, Plan $plan): RedirectResponse
    {
        $plan->update($this->normalize($request));

        return back()->with('panel_ok', 'Plan actualizado.');
    }

    public function destroy(Plan $plan): RedirectResponse
    {
        // No se borra un plan con suscripciones vivas: dejaría empresas sin plan de referencia.
        if ($plan->subscriptions()->exists()) {
            return back()->with('panel_error', 'No puedes eliminar un plan con suscripciones activas. Desactívalo en su lugar.');
        }

        $plan->delete();

        return back()->with('panel_ok', 'Plan eliminado.');
    }

    /**
     * @return array<string, mixed>
     */
    private function normalize(StorePlanRequest $request): array
    {
        $data = $request->validated();

        $modules = ModuleRegistry::sanitize($data['modules']);
        // Todos los módulos = null (el plan lo incluye todo, y hereda módulos futuros).
        $data['modules'] = count($modules) === count(ModuleRegistry::keys()) ? null : $modules;
        $data['is_active'] = $request->boolean('is_active');

        return $data;
    }
}
