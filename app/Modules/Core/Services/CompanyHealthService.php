<?php

declare(strict_types=1);

namespace App\Modules\Core\Services;

use App\Models\User;
use App\Modules\Cash\Models\CashSession;
use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\Company;
use App\Modules\Core\Models\Warehouse;
use App\Modules\Core\Support\DbTable;
use App\Modules\Inventory\Models\Product;
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

    /** @return array<int, Carbon> */
    private function ultimaVenta(): array
    {
        return Sale::query()->withoutGlobalScopes()
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
}
