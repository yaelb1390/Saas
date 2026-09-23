<?php

declare(strict_types=1);

namespace App\Modules\Core\Monitoring\Incidents;

use App\Modules\Core\Models\Incident;
use App\Modules\Core\Models\IncidentCompany;
use App\Modules\Core\Models\IncidentLink;
use App\Modules\Core\Models\SystemEvent;
use App\Modules\Core\Monitoring\Search\MonitoringFilters;
use App\Modules\Core\Support\BusquedaTexto;
use App\Modules\Core\Support\DbTable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\CursorPaginator as PaginadorVacio;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Abre, actualiza y lista incidentes.
 *
 * «Abrir» no es siempre crear una fila nueva: mientras un incidente con la misma `dedupe_key` siga
 * ACTIVO, lo que llega se le SUMA (una ocurrencia más, la fecha de detección al día, la empresa que
 * lo sufrió); solo se crea uno nuevo si no hay ninguno activo con esa clave. Es el mismo patrón que
 * `ErrorRecorder` con los grupos de error: UPDATE primero, INSERT solo si no había nada que sumar.
 *
 * Por qué así y no al revés (leer, decidir, escribir): dos detecciones casi a la vez —dos peticiones
 * del mismo error reventando a la vez— crearían dos incidentes para el mismo problema si cada una
 * decidiera por su cuenta. El UPDATE primero hace que la segunda encuentre lo que la primera acaba
 * de dejar y se sume a ello en vez de duplicar.
 */
final class IncidentService
{
    public const ACCIONES = [
        'investigate' => Incident::INVESTIGATING,
        'resolve' => Incident::RESOLVED,
        'ignore' => Incident::IGNORED,
        'reopen' => Incident::OPEN,
    ];

    private const REINTENTOS_CODIGO = 3;

    public const POR_PAGINA = 25;

    /**
     * Abre un incidente por la clave dada, o lo actualiza si ya hay uno activo con ella.
     *
     * @param  string  $dedupeKey  «error:{huella}» o «health:{servicio}» (Fase 3). Lo que decide si
     *                             esto es «lo mismo de antes» o «algo nuevo».
     * @param  list<int>  $companyIds  Empresas a las que atribuir esta ocurrencia.
     */
    public function detectarOAbrir(
        string $dedupeKey,
        string $titulo,
        ?string $servicio,
        string $severidad,
        string $sourceType,
        int $sourceId,
        array $companyIds,
    ): Incident {
        $ahora = now();

        $incidente = $this->sumarOAbrir($dedupeKey, $titulo, $servicio, $severidad, $ahora);

        $this->enlazar($incidente, $sourceType, $sourceId);
        $this->sumarEmpresas($incidente, $companyIds, $ahora);

        return $incidente->refresh();
    }

    /**
     * Cambia el estado de un incidente: lo investiga, lo resuelve, lo ignora o lo reabre.
     */
    public function cambiarEstado(Incident $incidente, string $accion, ?int $userId): void
    {
        $estado = self::ACCIONES[$accion];

        $datos = ['status' => $estado];

        if (in_array($accion, ['resolve', 'ignore'], true)) {
            $datos += ['resolved_at' => now(), 'resolved_by' => $userId];
        } else {
            // Investigar o reabrir: si venía de resuelto/ignorado, ya no lo está.
            $datos += ['resolved_at' => null, 'resolved_by' => null];
        }

        $incidente->update($datos);

        SystemEvent::registrar(
            type: 'incident.status_changed',
            message: "Incidente {$incidente->code}: ".match ($accion) {
                'investigate' => 'en investigación',
                'resolve' => 'resuelto',
                'ignore' => 'ignorado',
                default => 'reabierto',
            },
            contexto: ['incidente_id' => $incidente->id, 'accion' => $accion],
            level: SystemEvent::INFO,
        );
    }

    /**
     * La lista de incidentes de la pestaña, con sus filtros.
     *
     * @return CursorPaginator<int, Incident>
     */
    public function listar(MonitoringFilters $f): CursorPaginator
    {
        // Las migraciones de esta fase se aplican a mano: entre que sale el código y alguien migra,
        // la pestaña tiene que pintarse vacía, no caerse con un 500 (es la pantalla a la que se va
        // cuando algo va mal).
        if (! DbTable::existe('incidents')) {
            return new PaginadorVacio(collect(), self::POR_PAGINA, null, ['cursorName' => 'inc']);
        }

        $consulta = Incident::query();

        match (true) {
            $f->estado === 'active' => $consulta->activos(),
            $f->estado === 'todos' => null,
            in_array($f->estado, [Incident::OPEN, Incident::INVESTIGATING, Incident::RESOLVED, Incident::IGNORED], true)
                => $consulta->where('status', $f->estado),
            default => $consulta->activos(),
        };

        if ($f->severidad !== null) {
            $consulta->where('severity', $f->severidad);
        }

        if ($f->servicio !== null) {
            $consulta->where('service', $f->servicio);
        }

        if ($f->empresa !== null) {
            $consulta->whereIn('id', DB::table('incident_companies')->select('incident_id')
                ->where('company_id', $f->empresa));
        }

        if ($f->ventana !== null) {
            $consulta->where('last_detected_at', '>=', now()->subDays($f->ventana));
        }

        if ($f->busca !== null) {
            $patron = BusquedaTexto::patron($f->busca);

            $consulta->where(function (Builder $q) use ($patron): void {
                $q->orWhereRaw('lower(code) like ?'.BusquedaTexto::ESCAPE, [$patron])
                    ->orWhereRaw('lower(title) like ?'.BusquedaTexto::ESCAPE, [$patron]);
            });
        }

        return $consulta
            ->orderByDesc('last_detected_at')
            ->orderByDesc('id')
            ->cursorPaginate(self::POR_PAGINA, ['*'], 'inc')
            ->withQueryString();
    }

    private function sumarOAbrir(string $dedupeKey, string $titulo, ?string $servicio, string $severidad, CarbonInterface $ahora): Incident
    {
        $sumadas = Incident::query()->activos()->where('dedupe_key', $dedupeKey)
            ->update(['occurrences' => DB::raw('occurrences + 1'), 'last_detected_at' => $ahora, 'updated_at' => $ahora]);

        if ($sumadas > 0) {
            return Incident::query()->activos()->where('dedupe_key', $dedupeKey)->firstOrFail();
        }

        try {
            return $this->crear($dedupeKey, $titulo, $servicio, $severidad, $ahora);
        } catch (Throwable) {
            // Otra petición lo creó entre el UPDATE y el INSERT: se suma a la suya, igual que
            // `ErrorRecorder` con los grupos de error.
            Incident::query()->activos()->where('dedupe_key', $dedupeKey)
                ->update(['occurrences' => DB::raw('occurrences + 1'), 'last_detected_at' => $ahora]);

            return Incident::query()->activos()->where('dedupe_key', $dedupeKey)->firstOrFail();
        }
    }

    /**
     * Crea el incidente con su código `INC-{año}-{número}`. Reintenta si dos se crean en el mismo
     * año a la vez y chocan por el número: cada intento vuelve a preguntar el máximo, así que el
     * segundo ve ya el del primero.
     */
    private function crear(string $dedupeKey, string $titulo, ?string $servicio, string $severidad, CarbonInterface $ahora): Incident
    {
        for ($intento = 1; $intento <= self::REINTENTOS_CODIGO; $intento++) {
            try {
                return DB::transaction(function () use ($dedupeKey, $titulo, $servicio, $severidad, $ahora): Incident {
                    $year = (int) $ahora->format('Y');
                    $seq = (int) Incident::query()->where('year', $year)->max('seq') + 1;

                    $incidente = Incident::query()->create([
                        'code' => sprintf('INC-%d-%04d', $year, $seq),
                        'year' => $year,
                        'seq' => $seq,
                        'title' => Str::limit($titulo, 195, ''),
                        'service' => $servicio,
                        'severity' => $severidad,
                        'status' => Incident::OPEN,
                        'started_at' => $ahora,
                        'last_detected_at' => $ahora,
                        'occurrences' => 1,
                        'companies_count' => 0,
                        'dedupe_key' => $dedupeKey,
                        'source' => Incident::FUENTE_AUTO,
                    ]);

                    SystemEvent::registrar(
                        type: 'incident.opened',
                        message: "Incidente {$incidente->code} abierto: {$incidente->title}",
                        contexto: ['incidente_id' => $incidente->id, 'servicio' => $servicio, 'severidad' => $severidad],
                        level: SystemEvent::GRAVE,
                    );

                    return $incidente;
                });
            } catch (Throwable $e) {
                if ($intento === self::REINTENTOS_CODIGO) {
                    throw $e;
                }
            }
        }

        throw new \RuntimeException('No se pudo abrir el incidente.'); // inalcanzable
    }

    private function enlazar(Incident $incidente, string $sourceType, int $sourceId): void
    {
        try {
            IncidentLink::query()->firstOrCreate([
                'incident_id' => $incidente->id,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
            ]);
        } catch (Throwable) {
            // Ya enlazado por otra petición a la vez: no es un error, es el mismo dato dos veces.
        }
    }

    /**
     * @param  list<int>  $companyIds
     */
    private function sumarEmpresas(Incident $incidente, array $companyIds, CarbonInterface $ahora): void
    {
        foreach (array_unique($companyIds) as $companyId) {
            $sumadas = IncidentCompany::query()
                ->where('incident_id', $incidente->id)->where('company_id', $companyId)
                ->update(['hits' => DB::raw('hits + 1'), 'last_seen_at' => $ahora]);

            if ($sumadas > 0) {
                continue;
            }

            try {
                IncidentCompany::query()->create([
                    'incident_id' => $incidente->id, 'company_id' => $companyId,
                    'hits' => 1, 'first_seen_at' => $ahora, 'last_seen_at' => $ahora,
                ]);

                $incidente->increment('companies_count');
            } catch (Throwable) {
                IncidentCompany::query()
                    ->where('incident_id', $incidente->id)->where('company_id', $companyId)
                    ->update(['hits' => DB::raw('hits + 1'), 'last_seen_at' => $ahora]);
            }
        }
    }
}
