<?php

declare(strict_types=1);

namespace App\Modules\Core\Monitoring\Counters;

use App\Modules\Core\Models\ErrorEvent;
use App\Modules\Core\Models\Incident;
use App\Modules\Core\Models\PolarWebhookEvent;
use App\Modules\Core\Models\SystemEvent;
use App\Modules\Core\Monitoring\MonitoringSchema;
use App\Modules\Core\Services\CompanyHealthService;
use App\Modules\Core\Services\PlatformHealthService;
use App\Modules\Core\Support\DbTable;
use Illuminate\Support\Collection;

/**
 * Cuántas cosas piden atención, de verdad.
 *
 * Los contadores que había ANTES no contaban: sumaban `->count()` de listas ya recortadas para
 * pintarse —15 errores, 10 webhooks— así que «3 errores» podía significar «3» o podía significar «15
 * y ninguno más cabía en la lista», y no había forma de distinguirlos desde la pantalla. Aquí cada
 * número sale de una consulta agregada sobre la tabla entera, nunca de contar lo que ya se recortó
 * para mostrar.
 *
 * Todo lo que puede faltar por migrar se pregunta antes ({@see MonitoringSchema}): sin la tabla o la
 * columna nueva, el contador correspondiente sale en cero en vez de romper la pantalla.
 */
final class MonitoringCounters
{
    /**
     * Un aviso de Polar «recibido» que sigue sin aplicarse pasado este tiempo es tan accionable como
     * uno «sin resolver»: algo se quedó a medias y nadie lo está mirando.
     */
    private const MINUTOS_RECIBIDO_ATASCADO = 5;

    public function __construct(
        private readonly CompanyHealthService $empresas,
        private readonly PlatformHealthService $plataforma,
    ) {}

    /**
     * @return array{
     *     errores_activos: int, avisos_graves_24h: int, webhooks_pendientes: int,
     *     empresas_con_problemas: int, servicios_con_aviso: int, empresas_bloqueadas: int,
     *     incidentes_activos: int, pendientes: int,
     * }
     */
    public function calcular(): array
    {
        $erroresActivos = $this->erroresActivos();
        $webhooksPendientes = $this->webhooksPendientes();
        $serviciosConAviso = $this->serviciosConAviso();
        $empresasBloqueadas = $this->plataforma->resumen()['bloqueadas'];

        return [
            'errores_activos' => $erroresActivos,
            'avisos_graves_24h' => $this->avisosGraves24h(),
            'webhooks_pendientes' => $webhooksPendientes,
            'empresas_con_problemas' => $this->empresas->conAviso(),
            'servicios_con_aviso' => $serviciosConAviso,
            'empresas_bloqueadas' => $empresasBloqueadas,
            'incidentes_activos' => $this->incidentesActivos(),
            /*
             * El titular de la pantalla. Las mismas cuatro familias de antes —integraciones con
             * aviso, empresas bloqueadas, errores, webhooks—, pero contadas de verdad. Las empresas
             * con un problema de negocio (sin almacén, caja abierta…) tienen su propia tarjeta y no
             * entran aquí: esto es lo que le toca resolver AL OPERADOR de la plataforma, no lo que
             * cada cliente tiene pendiente en su propio negocio.
             *
             * Los incidentes NO se suman aquí a propósito: casi todo incidente activo nace de un
             * grupo de error que YA está contado en `errores_activos`, y sumar los dos doblaría la
             * misma cosa. Un incidente es una forma más grave de mirar el mismo error, no una cosa
             * aparte que además pida atención.
             */
            'pendientes' => $erroresActivos + $webhooksPendientes + $serviciosConAviso + $empresasBloqueadas,
        ];
    }

    private function incidentesActivos(): int
    {
        if (! DbTable::existe('incidents')) {
            return 0;
        }

        return Incident::query()->activos()->count();
    }

    private function erroresActivos(): int
    {
        if (! MonitoringSchema::hayErrores()) {
            return 0;
        }

        // Sin el desglose no hay columna `status`: todo grupo cuenta como activo, que es lo que era
        // cierto hasta ahora.
        return MonitoringSchema::erroresConDesglose()
            ? ErrorEvent::query()->activos()->count()
            : ErrorEvent::query()->count();
    }

    private function avisosGraves24h(): int
    {
        if (! MonitoringSchema::haySucesos()) {
            return 0;
        }

        return SystemEvent::query()
            ->where('created_at', '>=', now()->subDay())
            ->whereIn('level', [SystemEvent::AVISO, SystemEvent::GRAVE])
            ->count();
    }

    /**
     * `unresolved` es el aviso que alguien tiene que decidir; un `received` de hace rato es uno que se
     * quedó a medio procesar. Los dos piden que alguien mire.
     */
    private function webhooksPendientes(): int
    {
        return PolarWebhookEvent::query()
            ->where(function ($q): void {
                $q->where('result', PolarWebhookEvent::RESULT_UNRESOLVED)
                    ->orWhere(function ($q2): void {
                        $q2->where('result', 'received')
                            ->where('created_at', '<', now()->subMinutes(self::MINUTOS_RECIBIDO_ATASCADO));
                    });
            })
            ->count();
    }

    private function serviciosConAviso(): int
    {
        /** @var Collection<int, array{estado: string}> $integraciones */
        $integraciones = collect($this->plataforma->resumen()['integraciones']);

        return $integraciones->where('estado', 'aviso')->count();
    }
}
