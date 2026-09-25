<?php

declare(strict_types=1);

namespace App\Modules\SocialCommerce\Http\Controllers;

use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\Inventory\Models\Product;
use App\Modules\Social\Enums\KeywordMatch;
use App\Modules\Social\Exceptions\SocialException;
use App\Modules\Social\Services\ZernioClient;
use App\Modules\SocialCommerce\Enums\TemplateChannel;
use App\Modules\SocialCommerce\Http\Requests\StoreRuleRequest;
use App\Modules\SocialCommerce\Models\Rule;
use App\Modules\SocialCommerce\Services\RuleSyncService;
use App\Modules\SocialCommerce\Services\TemplateRenderer;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Reglas palabra clave → producto → precio → plantilla.
 *
 * Cada guardado se sincroniza con Zernio a través de `RuleSyncService`; la regla local es la
 * fuente de la configuración, Zernio es quien de verdad la ejecuta (mismo reparto de
 * responsabilidad que `SocialAutomationController`, pero aquí SÍ hay tabla propia porque hace
 * falta guardar el producto, el precio y las plantillas sin resolver).
 */
final class RuleController extends Controller
{
    public function index(CurrentCompany $currentCompany): View
    {
        $rules = Rule::with(['product', 'dmTemplates', 'publicTemplates'])
            ->orderByDesc('id')
            ->get();

        return view('panel.social-commerce.index', [
            'rules' => $rules,
            'cuentas' => $this->cuentas($currentCompany),
        ]);
    }

    public function create(CurrentCompany $currentCompany): View
    {
        return view('panel.social-commerce.form', $this->datosDelFormulario($currentCompany, null));
    }

    public function edit(Rule $rule, CurrentCompany $currentCompany): View
    {
        $rule->load(['dmTemplates', 'publicTemplates']);

        return view('panel.social-commerce.form', $this->datosDelFormulario($currentCompany, $rule));
    }

    public function store(StoreRuleRequest $request, RuleSyncService $sync): RedirectResponse
    {
        $rule = DB::transaction(function () use ($request): Rule {
            $rule = Rule::create($request->paraRegla());
            $this->guardarPlantillas($rule, $request);

            return $rule;
        });

        $sync->sync($rule);

        return $rule->sync_error === null
            ? redirect()->route('panel.social-commerce.index')->with('panel_ok', 'Regla creada y sincronizada con Zernio.')
            : redirect()->route('panel.social-commerce.edit', $rule)
                ->with('panel_error', "Se guardó, pero no se pudo sincronizar con Zernio: {$rule->sync_error}");
    }

    public function update(StoreRuleRequest $request, Rule $rule, RuleSyncService $sync): RedirectResponse
    {
        DB::transaction(function () use ($request, $rule): void {
            $rule->update($request->paraRegla());
            $rule->templates()->delete();
            $this->guardarPlantillas($rule, $request);
        });

        $sync->sync($rule->fresh());

        return $rule->sync_error === null
            ? redirect()->route('panel.social-commerce.index')->with('panel_ok', 'Regla guardada y sincronizada con Zernio.')
            : back()->withInput()->with('panel_error', "Se guardó, pero no se pudo sincronizar con Zernio: {$rule->sync_error}");
    }

    public function toggle(Request $request, Rule $rule, RuleSyncService $sync): RedirectResponse
    {
        $rule = $request->boolean('is_active') ? $sync->activate($rule) : $sync->pause($rule);

        return $rule->sync_error === null
            ? back()->with('panel_ok', $request->boolean('is_active') ? 'Regla encendida.' : 'Regla pausada.')
            : back()->with('panel_error', "No se pudo cambiar el estado en Zernio: {$rule->sync_error}");
    }

    public function destroy(Rule $rule, RuleSyncService $sync): RedirectResponse
    {
        $sync->delete($rule);

        return back()->with('panel_ok', 'Regla borrada.');
    }

    /** Trae de Zernio las publicaciones subidas fuera del panel, para poder elegirlas. */
    public function syncPosts(CurrentCompany $currentCompany): RedirectResponse
    {
        $company = $currentCompany->model();
        abort_if($company === null, 403);

        $cliente = new ZernioClient($company);
        $encontradas = 0;

        try {
            foreach ($this->cuentas($currentCompany) as $cuenta) {
                $encontradas += $cliente->syncExternalPosts($cuenta['id']);
            }
        } catch (SocialException $e) {
            return back()->with('panel_error', $e->getMessage());
        }

        return back()->with('panel_ok', $encontradas > 0
            ? "Encontramos {$encontradas} ".($encontradas === 1 ? 'publicación' : 'publicaciones').'.'
            : 'No encontramos publicaciones nuevas.');
    }

    /**
     * @return array<string, mixed>
     */
    private function datosDelFormulario(CurrentCompany $currentCompany, ?Rule $rule): array
    {
        $company = $currentCompany->model();
        abort_if($company === null, 403);

        $cliente = new ZernioClient($company);
        $publicaciones = [];
        $aviso = null;

        if ($cliente->isConfigured()) {
            try {
                $publicaciones = $cliente->publishedPosts();
            } catch (Throwable $e) {
                report($e);
                $aviso = 'No se pudieron traer las publicaciones. Puedes elegir «en todas» y afinarlo luego.';
            }
        }

        return [
            'rule' => $rule,
            'cuentas' => $this->cuentas($currentCompany),
            'productos' => Product::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'sku', 'price']),
            'publicaciones' => $publicaciones,
            'aviso' => $aviso,
            'coincidencias' => KeywordMatch::cases(),
            'canales' => TemplateChannel::cases(),
            'variables' => TemplateRenderer::VARIABLES,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function cuentas(CurrentCompany $currentCompany): array
    {
        $company = $currentCompany->model();
        abort_if($company === null, 403);

        $cliente = new ZernioClient($company);

        if (! $cliente->isConfigured()) {
            return [];
        }

        try {
            return array_values(array_filter(
                $cliente->accounts(),
                static fn (array $c): bool => in_array($c['platform'], ['instagram', 'facebook'], true) && ! $c['necesita_reconectar'],
            ));
        } catch (Throwable $e) {
            report($e);

            return [];
        }
    }

    private function guardarPlantillas(Rule $rule, StoreRuleRequest $request): void
    {
        foreach ($request->plantillas('dm_templates') as $posicion => $texto) {
            $rule->templates()->create(['channel' => TemplateChannel::Dm->value, 'body' => $texto, 'position' => $posicion]);
        }

        foreach ($request->plantillas('public_templates') as $posicion => $texto) {
            $rule->templates()->create(['channel' => TemplateChannel::Public->value, 'body' => $texto, 'position' => $posicion]);
        }
    }
}
