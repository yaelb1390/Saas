<?php

declare(strict_types=1);

namespace App\Modules\Core\Http\Controllers;

use App\Modules\Core\Models\Audit;
use App\Modules\Core\Models\Company;
use App\Modules\Core\Models\ErrorEvent;
use App\Modules\Core\Models\SystemEvent;
use App\Modules\Core\Services\CompanyHealthService;
use App\Modules\Core\Services\PlatformHealthService;
use App\Modules\Core\Support\BusquedaTexto;
use App\Modules\Core\Support\DbTable;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Pagination\CursorPaginator as PaginadorVacio;
use Illuminate\Routing\Controller;

/**
 * Monitoreo de la plataforma: qué está pasando, quién lo hizo y qué se está rompiendo.
 *
 * Todo lo de aquí mira a TODAS las empresas. Un super administrador siempre tiene una empresa activa
 * —`SetCurrentCompany` lo fija a la de su sesión o a la primera por id—, así que las consultas van
 * sin el ámbito de empresa a propósito. Sin eso, esta pantalla enseñaría los datos de una sola
 * empresa haciéndolos pasar por los de la plataforma, sin dar error.
 */
final class MonitoringController extends Controller
{
    /** Las acciones que registra la auditoría, en español. */
    private const ACCIONES = [
        'created' => 'creó',
        'updated' => 'modificó',
        'deleted' => 'eliminó',
        'restored' => 'restauró',
    ];

    /**
     * Familias del registro del sistema, para el filtro.
     *
     * Se agrupa por PREFIJO y no se listan los cuarenta tipos: al operador le interesa «los accesos»
     * o «lo que se rompió por fuera», no distinguir `auth.login` de `auth.logout` en un desplegable.
     */
    private const FAMILIAS = [
        'auth' => 'Accesos',
        'integration' => 'Servicios externos',
        'platform' => 'Acciones de plataforma',
        'task' => 'Tareas programadas',
        'webhook' => 'Webhooks',
    ];

    public function __invoke(PlatformHealthService $salud, CompanyHealthService $empresas): View
    {
        return view('panel.admin.monitoring', [
            /*
             * El estado de CADA empresa, que es lo que no había.
             *
             * Todo lo demás de esta pantalla responde «¿cómo está la plataforma?». Para saber si a un
             * cliente concreto le va bien había que ir a mirar sus datos uno por uno, y para
             * enterarse de que se estaba yendo, esperar a que cancelara.
             */
            'salud_empresas' => $empresas->porEmpresa(),
            'avisos' => $empresas->resumenDeAvisos(),
            'registro' => $this->registro(),
            'familias' => self::FAMILIAS,
            'salud' => $salud->resumen(),
            // El pulso de 24 h y la serie de catorce días. Es lo que hace que un número signifique
            // algo: «cuarenta sucesos» no dice nada; cuarenta al lado de los trece días anteriores,
            // sí.
            'pulso' => $salud->pulso(),
            'webhooks' => $salud->webhooksSinResolver(),
            'actividad' => $this->actividad(),
            'errores' => ErrorEvent::query()->with('company')->latest('last_seen_at')->limit(15)->get(),
            'empresas' => Company::query()->orderBy('name')->get(['id', 'name']),
            'acciones' => self::ACCIONES,
            'filtros' => [
                'empresa' => request('empresa'),
                'accion' => request('accion'),
                'familia' => request('familia'),
                'nivel' => request('nivel'),
                'busca' => request('busca'),
            ],
        ]);
    }

    /**
     * La actividad reciente, filtrable.
     *
     * Paginación por CURSOR y no la normal: esta tabla crece sin parar y `paginate()` lanza un
     * `count(*)` de la tabla entera en cada carga solo para saber cuántas páginas hay.
     */
    private function actividad(): CursorPaginator
    {
        return Audit::query()
            ->with('company')
            ->when(request('empresa'), fn ($q, $id) => $q->where('company_id', $id))
            ->when(
                request('accion') && array_key_exists((string) request('accion'), self::ACCIONES),
                fn ($q) => $q->where('event', request('accion')),
            )
            ->latest('created_at')
            ->cursorPaginate(25)
            ->withQueryString();
    }

    /**
     * El registro del sistema, filtrable.
     *
     * Es lo que NO se ve en ningún otro sitio: quién entró, quién lo intentó sin conseguirlo, qué
     * servicio externo falló y qué tocó el operador. La auditoría de al lado cuenta quién cambió una
     * fila; esto cuenta todo lo demás.
     *
     * Paginación por cursor, igual que la actividad y por el mismo motivo: la tabla crece cada día y
     * `paginate()` haría un `count(*)` entero en cada carga.
     */
    private function registro(): CursorPaginator
    {
        /*
         * Sin la tabla se devuelve un listado vacío y la pantalla se pinta. El monitoreo es a donde
         * se va cuando algo va mal: es la última que puede caerse por una migración pendiente.
         *
         * Se construye a mano y NO con `->whereRaw('1 = 0')->cursorPaginate()`: eso también consulta
         * —lo intenté y seguía dando 500—, porque la consulta se ejecuta aunque no pueda devolver
         * nada. Aquí no se toca la base en absoluto.
         */
        if (! DbTable::existe('system_events')) {
            return new PaginadorVacio(collect(), 30, null, ['cursorName' => 'reg']);
        }

        return SystemEvent::query()
            ->with(['company', 'user'])
            ->when(request('empresa'), fn ($q, $id) => $q->where('company_id', $id))
            ->when(
                request('familia') && array_key_exists((string) request('familia'), self::FAMILIAS),
                // Por prefijo: `auth` trae `auth.login`, `auth.failed` y los que se añadan mañana sin
                // tener que tocar el filtro.
                fn ($q) => $q->where('type', 'like', request('familia').'.%'),
            )
            ->when(
                in_array(request('nivel'), [SystemEvent::INFO, SystemEvent::AVISO, SystemEvent::GRAVE], true),
                fn ($q) => $q->where('level', request('nivel')),
            )
            // Buscar por texto: sirve para «¿quién intentó entrar con este correo?», que es la
            // pregunta que se hace cuando algo huele mal.
            /*
             * Sin que importen las mayúsculas. Era un `like` a secas, y en PostgreSQL —la base de
             * este proyecto— eso distingue: buscar «error» en el registro no encontraba «Error».
             * Justo en la pantalla a la que se viene cuando algo va mal.
             */
            ->when(
                request('busca'),
                fn ($q, $texto) => $q->whereRaw('lower(message) like ?'.BusquedaTexto::ESCAPE, [BusquedaTexto::patron((string) $texto)]),
            )
            ->latest('created_at')
            ->cursorPaginate(30, ['*'], 'reg')
            ->withQueryString();
    }

    /**
     * Borra el rastro de más de un año.
     *
     * Va aquí y no en una tarea programada del sistema porque en producción no hay cron propio: se
     * llama desde el mismo sitio que las demás, con su clave compartida.
     */
    public function limpiar(): RedirectResponse
    {
        $limite = now()->subYear();

        $borradas = Audit::query()->where('created_at', '<', $limite)->delete()
            + (DbTable::existe('system_events')
                ? SystemEvent::query()->where('created_at', '<', $limite)->delete()
                : 0);

        return back()->with('panel_ok', $borradas > 0
            ? "Se borraron {$borradas} registros de más de un año."
            : 'No había nada de más de un año.');
    }
}
