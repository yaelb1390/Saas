<?php

declare(strict_types=1);

namespace App\Modules\Core\Services;

use App\Models\User;
use App\Modules\Cash\Models\CashSession;
use App\Modules\Core\DTOs\CompanyHealthCard;
use App\Modules\Core\DTOs\CompanyProblem;
use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\Company;
use App\Modules\Core\Models\Warehouse;
use App\Modules\Core\Monitoring\Metrics\Percentiles;
use App\Modules\Core\Support\DbTable;
use App\Modules\Inventory\Models\Product;
use App\Modules\Sales\Enums\SaleStatus;
use App\Modules\Sales\Models\Sale;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Cómo le va a CADA empresa creada.
 *
 * `PlatformHealthService` responde por la plataforma: cuántas empresas hay, qué integraciones están
 * caídas, cuántos sucesos hubo hoy. Eso no dice si a un cliente concreto le va bien, y era todo lo
 * que había. Para saber si una empresa está en problemas había que ir a mirar sus datos uno por uno,
 * y para enterarse de que se estaba yendo había que esperar a que cancelara.
 *
 * Aquí van las señales POR EMPRESA, agrupadas en cuatro familias:
 *
 *   · Lo que le impide vender HOY —sin almacén, sin NCF, caja sin cerrar, sin productos—.
 *   · Si está dejando de usarlo —última venta, último acceso, las que nunca arrancaron—.
 *   · Si se pasó de su plan —usuarios y sucursales—.
 *   · Cómo opera —descuadres de caja, el bot sin configurar, precios en cero—.
 *
 * TODAS LAS CONSULTAS VAN SIN EL ÁMBITO DE EMPRESA, igual que en el monitoreo de al lado: un super
 * administrador siempre tiene una empresa activa, así que con el ámbito puesto esta pantalla
 * enseñaría los datos de una sola haciéndolos pasar por los de todas, y sin dar error.
 */
final class CompanyHealthService
{
    /** Lo mismo que el otro servicio de salud: un minuto es fresco de sobra para esto. */
    private const TTL = 60;

    /** Una caja abierta más de esto es un turno que nadie cerró, no una jornada larga. */
    private const HORAS_CAJA_ABIERTA = 24;

    /** Antes de una semana, «no ha vendido» es que acaba de empezar, no que se esté yendo. */
    private const DIAS_PARA_ARRANCAR = 7;

    /** Sin vender este tiempo, un negocio que estaba activo ha dejado de usarlo. */
    private const DIAS_SIN_VENDER = 14;

    /**
     * El estado de cada empresa, ya resuelto.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function porEmpresa(): Collection
    {
        /** @var array<int, array<string, mixed>> $filas */
        $filas = cache()->remember('platform:empresas', self::TTL, fn (): array => $this->calcular());

        return collect($filas);
    }

    /**
     * Las señales que hacen que una empresa cuente como «con problemas». Un `array_filter` a mano por
     * cada llamador sería repetir esta misma lista de ocho campos y dos umbrales en cada sitio que
     * necesite el mismo criterio, y que divergieran sería el bug más silencioso posible: dos pantallas
     * de acuerdo hoy y en desacuerdo dentro de un mes sin que nadie lo note.
     */
    private const SEÑALES_BOOLEANAS = [
        'sin_almacen', 'sin_ncf', 'caja_abierta', 'sin_productos',
        'nunca_vendio', 'sin_vender', 'pasada_de_plan', 'bot_sin_info',
    ];

    /**
     * Cuántas empresas tienen AL MENOS UNA señal de problema.
     *
     * Es distinto de sumar `resumenDeAvisos()`: una empresa con tres señales a la vez contaría tres
     * veces ahí, y el titular de la pantalla («N empresas piden atención») necesita empresas, no
     * señales.
     */
    public function conAviso(): int
    {
        return $this->porEmpresa()
            ->filter(fn (array $e): bool => $this->tieneAlgunaSeñal($e))
            ->count();
    }

    /**
     * @param  array<string, mixed>  $empresa  una fila de {@see porEmpresa()}
     */
    private function tieneAlgunaSeñal(array $empresa): bool
    {
        foreach (self::SEÑALES_BOOLEANAS as $señal) {
            if ($empresa[$señal] === true) {
                return true;
            }
        }

        return $empresa['descuadres'] > 0 || $empresa['sin_precio'] > 0;
    }

    /**
     * Cuántas empresas tiene cada problema, para las tarjetas de arriba.
     *
     * @return array<string, int>
     */
    public function resumenDeAvisos(): array
    {
        $empresas = $this->porEmpresa();

        return [
            'sin_almacen' => $empresas->where('sin_almacen', true)->count(),
            'sin_ncf' => $empresas->where('sin_ncf', true)->count(),
            'caja_abierta' => $empresas->where('caja_abierta', true)->count(),
            'sin_productos' => $empresas->where('sin_productos', true)->count(),
            'nunca_vendio' => $empresas->where('nunca_vendio', true)->count(),
            'sin_vender' => $empresas->where('sin_vender', true)->count(),
            'pasada_de_plan' => $empresas->where('pasada_de_plan', true)->count(),
            'bot_sin_info' => $empresas->where('bot_sin_info', true)->count(),
            'descuadres' => $empresas->where('descuadres', '>', 0)->count(),
            'sin_precio' => $empresas->where('sin_precio', '>', 0)->count(),
        ];
    }

    /**
     * Una consulta AGRUPADA por señal, no una por empresa.
     *
     * Con diez señales y treinta empresas, preguntar una a una serían trescientas consultas en la
     * pantalla que se abre justo cuando algo va mal. Cada método de abajo devuelve un mapa
     * «empresa => dato» de un solo golpe y aquí se juntan en memoria.
     *
     * @return array<int, array<string, mixed>>
     */
    private function calcular(): array
    {
        $empresas = Company::query()->orderBy('name')->get(['id', 'name', 'is_active', 'created_at']);

        $conAlmacen = $this->conAlmacenPorOmision();
        $sinNcf = $this->sinNcfUtilizable();
        $cajaVieja = $this->cajaAbiertaDemasiado();
        $productos = $this->productosActivos();
        $sinPrecio = $this->productosSinPrecio();
        $ultimaVenta = $this->ultimaVenta();
        $ultimoAcceso = $this->ultimoAcceso();
        $usuarios = $this->usuarios();
        $sucursales = $this->sucursales();
        $limites = $this->limitesDelPlan();
        $descuadres = $this->descuadresDeCaja();
        $botSinInfo = $this->botEncendidoSinInformacion();

        return $empresas->map(function (Company $empresa) use (
            $conAlmacen, $sinNcf, $cajaVieja, $productos, $sinPrecio, $ultimaVenta,
            $ultimoAcceso, $usuarios, $sucursales, $limites, $descuadres, $botSinInfo,
        ): array {
            $id = (int) $empresa->id;
            $venta = $ultimaVenta[$id] ?? null;
            $limite = $limites[$id] ?? ['usuarios' => null, 'sucursales' => null, 'plan' => null];

            $misUsuarios = $usuarios[$id] ?? 0;
            $misSucursales = $sucursales[$id] ?? 0;

            $pasadaUsuarios = $limite['usuarios'] !== null && $misUsuarios > $limite['usuarios'];
            $pasadaSucursales = $limite['sucursales'] !== null && $misSucursales > $limite['sucursales'];

            /*
             * «Nunca vendió» y «dejó de vender» son cosas distintas y no deben mezclarse.
             *
             * Una empresa creada anteayer sin ventas está empezando; una de hace un mes sin ninguna
             * no arrancó nunca. Y una que vendía y lleva dos semanas parada es la que se está yendo.
             */
            $reciente = $empresa->created_at !== null
                && $empresa->created_at->gt(now()->subDays(self::DIAS_PARA_ARRANCAR));

            return [
                'id' => $id,
                'nombre' => (string) $empresa->name,
                'activa' => (bool) $empresa->is_active,
                'plan' => $limite['plan'],
                'creada' => $empresa->created_at,

                // 1. Lo que le impide vender hoy.
                'sin_almacen' => ! ($conAlmacen[$id] ?? false),
                'sin_ncf' => $sinNcf[$id] ?? false,
                'caja_abierta' => $cajaVieja[$id] ?? false,
                'sin_productos' => ($productos[$id] ?? 0) === 0,

                // 2. Si está dejando de usarlo.
                'ultima_venta' => $venta,
                'ultimo_acceso' => $ultimoAcceso[$id] ?? null,
                'nunca_vendio' => $venta === null && ! $reciente,
                'sin_vender' => $venta !== null && $venta->lt(now()->subDays(self::DIAS_SIN_VENDER)),

                // 3. Si se pasó de su plan.
                'usuarios' => $misUsuarios,
                'sucursales' => $misSucursales,
                'limite_usuarios' => $limite['usuarios'],
                'limite_sucursales' => $limite['sucursales'],
                'pasada_de_plan' => $pasadaUsuarios || $pasadaSucursales,

                // 4. Cómo opera.
                'descuadres' => $descuadres[$id] ?? 0,
                'sin_precio' => $sinPrecio[$id] ?? 0,
                'bot_sin_info' => $botSinInfo[$id] ?? false,
            ];
        })->all();
    }

    /**
     * Qué empresas tienen almacén por omisión.
     *
     * Sin él, el cobro del punto de venta y el mostrador de repuestos caen con «No hay un almacén
     * configurado». No aparece en ninguna pantalla hasta que falla con un cliente delante.
     *
     * @return array<int, bool>
     */
    private function conAlmacenPorOmision(): array
    {
        return Warehouse::query()->withoutGlobalScopes()
            ->where('is_default', true)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->distinct()
            ->pluck('company_id')
            ->mapWithKeys(fn ($id): array => [(int) $id => true])
            ->all();
    }

    /**
     * Qué empresas se quedaron sin comprobantes fiscales que emitir.
     *
     * Una secuencia agotada o vencida deja al negocio sin poder facturar. Se calcula con las mismas
     * reglas del modelo —`hasAvailableNumbers()` e `isExpired()`— pero en SQL, para no traerse todas
     * las secuencias de todas las empresas a memoria.
     *
     * Solo se marca a quien TIENE secuencias: una empresa que nunca facturó con NCF no está rota, es
     * que no usa ese módulo.
     *
     * @return array<int, bool>
     */
    private function sinNcfUtilizable(): array
    {
        if (! DbTable::existe('fiscal_sequences')) {
            return [];
        }

        $porEmpresa = DB::table('fiscal_sequences')
            ->selectRaw('company_id')
            ->selectRaw('count(*) as total')
            ->selectRaw('sum(case when next_number <= range_to and (expires_at is null or expires_at >= ?) then 1 else 0 end) as utilizables', [now()->toDateString()])
            ->where('is_active', true)
            ->groupBy('company_id')
            ->get();

        return $porEmpresa
            ->mapWithKeys(fn ($f): array => [(int) $f->company_id => (int) $f->utilizables === 0])
            ->all();
    }

    /**
     * Cajas abiertas desde hace demasiado.
     *
     * Un turno que nadie cierra deja el arqueo sin cuadrar y las ventas del día siguiente colgando de
     * la jornada anterior. Es de las cosas que solo se ven desde fuera.
     *
     * @return array<int, bool>
     */
    private function cajaAbiertaDemasiado(): array
    {
        return CashSession::query()->withoutGlobalScopes()
            ->where('status', 'open')
            ->where('opened_at', '<', now()->subHours(self::HORAS_CAJA_ABIERTA))
            ->distinct()
            ->pluck('company_id')
            ->mapWithKeys(fn ($id): array => [(int) $id => true])
            ->all();
    }

    /** @return array<int, int> */
    private function productosActivos(): array
    {
        return Product::query()->withoutGlobalScopes()
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->groupBy('company_id')
            ->selectRaw('company_id, count(*) as total')
            ->pluck('total', 'company_id')
            ->mapWithKeys(fn ($n, $id): array => [(int) $id => (int) $n])
            ->all();
    }

    /**
     * Productos activos con precio en cero: se pueden meter al ticket y no cobran nada.
     *
     * @return array<int, int>
     */
    private function productosSinPrecio(): array
    {
        return Product::query()->withoutGlobalScopes()
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->where('price', '<=', 0)
            ->groupBy('company_id')
            ->selectRaw('company_id, count(*) as total')
            ->pluck('total', 'company_id')
            ->mapWithKeys(fn ($n, $id): array => [(int) $id => (int) $n])
            ->all();
    }

    /**
     * La ÚLTIMA venta que de verdad vale como venta: ni borrada, ni en borrador, ni cancelada. Antes
     * `withoutGlobalScopes()` —necesario para ver TODAS las empresas— también quitaba el filtro de
     * borrado y no distinguía un borrador de una venta completada, así que una empresa con solo
     * ventas canceladas o una venta borrada parecía «activa».
     *
     * @return array<int, Carbon>
     */
    private function ultimaVenta(): array
    {
        return Sale::query()->withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->where('status', SaleStatus::Completed)
            ->groupBy('company_id')
            ->selectRaw('company_id, max(created_at) as ultima')
            ->pluck('ultima', 'company_id')
            ->mapWithKeys(fn ($fecha, $id): array => [(int) $id => Carbon::parse((string) $fecha)])
            ->all();
    }

    /**
     * El último acceso de cada empresa, del registro del sistema.
     *
     * Sale de ahí y no de una columna en `users` porque el dato ya se está guardando: cada entrada
     * deja un `auth.login`. Añadir una columna sería una segunda verdad que mantener.
     *
     * @return array<int, Carbon>
     */
    private function ultimoAcceso(): array
    {
        if (! DbTable::existe('system_events')) {
            return [];
        }

        return DB::table('system_events')
            ->where('type', 'auth.login')
            ->whereNotNull('company_id')
            ->groupBy('company_id')
            ->selectRaw('company_id, max(created_at) as ultimo')
            ->pluck('ultimo', 'company_id')
            ->mapWithKeys(fn ($fecha, $id): array => [(int) $id => Carbon::parse((string) $fecha)])
            ->all();
    }

    /** @return array<int, int> */
    private function usuarios(): array
    {
        return User::query()
            ->where('is_super_admin', false)
            ->whereNotNull('company_id')
            ->groupBy('company_id')
            ->selectRaw('company_id, count(*) as total')
            ->pluck('total', 'company_id')
            ->mapWithKeys(fn ($n, $id): array => [(int) $id => (int) $n])
            ->all();
    }

    /** @return array<int, int> */
    private function sucursales(): array
    {
        return Branch::query()->withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->groupBy('company_id')
            ->selectRaw('company_id, count(*) as total')
            ->pluck('total', 'company_id')
            ->mapWithKeys(fn ($n, $id): array => [(int) $id => (int) $n])
            ->all();
    }

    /**
     * Los topes del plan de cada empresa.
     *
     * ESTOS TOPES NO SE COMPRUEBAN EN NINGUNA PARTE de la aplicación: se guardan al crear el plan y
     * ahí se acaba. Una empresa con plan Básico —tres usuarios— puede tener quince y nadie se entera.
     * Enseñarlo aquí es lo primero que va a destapar quién está pasado.
     *
     * Se informa y NO se bloquea: cortarle el acceso a una empresa que lleva meses pasada, y hacerlo
     * desde un cambio de monitoreo, sería una sorpresa muy desagradable.
     *
     * @return array<int, array{usuarios: int|null, sucursales: int|null, plan: string|null}>
     */
    private function limitesDelPlan(): array
    {
        return DB::table('subscriptions')
            ->join('plans', 'plans.id', '=', 'subscriptions.plan_id')
            ->select('subscriptions.company_id', 'plans.name', 'plans.max_users', 'plans.max_branches')
            ->get()
            ->mapWithKeys(fn ($f): array => [(int) $f->company_id => [
                'usuarios' => $f->max_users === null ? null : (int) $f->max_users,
                'sucursales' => $f->max_branches === null ? null : (int) $f->max_branches,
                'plan' => $f->name === null ? null : (string) $f->name,
            ]])
            ->all();
    }

    /**
     * Turnos cerrados con diferencia entre lo contado y lo esperado, en los últimos treinta días.
     *
     * Un descuadre suelto es un error de conteo; varios seguidos son otra cosa. La pantalla enseña el
     * número y deja el juicio a quien la mira.
     *
     * @return array<int, int>
     */
    private function descuadresDeCaja(): array
    {
        return CashSession::query()->withoutGlobalScopes()
            ->whereNotNull('difference')
            ->where('difference', '!=', 0)
            ->where('closed_at', '>=', now()->subDays(30))
            ->groupBy('company_id')
            ->selectRaw('company_id, count(*) as total')
            ->pluck('total', 'company_id')
            ->mapWithKeys(fn ($n, $id): array => [(int) $id => (int) $n])
            ->all();
    }

    /**
     * El bot encendido pero sin nada que contar.
     *
     * Es la misma regla de `WaBotSetting::puedeContestar()`: encendido y con información del negocio.
     * Sin ella no se queda callado, contesta «esa no te la sé» a todo el mundo y pasa cada
     * conversación a una persona. Desde fuera parece que está roto; desde dentro, que está encendido.
     *
     * @return array<int, bool>
     */
    private function botEncendidoSinInformacion(): array
    {
        if (! DbTable::existe('wa_bot_settings')) {
            return [];
        }

        return DB::table('wa_bot_settings')
            // `where(..., true)` y no `whereRaw('is_active is true')`: aquello es sintaxis de
            // PostgreSQL y los tests corren sobre SQLite. Lo que solo se puede probar en producción
            // no está probado.
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('business_info')->orWhere('business_info', ''))
            ->distinct()
            ->pluck('company_id')
            ->mapWithKeys(fn ($id): array => [(int) $id => true])
            ->all();
    }

    // ==================================================================== Fase 7: la ficha por empresa

    /**
     * La ficha de CADA empresa: seis dominios (Sistema, Facturación, WhatsApp, IA, Ventas, Caja), cada
     * uno HEALTHY/WARNING/CRITICAL con su lista de problemas. Es una lectura DISTINTA de las mismas
     * señales que ya calcula `porEmpresa()` —Ventas y Caja son literalmente esas señales, reempaquetadas
     * en dominios— más cuatro dominios nuevos que cruzan con el resto del monitoreo (errores, jobs,
     * incidentes, rendimiento HTTP).
     *
     * Misma caché que `porEmpresa()` en espíritu, con su propia clave: son dos lecturas distintas de la
     * plataforma y no tiene sentido que una invalide a la otra.
     *
     * @return Collection<int, CompanyHealthCard>
     */
    public function fichas(): Collection
    {
        /** @var list<CompanyHealthCard> $fichas */
        $fichas = cache()->remember('platform:empresas:fichas', self::TTL, fn (): array => $this->calcularFichas());

        return collect($fichas);
    }

    /** La ficha de UNA empresa, o null si no existe. */
    public function ficha(int $companyId): ?CompanyHealthCard
    {
        return $this->fichas()->firstWhere('id', $companyId);
    }

    /**
     * Cuántas empresas hay en cada estado general, para el Resumen: «2 críticas, 8 con avisos».
     *
     * @return array{healthy: int, warning: int, critical: int}
     */
    public function resumenDeProblemas(): array
    {
        $fichas = $this->fichas();

        return [
            'healthy' => $fichas->filter(fn (CompanyHealthCard $f): bool => $f->estadoGeneral() === CompanyHealthCard::HEALTHY)->count(),
            'warning' => $fichas->filter(fn (CompanyHealthCard $f): bool => $f->estadoGeneral() === CompanyHealthCard::WARNING)->count(),
            'critical' => $fichas->filter(fn (CompanyHealthCard $f): bool => $f->estadoGeneral() === CompanyHealthCard::CRITICAL)->count(),
        ];
    }

    /**
     * Una consulta agrupada por señal —nunca una por empresa—, igual que {@see calcular()}: con
     * treinta empresas y seis dominios, preguntar uno a uno serían cientos de consultas en la pantalla
     * que se abre justo cuando algo va mal.
     *
     * @return list<CompanyHealthCard>
     */
    private function calcularFichas(): array
    {
        $base = $this->calcular(); // Ventas y Caja ya vienen de aquí; también sin_ncf y pasada_de_plan.

        $erroresActivos = $this->erroresActivos24hPorEmpresa();
        $incidentesAbiertos = $this->incidentesAbiertosPorEmpresa();
        $jobsFallidos = $this->jobsFallidos24hPorEmpresa();
        $http = $this->httpResumen24hPorEmpresa();
        $suscripciones = $this->estadoSuscripcionPorEmpresa();
        $waDesconectada = $this->whatsappDesconectadaPorEmpresa();
        $waFallidos = $this->whatsappFallidos24hPorEmpresa();
        $waPendientes = $this->whatsappPendientesPorEmpresa();
        $iaFallos = $this->iaFallos24hPorEmpresa();

        return array_map(function (array $e) use (
            $erroresActivos, $incidentesAbiertos, $jobsFallidos, $http, $suscripciones,
            $waDesconectada, $waFallidos, $waPendientes, $iaFallos,
        ): CompanyHealthCard {
            $id = (int) $e['id'];

            return new CompanyHealthCard(
                id: $id,
                nombre: $e['nombre'],
                activa: $e['activa'],
                plan: $e['plan'],
                dominios: [
                    'sistema' => $this->dominioSistema(
                        $erroresActivos[$id] ?? 0, $incidentesAbiertos[$id] ?? 0,
                        $jobsFallidos[$id] ?? 0, $http[$id] ?? null,
                    ),
                    'facturacion' => $this->dominioFacturacion($e, $suscripciones[$id] ?? null),
                    'whatsapp' => $this->dominioWhatsapp(
                        $e, $waDesconectada[$id] ?? false, $waFallidos[$id] ?? 0, $waPendientes[$id] ?? 0,
                    ),
                    'ia' => $this->dominioIa($iaFallos[$id] ?? 0),
                    'ventas' => $this->dominioVentas($e),
                    'caja' => $this->dominioCaja($e),
                ],
            );
        }, $base);
    }

    /**
     * @return array{estado: string, problemas: list<CompanyProblem>}
     */
    private function dominioSistema(int $erroresActivos, int $incidentesAbiertos, int $jobsFallidos, ?array $http): array
    {
        $errores5xx = $http['errores'] ?? 0;
        $p95 = $http['p95'] ?? null;
        $problemas = [];

        if ($incidentesAbiertos > 0) {
            $problemas[] = new CompanyProblem(
                "{$incidentesAbiertos} ".($incidentesAbiertos === 1 ? 'incidente abierto' : 'incidentes abiertos'),
                CompanyProblem::CRITICAL, 'incidentes',
            );
        }

        if ($errores5xx > 0) {
            $problemas[] = new CompanyProblem("{$errores5xx} error(es) 5xx en 24 h", CompanyProblem::CRITICAL, 'servicios');
        }

        if ($erroresActivos > 0) {
            $problemas[] = new CompanyProblem(
                "{$erroresActivos} ".($erroresActivos === 1 ? 'grupo de error activo' : 'grupos de error activos'),
                CompanyProblem::WARNING, 'errores',
            );
        }

        if ($jobsFallidos > 0) {
            $problemas[] = new CompanyProblem("{$jobsFallidos} trabajo(s) fallido(s) en 24 h", CompanyProblem::WARNING, 'servicios');
        }

        // 3000 ms: el mismo corte del tramo `h3` del histograma compartido (Fase 4-6) — lo que ya se
        // considera «empieza a ser mucho» en cualquier otra pantalla de rendimiento.
        if ($p95 !== null && $p95 > 3000) {
            $problemas[] = new CompanyProblem('P95 de sus peticiones por encima de 3 s', CompanyProblem::WARNING, 'rendimiento');
        }

        return $this->dominioDeProblemas($problemas);
    }

    /**
     * @param  array<string, mixed>  $empresa  una fila de {@see calcular()}
     * @return array{estado: string, problemas: list<CompanyProblem>}
     */
    private function dominioFacturacion(array $empresa, ?string $estadoSuscripcion): array
    {
        $problemas = [];

        if ($empresa['sin_ncf']) {
            $problemas[] = new CompanyProblem('Sin comprobantes fiscales disponibles', CompanyProblem::CRITICAL, 'empresas');
        }

        if (in_array($estadoSuscripcion, ['past_due', 'suspended', 'cancelled'], true)) {
            $problemas[] = new CompanyProblem('Suscripción '.match ($estadoSuscripcion) {
                'past_due' => 'con el cobro fallido',
                'suspended' => 'suspendida',
                default => 'cancelada',
            }, CompanyProblem::CRITICAL, 'empresas');
        }

        if ($empresa['pasada_de_plan']) {
            $problemas[] = new CompanyProblem('Pasada de los límites de su plan', CompanyProblem::WARNING, 'empresas');
        }

        return $this->dominioDeProblemas($problemas);
    }

    /**
     * @param  array<string, mixed>  $empresa  una fila de {@see calcular()}
     * @return array{estado: string, problemas: list<CompanyProblem>}
     */
    private function dominioWhatsapp(array $empresa, bool $desconectada, int $fallidos, int $pendientes): array
    {
        $problemas = [];

        // Desconectada Y con el bot encendido: no es «una línea sin usar», es que NADIE recibe
        // respuesta ahora mismo. Silenciosa de verdad, no solo un aviso de configuración.
        if ($desconectada && ! $empresa['bot_sin_info']) {
            $problemas[] = new CompanyProblem('La línea de WhatsApp está desconectada', CompanyProblem::CRITICAL, 'servicios');
        }

        if ($empresa['bot_sin_info']) {
            $problemas[] = new CompanyProblem('El bot está encendido sin información del negocio', CompanyProblem::WARNING, 'empresas');
        }

        if ($fallidos > 0) {
            $problemas[] = new CompanyProblem("{$fallidos} mensaje(s) fallido(s) en 24 h", CompanyProblem::WARNING, 'servicios');
        }

        if ($pendientes > 0) {
            $problemas[] = new CompanyProblem("{$pendientes} mensaje(s) atascado(s) hace más de 15 min", CompanyProblem::WARNING, 'servicios');
        }

        return $this->dominioDeProblemas($problemas);
    }

    /**
     * @return array{estado: string, problemas: list<CompanyProblem>}
     */
    private function dominioIa(int $fallos): array
    {
        $problemas = $fallos > 0
            ? [new CompanyProblem("{$fallos} fallo(s) de IA en 24 h", CompanyProblem::WARNING, 'errores')]
            : [];

        return $this->dominioDeProblemas($problemas);
    }

    /**
     * Lo que le impide vender HOY, reempaquetado desde {@see calcular()}: `sin_almacen` y
     * `sin_productos` son críticos (el cobro directamente no funciona); el resto informa.
     *
     * @param  array<string, mixed>  $empresa
     * @return array{estado: string, problemas: list<CompanyProblem>}
     */
    private function dominioVentas(array $empresa): array
    {
        $problemas = [];

        if ($empresa['sin_almacen']) {
            // Mismo texto que la pantalla ya enseñaba antes de la Fase 7 (`MonitoringTest` lo fija):
            // no hay razón para que cambie solo porque ahora sale de un dominio en vez de una bandera.
            $problemas[] = new CompanyProblem('Sin almacén: no puede cobrar', CompanyProblem::CRITICAL, 'empresas');
        }

        if ($empresa['sin_productos']) {
            $problemas[] = new CompanyProblem('Sin productos que vender', CompanyProblem::CRITICAL, 'empresas');
        }

        if ($empresa['sin_precio'] > 0) {
            $problemas[] = new CompanyProblem("{$empresa['sin_precio']} producto(s) activo(s) sin precio", CompanyProblem::WARNING, 'empresas');
        }

        if ($empresa['nunca_vendio']) {
            $problemas[] = new CompanyProblem('Nunca ha vendido', CompanyProblem::WARNING, 'empresas');
        }

        if ($empresa['sin_vender']) {
            $problemas[] = new CompanyProblem('Sin vender hace semanas', CompanyProblem::WARNING, 'empresas');
        }

        return $this->dominioDeProblemas($problemas);
    }

    /**
     * @param  array<string, mixed>  $empresa
     * @return array{estado: string, problemas: list<CompanyProblem>}
     */
    private function dominioCaja(array $empresa): array
    {
        $problemas = [];

        if ($empresa['caja_abierta']) {
            $problemas[] = new CompanyProblem('Caja abierta desde hace más de un día', CompanyProblem::WARNING, 'empresas');
        }

        if ($empresa['descuadres'] > 0) {
            $problemas[] = new CompanyProblem("{$empresa['descuadres']} descuadre(s) de caja este mes", CompanyProblem::WARNING, 'empresas');
        }

        return $this->dominioDeProblemas($problemas);
    }

    /**
     * @param  list<CompanyProblem>  $problemas
     * @return array{estado: string, problemas: list<CompanyProblem>}
     */
    private function dominioDeProblemas(array $problemas): array
    {
        $peor = CompanyHealthCard::HEALTHY;

        foreach ($problemas as $problema) {
            if ($problema->severidad === CompanyProblem::CRITICAL) {
                $peor = CompanyHealthCard::CRITICAL;
                break;
            }

            $peor = CompanyHealthCard::WARNING;
        }

        return ['estado' => $peor, 'problemas' => $problemas];
    }

    /**
     * Grupos de error ACTIVOS que tocaron a cada empresa en las últimas 24 h —por `last_seen_at`, no
     * por cuándo nació el grupo: uno viejo que sigue repitiéndose hoy SÍ cuenta—.
     *
     * @return array<int, int>
     */
    private function erroresActivos24hPorEmpresa(): array
    {
        if (! DbTable::existe('error_event_companies') || ! DbTable::existe('error_events')) {
            return [];
        }

        return DB::table('error_event_companies as ec')
            ->join('error_events as e', 'e.id', '=', 'ec.error_event_id')
            ->where('e.status', 'active')
            ->where('ec.last_seen_at', '>=', now()->subDay())
            ->groupBy('ec.company_id')
            ->selectRaw('ec.company_id, count(distinct ec.error_event_id) as total')
            ->pluck('total', 'company_id')
            ->mapWithKeys(fn ($n, $id): array => [(int) $id => (int) $n])
            ->all();
    }

    /**
     * Incidentes ABIERTOS (open|investigating) que afectan a cada empresa ahora mismo.
     *
     * @return array<int, int>
     */
    private function incidentesAbiertosPorEmpresa(): array
    {
        if (! DbTable::existe('incident_companies') || ! DbTable::existe('incidents')) {
            return [];
        }

        return DB::table('incident_companies as ic')
            ->join('incidents as i', 'i.id', '=', 'ic.incident_id')
            ->whereIn('i.status', ['open', 'investigating'])
            ->groupBy('ic.company_id')
            ->selectRaw('ic.company_id, count(distinct ic.incident_id) as total')
            ->pluck('total', 'company_id')
            ->mapWithKeys(fn ($n, $id): array => [(int) $id => (int) $n])
            ->all();
    }

    /**
     * Trabajos fallidos de cada empresa en las últimas 24 h, del agregador compartido (`kind=job`,
     * Fase 4): `errors` ya cuenta los fallos, sin tener que leer `failed_jobs` fila a fila.
     *
     * @return array<int, int>
     */
    private function jobsFallidos24hPorEmpresa(): array
    {
        if (! DbTable::existe('metric_buckets')) {
            return [];
        }

        return DB::table('metric_buckets')
            ->where('kind', 'job')
            ->where('company_id', '>', 0)
            ->where('bucket_start', '>=', now()->subDay())
            ->groupBy('company_id')
            ->selectRaw('company_id, sum(errors) as total')
            ->pluck('total', 'company_id')
            ->mapWithKeys(fn ($n, $id): array => [(int) $id => (int) $n])
            ->all();
    }

    /**
     * HTTP de cada empresa en las últimas 24 h (`kind=http`, Fase 5): 5xx y P95, del mismo histograma
     * que ya calcula `MetricsQuery` para la aplicación entera, aquí agrupado por empresa.
     *
     * @return array<int, array{errores: int, p95: float|null}>
     */
    private function httpResumen24hPorEmpresa(): array
    {
        if (! DbTable::existe('metric_buckets')) {
            return [];
        }

        $columnas = ['h0', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'h7', 'h8'];
        $tramos = implode(', ', array_map(fn (string $c): string => "sum({$c}) as {$c}", $columnas));

        return DB::table('metric_buckets')
            ->where('kind', 'http')
            ->where('company_id', '>', 0)
            ->where('bucket_start', '>=', now()->subDay())
            ->groupBy('company_id')
            ->selectRaw("company_id, sum(errors) as errores, {$tramos}")
            ->get()
            ->mapWithKeys(function (object $fila) use ($columnas): array {
                $conteos = array_map(fn (string $c): int => (int) $fila->{$c}, $columnas);

                return [(int) $fila->company_id => [
                    'errores' => (int) $fila->errores,
                    'p95' => Percentiles::estimar($conteos, 0.95),
                ]];
            })
            ->all();
    }

    /**
     * El estado de la suscripción de cada empresa, tal cual —Polar/`SubscriptionService` deciden qué
     * significa cada valor, aquí solo se lee—.
     *
     * @return array<int, string>
     */
    private function estadoSuscripcionPorEmpresa(): array
    {
        return DB::table('subscriptions')
            ->pluck('status', 'company_id')
            ->mapWithKeys(fn ($s, $id): array => [(int) $id => (string) $s])
            ->all();
    }

    /**
     * Si la ÚLTIMA vez que se supo de la línea de WhatsApp de cada empresa fue una desconexión. Un
     * `max(id)` agrupado y no `max(created_at)`: el id ya es el orden de llegada, y así evita tener que
     * volver a la tabla dos veces para leer el mensaje del último.
     *
     * @return array<int, bool>
     */
    private function whatsappDesconectadaPorEmpresa(): array
    {
        if (! DbTable::existe('system_events')) {
            return [];
        }

        $ultimos = DB::table('system_events')
            ->where('type', 'integration.whatsapp')
            ->whereNotNull('company_id')
            ->groupBy('company_id')
            ->selectRaw('max(id) as id')
            ->pluck('id');

        if ($ultimos->isEmpty()) {
            return [];
        }

        return DB::table('system_events')
            ->whereIn('id', $ultimos)
            ->get(['company_id', 'message'])
            ->mapWithKeys(fn (object $f): array => [(int) $f->company_id => str_contains((string) $f->message, 'desconectó')])
            ->all();
    }

    /** @return array<int, int> */
    private function whatsappFallidos24hPorEmpresa(): array
    {
        if (! DbTable::existe('wa_messages')) {
            return [];
        }

        return DB::table('wa_messages')
            ->where('status', 'failed')
            ->where('created_at', '>=', now()->subDay())
            ->groupBy('company_id')
            ->selectRaw('company_id, count(*) as total')
            ->pluck('total', 'company_id')
            ->mapWithKeys(fn ($n, $id): array => [(int) $id => (int) $n])
            ->all();
    }

    /** @return array<int, int> */
    private function whatsappPendientesPorEmpresa(): array
    {
        if (! DbTable::existe('wa_messages')) {
            return [];
        }

        return DB::table('wa_messages')
            ->where('status', 'pending')
            ->where('created_at', '<', now()->subMinutes(15))
            ->groupBy('company_id')
            ->selectRaw('company_id, count(*) as total')
            ->pluck('total', 'company_id')
            ->mapWithKeys(fn ($n, $id): array => [(int) $id => (int) $n])
            ->all();
    }

    /**
     * Grupos de error ACTIVOS de servicio `ai` que tocaron a cada empresa en las últimas 24 h —misma
     * consulta que {@see erroresActivos24hPorEmpresa()}, filtrada al servicio que `ServiceResolver`
     * (Fase 1a) ya distingue—.
     *
     * @return array<int, int>
     */
    private function iaFallos24hPorEmpresa(): array
    {
        if (! DbTable::existe('error_event_companies') || ! DbTable::tieneColumna('error_events', 'service')) {
            return [];
        }

        return DB::table('error_event_companies as ec')
            ->join('error_events as e', 'e.id', '=', 'ec.error_event_id')
            ->where('e.status', 'active')
            ->where('e.service', 'ai')
            ->where('ec.last_seen_at', '>=', now()->subDay())
            ->groupBy('ec.company_id')
            ->selectRaw('ec.company_id, count(distinct ec.error_event_id) as total')
            ->pluck('total', 'company_id')
            ->mapWithKeys(fn ($n, $id): array => [(int) $id => (int) $n])
            ->all();
    }
}
