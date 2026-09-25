<?php

declare(strict_types=1);

namespace App\Modules\SocialCommerce\Http\Controllers;

use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\SocialCommerce\Enums\RuleStatus;
use App\Modules\SocialCommerce\Models\Rule;
use App\Modules\SocialCommerce\Services\KeywordMatcher;
use App\Modules\SocialCommerce\Services\TemplateRenderer;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Modo prueba (prompt maestro, sección 31): simula qué pasaría con un comentario, sin publicar ni
 * enviar nada a Zernio ni crear ningún registro. Es una aproximación local (ver `KeywordMatcher`),
 * no lo que Zernio haría al milímetro.
 */
final class SandboxController extends Controller
{
    public function index(): View
    {
        return view('panel.social-commerce.sandbox', [
            'reglas' => $this->reglasActivas(),
        ]);
    }

    public function simulate(Request $request, KeywordMatcher $matcher, TemplateRenderer $renderer, CurrentCompany $currentCompany): JsonResponse
    {
        $request->validate(['comentario' => ['required', 'string', 'max:500']]);
        $comentario = (string) $request->input('comentario');

        $pasos = [['ok' => true, 'texto' => "Comentario recibido: «{$comentario}»"]];

        // Orden de creación, la más antigua primero: es la que se queda con el comentario cuando
        // varias reglas comparten palabra y ámbito (mismo comportamiento real de Zernio, ver
        // App\Modules\Social\Support\AutomationOverlap).
        $reglas = $this->reglasActivas();

        if ($reglas->isEmpty()) {
            $pasos[] = ['ok' => false, 'texto' => 'No hay ninguna regla activa. Crea o enciende una primero.'];

            return response()->json(['coincide' => false, 'pasos' => $pasos]);
        }

        foreach ($reglas as $rule) {
            $palabra = $matcher->matches($rule, $comentario);

            if ($palabra === null) {
                continue;
            }

            $company = $currentCompany->model();
            $producto = $rule->product;

            $pasos[] = ['ok' => true, 'texto' => "Palabra clave encontrada: «{$palabra}» (regla «{$rule->name}»)"];
            $pasos[] = ['ok' => true, 'texto' => "Producto identificado: {$producto->name}"];
            $pasos[] = ['ok' => true, 'texto' => "Precio identificado: {$company->currency} ".number_format((float) $producto->price, 2)];

            $plantilla = $rule->dmTemplates->count() > 0 ? $rule->dmTemplates->random() : null;

            if ($plantilla === null) {
                $pasos[] = ['ok' => false, 'texto' => 'La regla no tiene ningún mensaje privado configurado.'];

                return response()->json(['coincide' => true, 'regla' => $rule->name, 'pasos' => $pasos]);
            }

            $texto = $renderer->render($plantilla->body, $producto, $company);

            $pasos[] = ['ok' => true, 'texto' => $rule->dmTemplates->count() > 1
                ? "Plantilla elegida al azar entre {$rule->dmTemplates->count()} (así decide Zernio en la vida real)"
                : 'Plantilla seleccionada'];
            $pasos[] = ['ok' => true, 'texto' => "Respuesta simulada: «{$texto}»"];

            return response()->json(['coincide' => true, 'regla' => $rule->name, 'pasos' => $pasos]);
        }

        $pasos[] = ['ok' => false, 'texto' => 'Ninguna regla activa reconoce ese comentario. Revisa las palabras clave.'];

        return response()->json(['coincide' => false, 'pasos' => $pasos]);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, Rule>
     */
    private function reglasActivas()
    {
        return Rule::with(['product', 'dmTemplates'])
            ->where('status', RuleStatus::Active)
            ->orderBy('id')
            ->get();
    }
}
