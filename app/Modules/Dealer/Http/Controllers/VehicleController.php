<?php

declare(strict_types=1);

namespace App\Modules\Dealer\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Models\Audit;
use App\Modules\Core\Models\Branch;
use App\Modules\Core\Support\BusquedaTexto;
use App\Modules\Core\Support\DbTable;
use App\Modules\Core\Support\EntregaDeArchivo;
use App\Modules\Dealer\DTOs\CreateVehicleData;
use App\Modules\Dealer\Enums\DealStatus;
use App\Modules\Dealer\Enums\DocumentType;
use App\Modules\Dealer\Enums\ExpenseType;
use App\Modules\Dealer\Enums\VehicleStatus;
use App\Modules\Dealer\Http\Requests\StoreVehicleRequest;
use App\Modules\Dealer\Models\Vehicle;
use App\Modules\Dealer\Models\VehicleDeal;
use App\Modules\Dealer\Services\VehicleService;
use App\Modules\Dealer\Support\VehicleImageStore;
use App\Support\SimpleXlsx;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * El patio de vehículos.
 *
 * La pantalla es una rejilla (AG Grid) que se alimenta de `datos()`. La lista NO se pinta en el
 * Blade: se pide por JSON, porque es lo que permite ordenar, filtrar y paginar sin recargar y lo que
 * hace usable una pantalla en la que el dealer pasa el día.
 */
final class VehicleController extends Controller
{
    /** Cuántas unidades se mandan de una vez. Ver la nota de `datos()`. */
    private const TOPE = 2000;

    public function index(): View
    {
        /*
         * Aquí las migraciones se aplican a mano y el despliegue no las corre, así que entre que
         * sale el código y alguien migra hay un hueco de horas. En ese hueco la pantalla tiene que
         * explicar qué falta, no devolver un 500. Ya se cayó así la pantalla de Redes sociales.
         */
        $faltaMigrar = ! DbTable::existe('vehicles');

        return view('panel.vehicles', [
            'faltaMigrar' => $faltaMigrar,
            'sucursales' => $faltaMigrar ? collect() : Branch::query()->orderBy('name')->get(['id', 'name']),
            'estados' => VehicleStatus::cases(),
            'puedeGestionar' => Gate::allows('vehicles.manage'),
            'resumen' => $faltaMigrar ? null : $this->resumen(),
            // Marcas y modelos de lo que la empresa TIENE. Un desplegable con todas las marcas del
            // mundo no lo usa nadie, y uno vacío tampoco: estos salen del propio patio.
            'marcas' => $faltaMigrar ? collect() : Vehicle::query()->distinct()->orderBy('make')->pluck('make')->filter()->values(),
            'anios' => $faltaMigrar ? collect() : Vehicle::query()->distinct()->orderByDesc('year')->pluck('year')->filter()->values(),
            'porMarca' => $faltaMigrar ? collect() : $this->porMarca(),
            'tiposDocumento' => DocumentType::cases(),
            'tiposGasto' => ExpenseType::cases(),
        ]);
    }

    /**
     * Las filas de la rejilla.
     *
     * EL COSTO NO VIAJA SI NO SE PUEDE VER. Esconder la columna en el navegador mientras el costo va
     * dentro del JSON no es una restricción: es una filtración con una cortina delante, y cualquiera
     * la levanta abriendo la pestaña de red. Por eso las claves ni se construyen.
     */
    public function datos(Request $request, VehicleService $vehiculos): JsonResponse
    {
        if (! DbTable::existe('vehicles')) {
            return response()->json(['filas' => [], 'falta_migrar' => true]);
        }

        $puedeGestionar = Gate::allows('vehicles.manage');

        // El MISMO filtrado que usan la exportación y el agrupado. Con tres copias, el día que
        // alguien añada un filtro a una sola, el Excel traería otra cosa que la pantalla.
        $consulta = $this->filtrar($vehiculos->paraLaRejilla(), $request);

        /*
         * Se manda todo de una vez y con tope, porque el modelo de filas de AG Grid Community es del
         * cliente: ordena, filtra y pagina en el navegador sobre lo que ya tiene. Para el tamaño de
         * un dealer —decenas o cientos de unidades— es lo correcto y va instantáneo.
         *
         * El tope no es decorativo: sin él, un patio enorme mandaría megas de JSON y colgaría el
         * navegador. Si algún día alguien lo alcanza, se pagina en el servidor; el modelo de filas de
         * servidor de AG Grid es de pago y no se va a usar.
         */
        $unidades = $consulta->limit(self::TOPE)->get();

        // Quién tiene cada unidad, de UNA consulta para todas. Preguntarlo fila a fila serían
        // doscientas consultas en la pantalla que el dealer deja abierta todo el día.
        $clientes = $this->clientesPorUnidad($unidades->pluck('id')->all());

        $filas = $unidades
            ->map(fn (Vehicle $v): array => $this->fila($v, $puedeGestionar, $clientes[$v->id] ?? null))
            ->all();

        return response()->json([
            'filas' => $filas,
            'puede_gestionar' => $puedeGestionar,
            'tope_alcanzado' => count($filas) >= self::TOPE,
        ]);
    }

    /**
     * La ficha de una unidad: lo que no cabe en la tabla.
     *
     * Va por separado y solo al abrirla: mandar los trabajos de taller de todas las unidades en la
     * carga inicial sería traer datos que casi nadie va a mirar.
     */
    public function ficha(Vehicle $vehicle): JsonResponse
    {
        $puedeGestionar = Gate::allows('vehicles.manage');

        $trato = DbTable::existe('vehicle_deals')
            ? $vehicle->deals()->where('status', '!=', DealStatus::Cancelled->value)->latest('id')->first()
            : null;

        /*
         * Los gastos SOLO si se pueden ver.
         *
         * En qué se gastó el dinero de una unidad es información de costo, igual que el margen: un
         * vendedor no tiene por qué saber que este carro llevó 90.000 de pintura. Se devuelve la
         * lista vacía, no una lista con los costos en blanco.
         */
        $trabajos = $puedeGestionar && DbTable::existe('vehicle_jobs')
            ? $vehicle->jobs()->latest('id')->get()->map(fn ($t): array => [
                'descripcion' => $t->description,
                'tipo' => $t->type->label(),
                'tono' => $t->type->badgeClass(),
                'quien' => $t->performed_by,
                'fecha' => $t->performed_at?->format('d/m/Y'),
                'estado' => $t->status->label(),
                'costo' => (float) $t->cost,
            ])->all()
            : [];

        $fotos = DbTable::existe('vehicle_photos')
            ? $vehicle->photos()->get()->map(fn ($f): array => [
                'id' => $f->id,
                'url' => $f->url(),
                'principal' => (bool) $f->is_primary,
            ])->all()
            : [];

        // Los papeles llevan cédulas y precios pactados: se piden por su propia ruta, que exige
        // administrar. Aquí solo va cuántos hay, para que la pestaña sepa si tiene algo que enseñar.
        $documentos = $puedeGestionar && DbTable::existe('vehicle_documents')
            ? $vehicle->documents()->count()
            : 0;

        return response()->json([
            'trabajos' => $trabajos,
            'fotos' => $fotos,
            'documentos' => $documentos,
            'historial' => $puedeGestionar ? $this->historial($vehicle) : [],
            'trato' => $trato === null ? null : [
                'codigo' => $trato->code,
                'cliente' => $trato->customer_name,
                'estado' => $trato->status->label(),
                'precio' => (float) $trato->agreed_price,
                'saldo' => (float) $trato->balance,
            ],
        ]);
    }

    /**
     * Sirve la foto de una unidad.
     *
     * El modelo llega por enlace de ruta y por tanto pasa por el ámbito de empresa: la foto de otro
     * negocio no se resuelve y devuelve 404. Es importante que sea así y no una comprobación a mano,
     * porque esto entrega un FICHERO a partir de un id y un fallo aquí filtraría imágenes entre
     * empresas sin dejar rastro.
     */
    public function foto(Vehicle $vehicle): Response
    {
        abort_unless($vehicle->hasPhoto(), 404);

        return EntregaDeArchivo::imagen(VehicleImageStore::disk(), (string) $vehicle->photo_path);
    }

    public function store(StoreVehicleRequest $request, VehicleService $vehiculos): RedirectResponse
    {
        $datos = $request->validated();

        $vehiculo = $vehiculos->create(new CreateVehicleData(
            make: $datos['make'],
            model: $datos['model'],
            year: isset($datos['year']) ? (int) $datos['year'] : null,
            vin: $datos['vin'] ?? null,
            trim: $datos['trim'] ?? null,
            color: $datos['color'] ?? null,
            mileage: isset($datos['mileage']) ? (int) $datos['mileage'] : null,
            fuel: $datos['fuel'] ?? null,
            transmission: $datos['transmission'] ?? null,
            plate: $datos['plate'] ?? null,
            purchaseCost: (string) ($datos['purchase_cost'] ?? '0'),
            askingPrice: (string) ($datos['asking_price'] ?? '0'),
            branchId: isset($datos['branch_id']) ? (int) $datos['branch_id'] : null,
            acquiredAt: $datos['acquired_at'] ?? null,
            notes: $datos['notes'] ?? null,
            photo: $request->file('photo'),
        ));

        return back()->with('panel_success', "«{$vehiculo->nombre()}» quedó registrado como {$vehiculo->code}.");
    }

    /**
     * Corrige los datos de una unidad.
     *
     * Faltaba, y era el hueco más grave: un chasis mal tecleado o un precio equivocado no se podían
     * arreglar. Reutiliza el mismo Form Request que el alta, cuyas reglas ya ignoran la propia
     * unidad al comprobar que el chasis no se repite.
     */
    public function update(StoreVehicleRequest $request, Vehicle $vehicle, VehicleService $vehiculos): RedirectResponse
    {
        $vehiculos->update($vehicle, $request->validated());

        return back()->with('panel_success', "«{$vehicle->nombre()}» quedó actualizado.");
    }

    /**
     * El inventario en Excel o CSV.
     *
     * Baja lo que hay FILTRADO, no lo que se ve en pantalla. Es la diferencia que importa: exportar
     * desde el navegador daría solo las quince filas de la página actual, y quien exporta quiere el
     * inventario.
     *
     * Usa `SimpleXlsx`, el mismo generador que ya emplean Ventas, Inventario y Facturación: un
     * .xlsx de verdad armado con ZipArchive, sin una sola dependencia. Agrupar y exportar a Excel
     * desde la rejilla son de la edición de pago de AG Grid; esto los sustituye sin pagar.
     */
    public function exportar(Request $request, VehicleService $vehiculos): SymfonyResponse
    {
        abort_unless(DbTable::existe('vehicles'), 404);

        $puedeGestionar = Gate::allows('vehicles.manage');
        $unidades = $this->filtrar($vehiculos->paraLaRejilla(), $request)->get();
        $clientes = $this->clientesPorUnidad($unidades->pluck('id')->all());

        $cabeceras = ['Código', 'Chasis', 'Marca', 'Modelo', 'Año', 'Color', 'Km', 'Placa', 'Estado', 'Cliente', 'Precio'];

        // El costo solo va si se puede ver. Un fichero que se descarga y circula por correo es peor
        // sitio todavía para filtrar el margen que una pantalla.
        if ($puedeGestionar) {
            $cabeceras = [...$cabeceras, 'Costo de compra', 'Preparación', 'Costo real', 'Margen'];
        }

        $filas = $unidades->map(function (Vehicle $v) use ($puedeGestionar, $clientes): array {
            $fila = [
                $v->code, $v->vin, $v->make, $v->model, $v->year, $v->color,
                $v->mileage, $v->plate, $v->status->label(), $clientes[$v->id] ?? '',
                (float) $v->asking_price,
            ];

            if ($puedeGestionar) {
                $fila = [...$fila, (float) $v->purchase_cost, (float) $v->gastos(), (float) $v->costoReal(), (float) $v->margen()];
            }

            return $fila;
        });

        if ($request->query('formato') === 'xlsx') {
            $ruta = SimpleXlsx::write($cabeceras, $filas);

            return response()->download($ruta, 'patio.xlsx', [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ])->deleteFileAfterSend(true);
        }

        return response()->streamDownload(function () use ($cabeceras, $filas): void {
            $salida = fopen('php://output', 'w');
            fwrite($salida, 'ï»¿'); // para que Excel abra los acentos bien
            fputcsv($salida, $cabeceras);
            foreach ($filas as $fila) {
                fputcsv($salida, $fila);
            }
            fclose($salida);
        }, 'patio.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Agrupa el patio por marca, año o estado.
     *
     * En el SERVIDOR y no en la rejilla: agrupar filas es de la edición de pago de AG Grid, y hacerlo
     * aquí además agrupa el inventario ENTERO —no solo lo que se descargó—, que es lo que se quiere
     * cuando se pregunta «¿de qué tengo más?».
     */
    public function agrupar(Request $request, VehicleService $vehiculos): JsonResponse
    {
        if (! DbTable::existe('vehicles')) {
            return response()->json(['grupos' => []]);
        }

        $por = match ((string) $request->query('por')) {
            'anio' => 'year',
            'estado' => 'status',
            default => 'make',
        };

        $puedeGestionar = Gate::allows('vehicles.manage');

        $grupos = $this->filtrar(Vehicle::query(), $request)
            ->groupBy($por)
            ->selectRaw($por.' as clave, count(*) as unidades, sum(asking_price) as precio')
            ->orderByDesc('unidades')
            ->get()
            ->map(fn ($g): array => [
                'clave' => $g->clave === null || $g->clave === '' ? 'Sin anotar' : (string) $g->clave,
                'unidades' => (int) $g->unidades,
                // El valor del grupo es dinero del inventario: mismo criterio que en la tabla.
                'precio' => $puedeGestionar ? (float) $g->precio : null,
            ])
            ->all();

        return response()->json(['grupos' => $grupos, 'por' => $por]);
    }

    /**
     * Aplica los filtros de la pantalla a una consulta.
     *
     * Extraído para que la rejilla, la exportación y el agrupado filtren EXACTAMENTE igual. Con tres
     * copias, el día que alguien añada un filtro a una sola, el Excel traería otra cosa que la
     * pantalla y nadie sabría cuál de las dos miente.
     *
     * @param  Builder<Vehicle>  $consulta
     * @return Builder<Vehicle>
     */
    private function filtrar(Builder $consulta, Request $request): Builder
    {
        foreach (['estado' => 'status', 'marca' => 'make', 'anio' => 'year'] as $param => $columna) {
            if ($request->filled($param)) {
                $consulta->where($columna, (string) $request->query($param));
            }
        }

        if ($request->filled('q')) {
            BusquedaTexto::enCualquiera(
                $consulta,
                ['code', 'vin', 'make', 'model', 'plate', 'color'],
                (string) $request->query('q'),
            );
        }

        return $consulta;
    }

    /**
     * Quién tiene cada unidad, en UNA consulta.
     *
     * Se toma el trato vivo más reciente de cada vehículo. Los caídos se excluyen: si no, una unidad
     * cuyo apartado se deshizo seguiría enseñando el nombre de quien ya no la compró, que es peor que
     * no enseñar nada.
     *
     * @param  array<int, int>  $ids
     * @return array<int, string>
     */
    private function clientesPorUnidad(array $ids): array
    {
        if ($ids === [] || ! DbTable::existe('vehicle_deals')) {
            return [];
        }

        return VehicleDeal::query()
            ->whereIn('vehicle_id', $ids)
            ->where('status', '!=', DealStatus::Cancelled->value)
            // El más reciente por unidad: `max(id)` agrupado, no una consulta por vehículo.
            ->whereIn('id', fn ($q) => $q->from('vehicle_deals')
                ->selectRaw('max(id)')
                ->whereIn('vehicle_id', $ids)
                ->where('status', '!=', DealStatus::Cancelled->value)
                ->groupBy('vehicle_id'))
            ->get(['vehicle_id', 'customer_name'])
            ->mapWithKeys(fn ($d): array => [(int) $d->vehicle_id => (string) $d->customer_name])
            ->all();
    }

    /**
     * Una fila de la rejilla.
     *
     * El costo, los gastos y el margen SOLO existen si quien pregunta puede verlos. No se ponen a
     * null ni a cero: no aparecen. Un cero se puede confundir con «no costó nada»; una clave ausente
     * no se puede malinterpretar.
     *
     * @return array<string, mixed>
     */
    private function fila(Vehicle $v, bool $puedeGestionar, ?string $cliente): array
    {
        $fila = [
            'id' => $v->id,
            'codigo' => $v->code,
            'foto' => $v->photoUrl(),
            'vin' => $v->vin,
            'marca' => $v->make,
            'modelo' => $v->model,
            'anio' => $v->year,
            'version' => $v->trim,
            'color' => $v->color,
            'km' => $v->mileage,
            'combustible' => $v->fuel,
            'transmision' => $v->transmission,
            'placa' => $v->plate,
            'precio' => (float) $v->asking_price,
            'estado' => $v->status->value,
            'estado_texto' => $v->status->label(),
            'cliente' => $cliente,
            'sucursal' => $v->branch?->name,
            'ingreso' => $v->acquired_at?->format('d/m/Y'),
            /*
             * Cuánto lleva parada en el patio.
             *
             * Va como NÚMERO y no como texto para que la columna se pueda ordenar de verdad: quien
             * mira esto quiere ver primero lo que lleva más tiempo, que es el dinero quieto.
             *
             * Nulo si no se anotó la fecha de entrada; entonces no se pinta barra en vez de fingir
             * que entró hoy.
             */
            'dias' => $v->acquired_at === null ? null : (int) $v->acquired_at->diffInDays(now()),
            'notas' => $v->notes,
        ];

        if ($puedeGestionar) {
            $fila['costo'] = (float) $v->purchase_cost;
            $fila['gastos'] = (float) $v->gastos();
            $fila['costo_real'] = (float) $v->costoReal();
            $fila['margen'] = (float) $v->margen();
        }

        return $fila;
    }

    /**
     * El historial de la unidad, LEÍDO DE LA AUDITORÍA.
     *
     * Sin tabla propia: el sistema ya guarda de cada cambio quién lo hizo, cuándo, desde qué IP, el
     * valor anterior y el nuevo —justo lo que hace falta—. Una tabla de historial paralela sería una
     * segunda verdad que hay que acordarse de escribir, y el día que alguien no lo haga faltaría el
     * cambio justo que se está buscando.
     *
     * Solo se enseñan los campos que le importan a una persona: quién tocó el precio o el estado, no
     * que se recalculó una marca de tiempo.
     *
     * @return array<int, array<string, mixed>>
     */
    private function historial(Vehicle $vehicle): array
    {
        if (! DbTable::existe('audits')) {
            return [];
        }

        $interesan = [
            'status' => 'Estado',
            'asking_price' => 'Precio de venta',
            'min_price' => 'Precio mínimo',
            'purchase_cost' => 'Costo de compra',
            'mileage' => 'Kilometraje',
            'vin' => 'Chasis',
            'plate' => 'Placa',
            'color' => 'Color',
        ];

        return Audit::query()
            ->where('auditable_type', Vehicle::class)
            ->where('auditable_id', $vehicle->id)
            ->with('user:id,name')
            ->latest('id')
            ->limit(50)
            ->get()
            ->flatMap(function (Audit $a) use ($interesan): array {
                $nuevos = (array) $a->new_values;
                $viejos = (array) $a->old_values;

                $lineas = [];

                foreach ($nuevos as $campo => $nuevo) {
                    if (! isset($interesan[$campo])) {
                        continue;
                    }

                    $lineas[] = [
                        'campo' => $interesan[$campo],
                        'antes' => $this->legible($campo, $viejos[$campo] ?? null),
                        'despues' => $this->legible($campo, $nuevo),
                        'quien' => $a->user?->name ?? 'el sistema',
                        'cuando' => $a->created_at?->format('d/m/Y H:i'),
                    ];
                }

                return $lineas;
            })
            ->take(30)
            ->values()
            ->all();
    }

    /** Traduce un valor guardado a algo que una persona lea: «available» no le dice nada a nadie. */
    private function legible(string $campo, mixed $valor): string
    {
        if ($valor === null || $valor === '') {
            return '—';
        }

        if ($campo === 'status') {
            return VehicleStatus::tryFrom((string) $valor)?->label() ?? (string) $valor;
        }

        if (in_array($campo, ['asking_price', 'min_price', 'purchase_cost'], true)) {
            return 'RD$ '.number_format((float) $valor, 2);
        }

        return (string) $valor;
    }

    /**
     * Cuántas unidades hay de cada marca, para el gráfico de barras.
     *
     * Se cortan a las seis primeras: un gráfico con veinte barras no se lee, y lo que se quiere
     * saber de un vistazo es de qué hay más, no el censo completo.
     *
     * @return Collection<string, int>
     */
    private function porMarca(): Collection
    {
        return Vehicle::query()
            ->groupBy('make')
            ->selectRaw('make, count(*) as total')
            ->orderByDesc('total')
            ->limit(6)
            ->pluck('total', 'make')
            ->map(fn ($n): int => (int) $n);
    }

    /**
     * Cuántas unidades hay en cada estado.
     *
     * Va aparte de la rejilla porque se pinta antes de que cargue el JavaScript: si la rejilla
     * tardara o fallara, la pantalla sigue diciendo algo útil.
     *
     * @return array<string, int>
     */
    private function resumen(): array
    {
        $porEstado = Vehicle::query()
            ->groupBy('status')
            ->selectRaw('status, count(*) as total')
            ->pluck('total', 'status');

        return [
            'total' => (int) $porEstado->sum(),
            'disponibles' => (int) ($porEstado[VehicleStatus::Available->value] ?? 0),
            'apartados' => (int) ($porEstado[VehicleStatus::Reserved->value] ?? 0),
            'vendidos' => (int) ($porEstado[VehicleStatus::Sold->value] ?? 0),
        ];
    }
}
