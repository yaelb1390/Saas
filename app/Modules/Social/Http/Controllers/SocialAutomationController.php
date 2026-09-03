<?php

declare(strict_types=1);

namespace App\Modules\Social\Http\Controllers;

use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\Social\Enums\AutomationTrigger;
use App\Modules\Social\Enums\KeywordMatch;
use App\Modules\Social\Exceptions\SocialException;
use App\Modules\Social\Http\Requests\StoreAutomationRequest;
use App\Modules\Social\Services\ZernioClient;
use App\Modules\Social\Support\AutomationOverlap;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Throwable;

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
            } catch (Throwable $e) {
                /*
                 * El mismo agujero que tenía la lista de publicaciones. `SocialException` la lanzamos
                 * nosotros cuando la API contesta mal; pero cuando NO contesta —plazo agotado, DNS que
                 * no resuelve— lo que sube es una excepción de la capa HTTP, que no es nuestra y se
                 * colaba entera hasta dar un 500. Y «no contesta» es justo el caso para el que existe
                 * este aviso.
                 *
                 * El mensaje no es el de la excepción: ahí vendría una cadena técnica con la URL
                 * dentro. Al dueño se le dice lo único que le sirve, que vuelva a intentarlo.
                 */
                report($e);

                $aviso = 'No se pudo hablar con el servicio de redes. Vuelve a intentarlo en un momento.';
            }
        }

        return view('panel.social-automations', [
            'configurado' => $cliente->isConfigured(),
            'automatizaciones' => $automatizaciones,
            // El «reporte aquí mismo»: sale de los contadores que YA vinieron para pintar la lista,
            // así que no cuesta ni una llamada más. Un dashboard aparte necesitaría una por
            // automatización, con 20 s de plazo cada una, y en producción no hay tiempo para eso.
            'resumen' => $this->resumen($automatizaciones),
            'cuentas' => $cuentas,
            // Para poder colgar la automatización de una publicación concreta. Si falla, se devuelve
            // vacío: quedarse sin la lista solo quita la opción de afinar, no impide crear nada.
            'publicaciones' => $this->publicaciones($cliente, $aviso),
            'aviso' => $aviso,
            'coincidencias' => KeywordMatch::cases(),
            'disparadores' => AutomationTrigger::cases(),
            // Cuáles no van a llegar a responder porque otra se les adelanta. Se calcula con lo que
            // ya vino en la misma llamada: no cuesta ni una petición más.
            'tapadas' => AutomationOverlap::tapadas($automatizaciones),
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

    /**
     * El reporte de una automatización: a quién le contestó, por qué falló y qué se le escapó.
     *
     * Responde a las dos preguntas que los contadores del listado no pueden responder, y las dos son
     * por automatización. De ahí que esto sea una pantalla por automatización y no un panel general:
     * juntarlas todas costaría una llamada por cada una, con su plazo, y en producción no hay tiempo.
     *
     * Cuesta DOS llamadas: el listado —que ya trae los contadores y evita pedir la automatización
     * suelta— y el registro.
     */
    public function reporte(string $automation, CurrentCompany $currentCompany): View|RedirectResponse
    {
        $cliente = $this->cliente($currentCompany);

        try {
            $automatizaciones = $cliente->automations();
        } catch (SocialException $e) {
            return back()->with('panel_error', $e->getMessage());
        }

        $a = null;

        foreach ($automatizaciones as $candidata) {
            if ($candidata['id'] === $automation) {
                $a = $candidata;
                break;
            }
        }

        if ($a === null) {
            return redirect()->route('panel.social.automations')
                ->with('panel_error', 'Esa respuesta automática ya no existe.');
        }

        // El registro nunca revienta la pantalla: devuelve su forma vacía si Zernio no responde.
        $registro = $cliente->automationLogs($automation, 200, $this->estadoPedido());

        return view('panel.social-automation-report', [
            'a' => $a,
            'logs' => $registro['logs'],
            'total' => $registro['pagination']['total'],
            'estado' => $this->estadoPedido(),
            'escapes' => $registro['misses'],
            /*
             * El registro que se tiene, ¿es el histórico completo?
             *
             * De esto depende que se pueda dibujar la gráfica sin mentir: si hay más disparos que los
             * 200 que caben, el eje sería «los últimos dos días» y se leería como un desplome que en
             * realidad es el borde de la ventana.
             */
            'completo' => $registro['pagination']['total'] <= 200,
            'porDia' => $this->agruparPorDia($registro['logs']),
        ]);
    }

    /** El filtro de estado, solo si es uno de los que la API admite. */
    private function estadoPedido(): ?string
    {
        $estado = (string) request()->query('estado', '');

        return in_array($estado, ['pending', 'sent', 'failed', 'skipped', 'gated'], true) ? $estado : null;
    }

    /**
     * Los disparos agrupados por día, EN LA ZONA DEL NEGOCIO.
     *
     * Agrupar en UTC partiría una noche dominicana en dos: a UTC-4, un comentario de las 22:00 caería
     * en el día siguiente y la gráfica repartiría entre dos barras lo que pasó en una sola tarde.
     *
     * Se rellenan los días sin disparos con cero: sin eso, el eje comprime los huecos e inventa una
     * densidad que no hubo.
     *
     * @param  array<int, array<string, mixed>>  $logs
     * @return array<string, int>
     */
    private function agruparPorDia(array $logs): array
    {
        $cuentas = [];

        foreach ($logs as $log) {
            if (blank($log['createdAt'] ?? null)) {
                continue;
            }

            $dia = rescue(
                fn (): ?string => Carbon::parse((string) $log['createdAt'])->timezone(config('app.business_timezone'))->toDateString(),
                null,
                report: false,
            );

            if ($dia !== null) {
                $cuentas[$dia] = ($cuentas[$dia] ?? 0) + 1;
            }
        }

        if ($cuentas === []) {
            return [];
        }

        // Como mucho un mes: más atrás las barras se vuelven ilegibles y nadie mira tan lejos.
        $desde = Carbon::parse(min(array_keys($cuentas)))
            ->max(Carbon::now(config('app.business_timezone'))->subDays(29)->startOfDay());
        $hasta = Carbon::parse(max(array_keys($cuentas)));

        $serie = [];

        for ($d = $desde->copy(); $d->lte($hasta); $d->addDay()) {
            $serie[$d->toDateString()] = $cuentas[$d->toDateString()] ?? 0;
        }

        return $serie;
    }

    /**
     * Los totales de todas las automatizaciones.
     *
     * @param  array<int, array<string, mixed>>  $automatizaciones
     * @return array<string, int>
     */
    private function resumen(array $automatizaciones): array
    {
        $resumen = ['encendidas' => 0, 'triggered' => 0, 'sent' => 0, 'people' => 0, 'failed' => 0];

        foreach ($automatizaciones as $a) {
            $resumen['encendidas'] += $a['isActive'] ? 1 : 0;
            // Los disparos no se enseñan en la tira, pero deciden si «0 fallos» significa algo:
            // sin un solo disparo, «sin fallos» es tan vacío como en una tarjeta recién creada.
            $resumen['triggered'] += $a['stats']['triggered'];
            $resumen['sent'] += $a['stats']['sent'];
            // Personas es el único que NO se puede sumar de verdad: quien comentó en dos
            // automatizaciones cuenta dos veces. Es una cota superior y así se dice en pantalla.
            $resumen['people'] += $a['stats']['people'];
            $resumen['failed'] += $a['stats']['failed'];
        }

        return $resumen;
    }

    /**
     * Las publicaciones ya subidas, para poder colgarles una automatización.
     *
     * EL COMENTARIO DE ARRIBA PROMETÍA ESTO Y EL CÓDIGO NO LO HACÍA: la llamada estaba fuera de
     * cualquier `try`, así que un fallo o una lentitud de la API de Zernio no dejaba la lista vacía,
     * tumbaba la pantalla entera con un 500. Y no es hipotético: se veía en la suite como un test que
     * fallaba de vez en cuando tardando cien segundos, que es lo que tarda una petición en rendirse.
     *
     * Se atrapa `Throwable` y no solo `SocialException` a propósito. Un plazo agotado o un DNS que no
     * resuelve no llegan como excepción del módulo, llegan como excepción de la capa HTTP; atrapar
     * solo la nuestra es justamente lo que dejaba pasar el caso que rompía.
     *
     * `report()` para que quede en el registro: degradar en silencio también es mentir.
     *
     * @return array<int, mixed>
     */
    private function publicaciones(ZernioClient $cliente, ?string $aviso): array
    {
        if (! $cliente->isConfigured() || $aviso !== null) {
            return [];
        }

        try {
            return $cliente->publishedPosts();
        } catch (Throwable $e) {
            report($e);

            return [];
        }
    }

    private function cliente(CurrentCompany $currentCompany): ZernioClient
    {
        $company = $currentCompany->model();

        abort_if($company === null, 403);

        return new ZernioClient($company);
    }
}
