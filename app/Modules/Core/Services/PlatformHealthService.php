<?php

declare(strict_types=1);

namespace App\Modules\Core\Services;

use App\Models\User;
use App\Modules\AI\Models\AiSetting;
use App\Modules\Core\Models\Company;
use App\Modules\Core\Models\ErrorEvent;
use App\Modules\Core\Models\PolarWebhookEvent;
use App\Modules\Core\Models\Subscription;
use App\Modules\Core\Models\SystemEvent;
use App\Modules\Core\Support\DbTable;
use App\Modules\Core\Support\PolarSignature;
use App\Modules\Core\Support\SubscriptionNotice;
use App\Modules\Core\Monitoring\Health\HealthAggregator;
use App\Modules\Core\Monitoring\Health\HealthStatus;
use App\Modules\WhatsApp\Enums\MessageStatus;
use App\Modules\WhatsApp\Models\WaMessage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * El estado de la plataforma entera, para el operador.
 *
 * TODAS las consultas van SIN el ámbito de empresa, y no es un descuido sino la pieza central: un
 * super administrador SIEMPRE tiene una empresa activa —`SetCurrentCompany` lo fija a la de su
 * sesión o, si no ha elegido, a la primera por id— así que un `Sale::count()` inocente devolvería
 * solo los de esa empresa, sin dar error y sin que nadie lo note mientras haya una sola empresa de
 * prueba. Es el fallo más caro de esta pantalla y por eso está escrito aquí arriba.
 *
 * Se cachea un minuto con clave `platform:`, no `company:`: con la convención de siempre, lo de la
 * plataforma se mezclaría con lo de la empresa que el operador tenga abierta.
 */
final class PlatformHealthService
{
    private const TTL = 60;

    public function __construct(private readonly HealthAggregator $salud) {}

    /**
     * @return array<string, mixed>
     */
    public function resumen(): array
    {
        return Cache::remember('platform:health', self::TTL, fn (): array => $this->calcular());
    }

    /**
     * @return array<string, mixed>
     */
    public function calcular(): array
    {
        $suscripciones = Subscription::query()->with(['company', 'plan'])->get();

        // «Por vencer» no se recalcula aquí: lo decide SubscriptionNotice, que es la fuente única
        // declarada y la que ya usan el banner y la ventana emergente del cliente. Dos criterios
        // distintos para lo mismo acabarían discrepando.
        $porVencer = $suscripciones
            ->map(fn (Subscription $s): ?array => ($aviso = SubscriptionNotice::for($s)) === null ? null : [
                'empresa' => $s->company?->name ?? '—',
                'nivel' => $aviso->level,
                'dias' => $aviso->days,
                'mensaje' => $aviso->message,
                'es_prueba' => $aviso->isTrial,
            ])
            ->filter()
            ->sortBy('dias')
            ->values()
            ->all();

        return [
            'empresas' => Company::query()->count(),
            'empresas_activas' => Company::query()->where('is_active', true)->count(),
            // `User` no lleva ámbito de empresa: el aislamiento lo hace a mano cada pantalla.
            'usuarios' => User::query()->where('is_super_admin', false)->count(),
            'bloqueadas' => $this->bloqueadas($suscripciones),
            'por_vencer' => $porVencer,
            'integraciones' => $this->integraciones(),
            // El estado de TODA la plataforma en una palabra (Fase 3): reutiliza el vocabulario de
            // `HealthStatus` (`unhealthy` es DOWN). Cacheado con el resto: no vale la pena recalcular
            // esto más a menudo que las demás cifras de este mismo resumen.
            'estado_general' => $this->salud->estado(),
        ];
    }

    /**
     * El pulso de las últimas 24 horas y la serie de los últimos catorce días.
     *
     * Es lo que convierte una lista en un monitoreo: un número suelto no dice nada —¿cuarenta
     * sucesos es mucho?—, pero al lado de los catorce días anteriores se ve solo. Un pico de accesos
     * fallidos el martes salta a la vista sin leer una sola fila.
     *
     * Sin caché, a diferencia del resumen: son cuatro conteos por índice y esta pantalla se abre
     * cuando algo va mal, que es justo cuando no se quiere un número de hace un minuto.
     *
     * Junto al pulso van los mismos cuatro conteos de las 24 horas ANTERIORES, en 'antes'. Sin ellos
     * no hay tendencia posible: un número solo no dice si la cosa mejora o empeora, y esta pantalla
     * existe para responder justo eso. Son cuatro conteos más por índice sobre la misma tabla.
     *
     * @return array{dia: array<string, int>, antes: array<string, int>, etiquetas: list<string>, normales: list<int>, problemas: list<int>}
     */
    public function pulso(): array
    {
        // Sin la tabla, el pulso sale en ceros y la pantalla se pinta igual. La alternativa era que
        // el monitoreo —la pantalla a la que se va cuando algo va mal— fuera lo primero en caerse.
        if (! DbTable::existe('system_events')) {
            return [
                'dia' => ['sucesos' => 0, 'problemas' => 0, 'accesos' => 0, 'fallidos' => 0, 'errores' => 0],
                'antes' => ['sucesos' => 0, 'problemas' => 0, 'accesos' => 0, 'fallidos' => 0],
                'etiquetas' => [], 'normales' => [], 'problemas' => [],
            ];
        }

        $desde = now()->subDay();

        $dia = [
            'sucesos' => SystemEvent::query()->where('created_at', '>=', $desde)->count(),
            'problemas' => SystemEvent::query()->where('created_at', '>=', $desde)
                ->whereIn('level', [SystemEvent::AVISO, SystemEvent::GRAVE])->count(),
            'accesos' => SystemEvent::query()->where('created_at', '>=', $desde)
                ->where('type', 'auth.login')->count(),
            'fallidos' => SystemEvent::query()->where('created_at', '>=', $desde)
                ->where('type', 'auth.failed')->count(),
            // `system_events` puede existir sin `error_events` todavía: son migraciones distintas.
            'errores' => DbTable::existe('error_events')
                ? ErrorEvent::query()->where('last_seen_at', '>=', $desde)->count()
                : 0,
        ];

        /*
         * Las 24 horas de antes de esas 24 horas, para poder decir si sube o baja.
         *
         * La ventana se cierra en $desde y no en «ayer a esta hora» calculado aparte: usar el mismo
         * límite para las dos garantiza que ningún suceso se cuente en las dos ventanas ni se caiga
         * entre ellas, que es el error clásico de comparar dos períodos.
         */
        $anterior = (clone $desde)->subDay();
        $enLaVentanaPrevia = fn (): Builder => SystemEvent::query()
            ->where('created_at', '>=', $anterior)
            ->where('created_at', '<', $desde);

        $antes = [
            'sucesos' => $enLaVentanaPrevia()->count(),
            'problemas' => $enLaVentanaPrevia()
                ->whereIn('level', [SystemEvent::AVISO, SystemEvent::GRAVE])->count(),
            'accesos' => $enLaVentanaPrevia()->where('type', 'auth.login')->count(),
            'fallidos' => $enLaVentanaPrevia()->where('type', 'auth.failed')->count(),
        ];

        /*
         * La serie se arma en PHP a partir de UNA consulta agrupada, y no con catorce.
         *
         * Y se rellenan los días vacíos: sin eso, una semana tranquila dibujaría una gráfica con
         * menos barras y las fechas se leerían corridas, que es peor que no tener gráfica.
         */
        $filas = SystemEvent::query()
            ->where('created_at', '>=', now()->subDays(13)->startOfDay())
            ->get(['created_at', 'level'])
            ->groupBy(fn (SystemEvent $e): string => $e->created_at->toDateString());

        $etiquetas = [];
        $normales = [];
        $problemas = [];

        foreach (range(13, 0) as $atras) {
            $fecha = now()->subDays($atras)->toDateString();
            $delDia = $filas->get($fecha, collect());

            $etiquetas[] = $fecha;
            $problemas[] = $delDia->whereIn('level', [SystemEvent::AVISO, SystemEvent::GRAVE])->count();
            $normales[] = $delDia->where('level', SystemEvent::INFO)->count();
        }

        return compact('dia', 'antes', 'etiquetas', 'normales', 'problemas');
    }

    /**
     * Empresas que no pueden operar.
     *
     * Mismo criterio, literal, que la pantalla de suspensión: sin él, el panel diría que todo está
     * bien mientras el cliente ve la puerta cerrada.
     *
     * @param  Collection<int, Subscription>  $suscripciones
     */
    private function bloqueadas(Collection $suscripciones): int
    {
        $porEmpresa = $suscripciones->keyBy('company_id');

        return Company::query()->get(['id', 'is_active'])
            ->filter(function (Company $empresa) use ($porEmpresa): bool {
                $suscripcion = $porEmpresa->get($empresa->id);

                return ! $empresa->is_active || ($suscripcion !== null && ! $suscripcion->isUsable());
            })
            ->count();
    }

    /**
     * El estado de cada servicio externo.
     *
     * Desde la Fase 3, «polar», «ia» y «whatsapp» consultan `health_checks` —lo que de verdad
     * respondió la última vez, no solo si hay una clave puesta—; antes de esta fase (o antes de que
     * se aplique su migración) caen al criterio de solo-configuración que había, sin romperse.
     * «redes» (Zernio) sigue igual: no tiene sonda propia en esta fase, y contar empresas sin
     * conectar es una pregunta de configuración, no de disponibilidad.
     *
     * @return array<int, array{clave: string, nombre: string, estado: string, detalle: string}>
     */
    private function integraciones(): array
    {
        $salud = DbTable::existe('health_checks')
            ? DB::table('health_checks')->get()->keyBy('service')
            : collect();

        $sinRedes = Company::query()->whereNull('social_api_key')->count();

        return [
            $this->integracionPolar($salud->get('polar')),
            $this->integracionIa($salud->get('ai')),
            $this->integracionWhatsapp($salud->get('evolution')),
            [
                'clave' => 'redes',
                'nombre' => 'Redes sociales',
                'estado' => $sinRedes > 0 ? 'aviso' : 'bien',
                'detalle' => $sinRedes > 0
                    ? $sinRedes.' '.($sinRedes === 1 ? 'empresa sin conectar' : 'empresas sin conectar')
                    : 'todas conectadas',
            ],
        ];
    }

    /**
     * @return array{clave: string, nombre: string, estado: string, detalle: string}
     */
    private function integracionPolar(?object $fila): array
    {
        $sinResolver = PolarWebhookEvent::query()
            ->where('result', PolarWebhookEvent::RESULT_UNRESOLVED)->count();

        $detalleAvisos = $sinResolver > 0
            ? $sinResolver.' '.($sinResolver === 1 ? 'aviso sin resolver' : 'avisos sin resolver')
            : 'sin avisos pendientes';

        if ($fila === null || ! $fila->configured) {
            return [
                'clave' => 'polar', 'nombre' => 'Cobros (Polar)',
                'estado' => PolarSignature::fromConfig()->isConfigured() ? 'bien' : 'apagado',
                'detalle' => $detalleAvisos,
            ];
        }

        $tono = $this->tono($fila->status);

        // Un aviso de cobro sin resolver PIDE ATENCIÓN aunque Polar responda perfectamente: no es lo
        // mismo «el servicio funciona» que «no hay nada pendiente en él».
        if ($tono === 'bien' && $sinResolver > 0) {
            $tono = 'aviso';
        }

        return [
            'clave' => 'polar', 'nombre' => 'Cobros (Polar)', 'estado' => $tono,
            'detalle' => $tono === 'bien' ? $detalleAvisos : ($fila->message ?? $detalleAvisos),
        ];
    }

    /**
     * @return array{clave: string, nombre: string, estado: string, detalle: string}
     */
    private function integracionIa(?object $fila): array
    {
        $ajustesIa = AiSetting::query()->first();

        if ($fila === null || ! $fila->configured) {
            return [
                'clave' => 'ia', 'nombre' => 'Inteligencia Artificial',
                'estado' => $ajustesIa?->configurado() ? 'bien' : 'apagado',
                'detalle' => $ajustesIa?->configurado() ? (string) $ajustesIa->provider : 'sin clave: el asistente no redacta',
            ];
        }

        $tono = $this->tono($fila->status);

        return [
            'clave' => 'ia', 'nombre' => 'Inteligencia Artificial', 'estado' => $tono,
            'detalle' => $tono === 'bien' ? (string) $ajustesIa?->provider : ($fila->message ?? (string) $ajustesIa?->provider),
        ];
    }

    /**
     * @return array{clave: string, nombre: string, estado: string, detalle: string}
     */
    private function integracionWhatsapp(?object $filaEvolution): array
    {
        $fallidosHoy = WaMessage::query()->withoutGlobalScopes()
            ->where('status', MessageStatus::Failed)
            ->where('created_at', '>=', now()->subDay())
            ->count();

        // Un mensaje `pending` desde hace más de un cuarto de hora no está «en camino»: algo lo dejó
        // a medias (la cola, el gateway) y nadie lo está mirando.
        $atascados = WaMessage::query()->withoutGlobalScopes()
            ->where('status', MessageStatus::Pending)
            ->where('created_at', '<', now()->subMinutes(15))
            ->count();

        $partes = array_filter([
            $fallidosHoy > 0 ? $fallidosHoy.' '.($fallidosHoy === 1 ? 'mensaje no salió hoy' : 'mensajes no salieron hoy') : null,
            $atascados > 0 ? $atascados.' '.($atascados === 1 ? 'atascado' : 'atascados') : null,
        ]);

        $detalle = $partes === [] ? 'sin mensajes fallidos' : implode(', ', $partes);

        // Solo se mira la conectividad de Evolution cuando de verdad hay una empresa con línea que
        // dependa de ella: una instalación que solo usa la vía oficial (Zernio) no tiene por qué
        // verse «apagada» solo porque nadie usa Evolution.
        $tonoConectividad = ($filaEvolution !== null && $filaEvolution->configured)
            ? $this->tono($filaEvolution->status)
            : null;

        $estado = match (true) {
            $tonoConectividad === 'grave' => 'grave',
            $partes !== [] || $tonoConectividad === 'aviso' => 'aviso',
            default => 'bien',
        };

        return ['clave' => 'whatsapp', 'nombre' => 'WhatsApp', 'estado' => $estado, 'detalle' => $detalle];
    }

    /** `HealthStatus` (de una sonda) al vocabulario de tono que ya usa esta pantalla. */
    private function tono(string $estadoDeSalud): string
    {
        return match ($estadoDeSalud) {
            HealthStatus::HEALTHY => 'bien',
            HealthStatus::DEGRADED => 'aviso',
            HealthStatus::UNHEALTHY => 'grave',
            default => 'apagado',
        };
    }

    /** Los avisos de Polar que nadie ha resuelto, con su motivo. */
    public function webhooksSinResolver(int $limite = 10): Collection
    {
        return PolarWebhookEvent::query()
            ->where('result', PolarWebhookEvent::RESULT_UNRESOLVED)
            ->latest('id')
            ->limit($limite)
            ->get(['id', 'type', 'note', 'company_id', 'created_at']);
    }
}
