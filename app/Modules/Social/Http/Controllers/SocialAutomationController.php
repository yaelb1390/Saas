<?php

declare(strict_types=1);

namespace App\Modules\Social\Http\Controllers;

use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\Social\Enums\KeywordMatch;
use App\Modules\Social\Exceptions\SocialException;
use App\Modules\Social\Http\Requests\StoreAutomationRequest;
use App\Modules\Social\Services\ZernioClient;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Respuestas automáticas: alguien comenta una palabra y el negocio le contesta solo, en público y por
 * privado.
 *
 * Zernio EJECUTA la automatización; esta pantalla solo la escribe. Por eso no hay tablas propias ni
 * procesos en segundo plano —que en producción no existen—: se listan, se crean y se cambian contra
 * su API, y lo que se ve es siempre lo que hay allí.
 */
final class SocialAutomationController extends Controller
{
    /** Las únicas redes que admiten esto. Ofrecer otra crearía algo que no se dispara nunca. */
    private const REDES_CON_AUTOMATIZACION = ['instagram', 'facebook'];

    public function index(CurrentCompany $currentCompany): View
    {
        $cliente = $this->cliente($currentCompany);

        $automatizaciones = [];
        $cuentas = [];
        $aviso = null;

        if ($cliente->isConfigured()) {
            try {
                $automatizaciones = $cliente->automations();
                $cuentas = array_values(array_filter(
                    $cliente->accounts(),
                    static fn (array $c): bool => in_array($c['platform'], self::REDES_CON_AUTOMATIZACION, true)
                        && ! $c['necesita_reconectar'],
                ));
            } catch (SocialException $e) {
                $aviso = $e->getMessage();
            }
        }

        return view('panel.social-automations', [
            'configurado' => $cliente->isConfigured(),
            'automatizaciones' => $automatizaciones,
            'cuentas' => $cuentas,
            // Para poder colgar la automatización de una publicación concreta. Si falla, se devuelve
            // vacío: quedarse sin la lista solo quita la opción de afinar, no impide crear nada.
            'publicaciones' => $cliente->isConfigured() && $aviso === null ? $cliente->publishedPosts() : [],
            'aviso' => $aviso,
            'coincidencias' => KeywordMatch::cases(),
        ]);
    }

    /**
     * Trae de la red las publicaciones que no se hicieron desde aquí.
     *
     * La mayoría de un negocio pequeño se sube desde el móvil, y sin esto «colgar la automatización de
     * esta foto» solo funcionaría con las subidas desde el panel, que son las menos.
     */
    public function syncPosts(CurrentCompany $currentCompany): RedirectResponse
    {
        $cliente = $this->cliente($currentCompany);

        try {
            $encontradas = 0;

            // Todas las cuentas que pueden tener automatizaciones, sin pedirle al dueño que elija:
            // un negocio pequeño tiene una o dos, y hacerle escoger antes de buscar es un paso de más.
            foreach ($cliente->accounts() as $cuenta) {
                if (in_array($cuenta['platform'], self::REDES_CON_AUTOMATIZACION, true) && ! $cuenta['necesita_reconectar']) {
                    $encontradas += $cliente->syncExternalPosts($cuenta['id']);
                }
            }
        } catch (SocialException $e) {
            return back()->with('panel_error', $e->getMessage());
        }

        return back()->with('panel_ok', $encontradas > 0
            ? "Encontramos {$encontradas} ".($encontradas === 1 ? 'publicación' : 'publicaciones').'. Ya puedes elegirla abajo.'
            : 'No encontramos publicaciones. Puede que la cuenta todavía no tenga ninguna.');
    }

    public function store(StoreAutomationRequest $request, CurrentCompany $currentCompany): RedirectResponse
    {
        try {
            $this->cliente($currentCompany)->createAutomation($request->paraZernio());
        } catch (SocialException $e) {
            return back()->withInput()->with('panel_error', $e->getMessage());
        }

        return back()->with('panel_ok', 'Respuesta automática creada.');
    }

    public function update(StoreAutomationRequest $request, string $automation, CurrentCompany $currentCompany): RedirectResponse
    {
        try {
            $this->cliente($currentCompany)->updateAutomation($automation, $request->paraZernio());
        } catch (SocialException $e) {
            return back()->withInput()->with('panel_error', $e->getMessage());
        }

        return back()->with('panel_ok', 'Respuesta automática guardada.');
    }

    /**
     * Enciende o apaga sin tocar nada más.
     *
     * Existe aparte de la edición porque apagar algo que está contestando mal tiene que ser un clic,
     * no abrir un formulario y volver a guardarlo entero.
     */
    public function toggle(Request $request, string $automation, CurrentCompany $currentCompany): RedirectResponse
    {
        $activa = $request->boolean('is_active');

        try {
            $this->cliente($currentCompany)->updateAutomation($automation, ['isActive' => $activa]);
        } catch (SocialException $e) {
            return back()->with('panel_error', $e->getMessage());
        }

        return back()->with('panel_ok', $activa
            ? 'Respuesta automática encendida.'
            : 'Respuesta automática apagada: deja de contestar.');
    }

    public function destroy(string $automation, CurrentCompany $currentCompany): RedirectResponse
    {
        try {
            $this->cliente($currentCompany)->deleteAutomation($automation);
        } catch (SocialException $e) {
            return back()->with('panel_error', $e->getMessage());
        }

        return back()->with('panel_ok', 'Respuesta automática borrada.');
    }

    /** Los últimos disparos, para responder a «lo monté y no hace nada». */
    public function logs(string $automation, CurrentCompany $currentCompany): View
    {
        return view('panel.social-automation-logs', [
            'logs' => $this->cliente($currentCompany)->automationLogs($automation),
        ]);
    }

    private function cliente(CurrentCompany $currentCompany): ZernioClient
    {
        $company = $currentCompany->model();

        abort_if($company === null, 403);

        return new ZernioClient($company);
    }
}
