<?php

declare(strict_types=1);

namespace App\Modules\Core\Http\Middleware;

use App\Modules\Core\Monitoring\Metrics\MetricsRecorder;
use App\Modules\Core\Monitoring\Metrics\ModuleResolver;
use App\Modules\Core\Monitoring\Metrics\Observation;
use App\Modules\Core\Support\TenantAttribution;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Cuánto tarda cada petición, para la pestaña «Rendimiento» (Fase 5).
 *
 * TERMINABLE a propósito: `handle()` no hace nada, y todo el trabajo pasa en `terminate()`, que
 * Laravel llama DESPUÉS de que la respuesta ya salió al navegador. Medir aquí no añade ni un
 * milisegundo a lo que el usuario espera.
 *
 * Global (`$middleware->append`, en `bootstrap/app.php`): cubre web y API por igual, que es lo que
 * hace falta para saber si lo lento es el panel o la API pública.
 *
 * Muestreo por estratos: un 5xx o una petición lenta SIEMPRE se guarda (son la señal que importa);
 * el resto, 1 de cada N, con un peso que representa a las N que no se guardaron —así el total sigue
 * siendo el total real, sin escribir una fila por cada petición normal.
 */
final class RecordRequestMetrics
{
    private const RUTAS_EXCLUIDAS = ['up'];

    private const EXTENSIONES_ESTATICAS = '/\.(css|js|map|png|jpe?g|gif|svg|ico|woff2?|ttf|eot)$/i';

    public function __construct(
        private readonly MetricsRecorder $metricas,
        private readonly ModuleResolver $modulos,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        if (! (bool) config('bmos.monitoreo.metricas_http.activo', true)) {
            return;
        }

        $ruta = $request->route();

        if ($this->seExcluye($request, $ruta)) {
            return;
        }

        $duracionMs = (microtime(true) - $this->inicioDeLaPeticion()) * 1000;
        $status = $response->getStatusCode();
        $esError = $status >= 500;
        $esLenta = $duracionMs >= (int) config('bmos.monitoreo.metricas_http.lento_ms', 1000);

        $peso = 1;

        if (! $esError && ! $esLenta) {
            $unoDeCada = max(1, (int) config('bmos.monitoreo.metricas_http.uno_de_cada', 5));

            if (random_int(1, $unoDeCada) !== 1) {
                return;
            }

            $peso = $unoDeCada;
        }

        $endpoint = $ruta?->getName() ?? $ruta?->uri() ?? 'unmatched';

        try {
            $this->metricas->anotar(
                kind: Observation::HTTP,
                name: $endpoint,
                method: $request->method(),
                module: $this->modulos->resolver($ruta),
                companyId: TenantAttribution::companyId(),
                durationMs: $duracionMs,
                isWarning: $esLenta,
                isError: $esError,
                weight: $peso,
            );
        } catch (Throwable) {
            // Ver cabecera de DatabaseSink: una métrica de más no puede tumbar la petición.
        }
    }

    /**
     * `LARAVEL_START` solo existe cuando la app arrancó por `public/index.php` (la entrada real, en
     * producción y en local). En tests la app arranca de otra forma y la constante no está definida.
     */
    private function inicioDeLaPeticion(): float
    {
        if (defined('LARAVEL_START')) {
            return (float) LARAVEL_START;
        }

        return (float) ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true));
    }

    private function seExcluye(Request $request, ?Route $ruta): bool
    {
        // La ruta de salud de Laravel (`health: '/up'` en bootstrap/app.php) no tiene NOMBRE —se
        // registra con un closure sin `->name()`—, así que se compara por URI, no por `getName()`.
        if ($ruta !== null && in_array($ruta->uri(), self::RUTAS_EXCLUIDAS, true)) {
            return true;
        }

        return preg_match(self::EXTENSIONES_ESTATICAS, $request->getPathInfo()) === 1;
    }
}
