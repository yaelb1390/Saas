{{--
    Monitoreo de la plataforma.

    Está ordenado como se mira una consola, no como se fue escribiendo:

      1. ¿Está todo bien?  → el estado, en una línea.
      2. ¿Cuánto y cuándo? → el pulso de 24 h y la serie de catorce días. Un número suelto no dice
         nada —¿cuarenta sucesos es mucho?—; al lado de los días anteriores, se ve solo.
      3. ¿Qué se rompió?   → los servicios externos.
      4. ¿Qué pasó?        → un panel con pestañas.

    Las PESTAÑAS son lo que arregla el problema de fondo: antes eran tres listas infinitas apiladas
    —3.535 px de alto, medido— con el mismo peso visual, así que nada decía dónde mirar y el registro
    quedaba enterrado al final. Ahora la pantalla cabe y la elección es de quien mira.

    Todo lo que se pinta es de TODAS las empresas, no de la que el operador tenga abierta.
--}}
@php
    $tonos = ['bien' => '#059669', 'aviso' => '#d97706', 'apagado' => '#94a3b8'];
    $nivel = ['critical' => 'badge-red', 'warning' => 'badge-amber', 'info' => 'badge-blue'];

    // «App\Modules\Sales\Models\Sale» no es información para nadie.
    $enEspanol = fn (?string $clase) => match (class_basename($clase ?? '')) {
        'Sale' => 'Venta', 'Product' => 'Producto', 'Customer' => 'Cliente',
        'Invoice' => 'Factura', 'Loan' => 'Préstamo', 'Delivery' => 'Entrega',
        'Expense' => 'Gasto', 'Company' => 'Empresa', 'User' => 'Usuario',
        'Subscription' => 'Suscripción', 'Plan' => 'Plan', 'Employee' => 'Empleado',
        'CashSession' => 'Turno de caja', 'PurchaseOrder' => 'Orden de compra',
        'Supplier' => 'Proveedor', 'Category' => 'Categoría', 'Account' => 'Cuenta',
        'GoodsReceipt' => 'Entrada de mercancía', 'Opportunity' => 'Oportunidad',
        default => class_basename($clase ?? '') ?: '—',
    };

    // El color del carril de gravedad, que es lo que se recorre con la vista.
    $carril = ['critical' => '#e11d48', 'warning' => '#f59e0b', 'info' => '#cbd5e1'];

    // Cuántas cosas piden atención de verdad. Es el titular de la pantalla.
    $pendientes = collect($salud['integraciones'])->where('estado', 'aviso')->count()
        + $salud['bloqueadas']
        + $errores->count()
        + $webhooks->count();
@endphp

<x-layouts.admin title="Monitoreo" heading="Monitoreo"
                 subheading="Qué está pasando en todas las empresas, quién lo hizo y qué se está rompiendo"
                 :wide="true">
    <div class="space-y-4">
        {{-- 1. La respuesta, antes que ningún dato. --}}
        <x-panel.estado
            :tono="$pendientes > 0 ? 'aviso' : 'ok'"
            :titulo="$pendientes > 0
                ? ($pendientes === 1 ? 'Una cosa pide atención' : $pendientes.' cosas piden atención')
                : 'Todo en orden'"
            :nota="$salud['empresas_activas'].' '.($salud['empresas_activas'] === 1 ? 'empresa activa' : 'empresas activas')
                .' · '.$salud['usuarios'].' '.($salud['usuarios'] === 1 ? 'usuario' : 'usuarios')
                .' · comprobado '.now()->format('H:i')" />

        {{-- 2. El pulso de las últimas 24 horas. --}}
        <x-panel.metricas :items="[
            ['valor' => $pulso['dia']['sucesos'], 'etiqueta' => 'sucesos 24 h', 'tono' => 'indigo'],
            ['valor' => $pulso['dia']['problemas'], 'etiqueta' => 'avisos y graves', 'tono' => 'ambar'],
            ['valor' => $pulso['dia']['accesos'], 'etiqueta' => 'accesos', 'tono' => 'azul'],
            ['valor' => $pulso['dia']['fallidos'], 'etiqueta' => 'accesos fallidos', 'tono' => 'rojo'],
        ]" />

        <div class="grid grid-cols-1 items-start gap-4 lg:grid-cols-2 xl:grid-cols-[minmax(0,1fr)_18rem_16rem]">
            {{-- La serie. Es lo que convierte números en tendencia: un pico del martes salta a la
                 vista sin leer una sola fila. --}}
            <div class="bmos-card bmos-card-pad">
                <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Últimos 14 días</p>
                    <p class="flex items-center gap-3 text-xs text-slate-400">
                        <span class="flex items-center gap-1.5">
                            <span class="h-2 w-2 rounded-sm" style="background:#c7d2fe"></span>normales
                        </span>
                        <span class="flex items-center gap-1.5">
                            <span class="h-2 w-2 rounded-sm" style="background:#f59e0b"></span>problemas
                        </span>
                    </p>
                </div>
                <div class="bmos-serie" x-data="sucesosPorDia(@js($pulso['etiquetas']), @js($pulso['normales']), @js($pulso['problemas']))">
                    <canvas x-ref="lienzo"></canvas>
                </div>
            </div>

            {{-- 3. Servicios externos: cada uno con su punto, y el punto late solo si pide algo. --}}
            <div class="bmos-card bmos-card-pad">
                    <p class="mb-3 text-xs font-semibold uppercase tracking-wide text-slate-400">Servicios externos</p>
                    <div class="grid grid-cols-1 gap-2">
                        @foreach ($salud['integraciones'] as $s)
                            <div class="bmos-servicio" style="--tono: {{ $tonos[$s['estado']] ?? '#94a3b8' }}">
                                <span class="bmos-pulso {{ $s['estado'] === 'aviso' ? 'late' : '' }}" style="margin-top:.35rem"></span>
                                <div class="min-w-0">
                                    <p class="bmos-servicio-nombre">{{ $s['nombre'] }}</p>
                                    <p class="bmos-servicio-detalle">{{ $s['detalle'] }}</p>
                                </div>
                            </div>
                        @endforeach
                    </div>

                    {{-- Un webhook «sin resolver» significa literalmente que alguien tiene que
                         mirarlo: es la señal más accionable que hay en toda la plataforma. --}}
                    @if ($webhooks->isNotEmpty())
                        <div class="mt-3 rounded-xl border border-amber-200 bg-amber-50 p-3">
                            <p class="text-sm font-semibold text-amber-900">Avisos de cobro sin resolver</p>
                            @foreach ($webhooks as $w)
                                <p class="mt-1 text-xs text-amber-800">
                                    <span class="bmos-mono">{{ $w->type }}</span> · {{ $w->note ?? 'sin motivo' }}
                                </p>
                            @endforeach
                        </div>
                    @endif
            </div>

            <div class="bmos-card bmos-card-pad">
                    <p class="mb-3 text-xs font-semibold uppercase tracking-wide text-slate-400">Suscripciones</p>
                    @forelse ($salud['por_vencer'] as $v)
                        <div class="flex items-center justify-between gap-3 border-b border-slate-50 py-2 last:border-0">
                            <div class="min-w-0">
                                <p class="truncate text-sm font-medium text-slate-700">{{ $v['empresa'] }}</p>
                                <p class="text-xs text-slate-400">{{ $v['es_prueba'] ? 'En prueba' : 'De pago' }}</p>
                            </div>
                            <span class="bmos-badge {{ $nivel[$v['nivel']] ?? 'badge-gray' }}">
                                {{ $v['dias'] }} {{ $v['dias'] === 1 ? 'día' : 'días' }}
                            </span>
                        </div>
                    @empty
                        <div class="py-4 text-center">
                            <p class="text-2xl font-bold text-emerald-600">{{ $salud['empresas'] }}</p>
                            <p class="mt-0.5 text-xs text-slate-400">al día, ninguna vence pronto</p>
                        </div>
                    @endforelse

                    @if ($salud['bloqueadas'] > 0)
                        <p class="mt-3 rounded-lg bg-rose-50 px-3 py-2 text-xs font-medium text-rose-700">
                            {{ $salud['bloqueadas'] }} {{ $salud['bloqueadas'] === 1 ? 'empresa bloqueada' : 'empresas bloqueadas' }}:
                            no pueden entrar.
                        </p>
                    @endif
            </div>
        </div>

        {{-- 4. El panel con pestañas: registro, errores y actividad.

               La pestaña de arranque NO es fija: si hay errores, se abre en errores. Quien entra a
               esta pantalla con algo roto no debería tener que buscar dónde está lo roto. --}}
        <div class="bmos-card overflow-hidden"
             x-data="{ pestana: @js($errores->isNotEmpty() ? 'errores' : 'registro') }">
            <div class="border-b border-slate-100 p-3">
                <div class="bmos-pestanas">
                    <button type="button" class="bmos-pestana" :class="pestana === 'registro' && 'is-activa'"
                            @click="pestana = 'registro'">
                        Registro del sistema
                    </button>
                    <button type="button" class="bmos-pestana" :class="pestana === 'errores' && 'is-activa'"
                            @click="pestana = 'errores'">
                        Errores
                        @if ($errores->isNotEmpty())
                            <span class="bmos-pestana-num">{{ $errores->count() }}</span>
                        @endif
                    </button>
                    <button type="button" class="bmos-pestana" :class="pestana === 'actividad' && 'is-activa'"
                            @click="pestana = 'actividad'">
                        Actividad
                    </button>
                </div>
            </div>

            {{-- ------------------------------------------------------------------ Registro --}}
            <div x-show="pestana === 'registro'">
                <div class="border-b border-slate-100 px-3 py-2.5">
                    <form method="GET" class="flex flex-wrap items-center gap-2">
                        {{-- Los filtros de la otra pestaña viajan escondidos: sin esto, filtrar aquí
                             reiniciaría el de Actividad sin que se vea. --}}
                        @foreach (['empresa' => $filtros['empresa'], 'accion' => $filtros['accion']] as $k => $v)
                            @if (filled($v)) <input type="hidden" name="{{ $k }}" value="{{ $v }}"> @endif
                        @endforeach
                        <input type="search" name="busca" value="{{ $filtros['busca'] }}" class="bmos-input"
                               style="width:15rem" placeholder="Buscar correo, nombre, IP…">
                        <select name="familia" class="bmos-input" style="width:11rem" onchange="this.form.submit()">
                            <option value="">Todo</option>
                            @foreach ($familias as $clave => $etiqueta)
                                <option value="{{ $clave }}" @selected($filtros['familia'] === $clave)>{{ $etiqueta }}</option>
                            @endforeach
                        </select>
                        <select name="nivel" class="bmos-input" style="width:9rem" onchange="this.form.submit()">
                            <option value="">Cualquiera</option>
                            <option value="critical" @selected($filtros['nivel'] === 'critical')>Grave</option>
                            <option value="warning" @selected($filtros['nivel'] === 'warning')>Aviso</option>
                            <option value="info" @selected($filtros['nivel'] === 'info')>Normal</option>
                        </select>
                    </form>
                </div>

                @if ($registro->items() === [])
                    <p class="bmos-empty">
                        @if (filled($filtros['busca']) || filled($filtros['familia']) || filled($filtros['nivel']))
                            No hay nada con esos filtros.
                        @else
                            Todavía no hay nada registrado. Aparecerá en cuanto alguien entre al sistema.
                        @endif
                    </p>
                @else
                    @foreach ($registro as $s)
                        <div class="bmos-suceso" style="--tono: {{ $carril[$s->level] ?? '#cbd5e1' }}">
                            {{-- La hora con SEGUNDOS y en monoespaciada: dos sucesos del mismo minuto
                                 se ordenan mirando, y en tipografía proporcional los dígitos bailan
                                 y no se pueden comparar entre líneas. --}}
                            <span class="bmos-suceso-hora" title="{{ $s->created_at?->diffForHumans() }}">
                                {{ $s->created_at?->format('d/m H:i:s') }}
                            </span>
                            <div class="min-w-0 flex-1">
                                <p class="bmos-suceso-texto">{{ $s->message }}</p>
                                <p class="bmos-suceso-meta">
                                    <span>{{ $s->type }}</span>
                                    {{-- Sin empresa NO es un fallo: un intento de acceso fallido pasa
                                         antes de saber de quién es, a veces con un correo inventado. --}}
                                    @if ($s->company)<span>{{ $s->company->name }}</span>@endif
                                    @if ($s->ip)<span>{{ $s->ip }}</span>@endif
                                </p>
                            </div>
                            {{-- El badge solo cuando hay algo que decir: repetir «Normal» trescientas
                                 veces es ruido, y el carril ya lo dice. --}}
                            @if ($s->level !== 'info')
                                <span class="bmos-badge {{ $s->level === 'critical' ? 'badge-red' : 'badge-amber' }} shrink-0">
                                    {{ $s->level === 'critical' ? 'Grave' : 'Aviso' }}
                                </span>
                            @endif
                        </div>
                    @endforeach

                    <div class="border-t border-slate-100 p-3">{{ $registro->links() }}</div>
                @endif
            </div>

            {{-- ------------------------------------------------------------------- Errores --}}
            <div x-show="pestana === 'errores'" x-cloak>
                @forelse ($errores as $e)
                    <div class="bmos-suceso" style="--tono: {{ $e->hits > 10 ? '#e11d48' : '#f59e0b' }}">
                        <span class="bmos-suceso-hora" title="{{ $e->last_seen_at?->format('d/m/Y H:i:s') }}">
                            {{ $e->last_seen_at?->diffForHumans() }}
                        </span>
                        <div class="min-w-0 flex-1">
                            <p class="bmos-suceso-texto">
                                <b>{{ class_basename($e->class) }}</b>
                                {{ Str::limit($e->message, 160) }}
                            </p>
                            <p class="bmos-suceso-meta">
                                <span>{{ $e->origin }}</span>
                                <span>{{ $e->company?->name ?? 'sin empresa' }}</span>
                            </p>
                        </div>
                        <span class="bmos-badge {{ $e->hits > 10 ? 'badge-red' : 'badge-amber' }} shrink-0">
                            {{ number_format($e->hits) }} {{ $e->hits === 1 ? 'vez' : 'veces' }}
                        </span>
                    </div>
                @empty
                    {{-- Una lista con cabecera y sin filas parece rota; esto dice lo que pasa. --}}
                    <div class="p-8 text-center">
                        <p class="text-sm font-medium text-emerald-700">Ningún error registrado</p>
                        <p class="mt-1 text-xs text-slate-400">Es la mejor noticia de esta pantalla.</p>
                    </div>
                @endforelse

                @if ($errores->isNotEmpty())
                    <p class="border-t border-slate-100 p-3 text-xs text-slate-400">
                        Agrupados por huella: el mismo fallo repetido es una fila con su contador, no cien filas.
                    </p>
                @endif
            </div>

            {{-- ----------------------------------------------------------------- Actividad --}}
            <div x-show="pestana === 'actividad'" x-cloak>
                <div class="border-b border-slate-100 px-3 py-2.5">
                    <form method="GET" class="flex flex-wrap items-center gap-2">
                        @foreach (['busca' => $filtros['busca'], 'familia' => $filtros['familia'], 'nivel' => $filtros['nivel']] as $k => $v)
                            @if (filled($v)) <input type="hidden" name="{{ $k }}" value="{{ $v }}"> @endif
                        @endforeach
                        <select name="empresa" class="bmos-input" style="width:13rem" onchange="this.form.submit()">
                            <option value="">Todas las empresas</option>
                            @foreach ($empresas as $emp)
                                <option value="{{ $emp->id }}" @selected($filtros['empresa'] == $emp->id)>{{ $emp->name }}</option>
                            @endforeach
                        </select>
                        <select name="accion" class="bmos-input" style="width:10rem" onchange="this.form.submit()">
                            <option value="">Todo</option>
                            @foreach ($acciones as $clave => $etiqueta)
                                <option value="{{ $clave }}" @selected($filtros['accion'] === $clave)>{{ ucfirst($etiqueta) }}</option>
                            @endforeach
                        </select>
                    </form>
                </div>

                @if ($actividad->items() === [])
                    <p class="bmos-empty">No hay actividad con esos filtros.</p>
                @else
                    @foreach ($actividad as $a)
                        @php
                            $quien = $a->user?->name ?? 'El sistema';
                            $color = match ($a->event) {
                                'created' => '#10b981', 'deleted' => '#f43f5e',
                                'restored' => '#0ea5e9', default => '#c7d2fe',
                            };
                        @endphp
                        <div class="bmos-suceso" style="--tono: {{ $color }}">
                            <span class="bmos-suceso-hora" title="{{ $a->created_at?->diffForHumans() }}">
                                {{ $a->created_at?->format('d/m H:i:s') }}
                            </span>
                            <div class="min-w-0 flex-1">
                                <p class="bmos-suceso-texto">
                                    <b>{{ $quien }}</b>
                                    {{ $acciones[$a->event] ?? $a->event }}
                                    <b>{{ $enEspanol($a->auditable_type) }}</b>
                                    <span class="bmos-mono">#{{ $a->auditable_id }}</span>
                                </p>
                                <p class="bmos-suceso-meta">
                                    <span>{{ $a->company?->name ?? '—' }}</span>
                                </p>
                            </div>
                        </div>
                    @endforeach

                    <div class="border-t border-slate-100 p-3">{{ $actividad->links() }}</div>
                @endif
            </div>
        </div>

        <form method="POST" action="{{ route('platform.monitoring.clean') }}" class="text-right">
            @csrf
            <button type="submit" class="bmos-btn bmos-btn-ghost text-xs">Borrar el rastro de más de un año</button>
        </form>
    </div>

    {{--
        En línea y no en `@push`: el layout del panel NO tiene `@stack('scripts')`, así que un push se
        traga el guion en silencio y la gráfica no se dibujaría nunca. Es como lo hacen Reportes, el
        Dashboard y el reporte de automatizaciones.
    --}}
    <script>
        /* Chart.js BAJO DEMANDA: `window.loadChart()` lo trae en su propio archivo para no lastrar
           las pantallas que no dibujan nada. Nunca un import estático. */
        function sucesosPorDia(etiquetas, normales, problemas) {
            return {
                async init() {
                    const Chart = await window.loadChart();

                    new Chart(this.$refs.lienzo, {
                        type: 'bar',
                        data: {
                            labels: etiquetas.map((d) => d.slice(8) + '/' + d.slice(5, 7)),
                            datasets: [
                                {
                                    label: 'Normales',
                                    data: normales,
                                    backgroundColor: '#c7d2fe',
                                    borderRadius: 3,
                                },
                                {
                                    /* Los problemas ARRIBA de la pila y en ámbar: es lo que se busca
                                       de un vistazo, y abajo quedarían aplastados contra el eje. */
                                    label: 'Problemas',
                                    data: problemas,
                                    backgroundColor: '#f59e0b',
                                    borderRadius: 3,
                                },
                            ],
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            scales: {
                                x: { stacked: true, grid: { display: false }, ticks: { font: { size: 10 } } },
                                y: {
                                    stacked: true,
                                    beginAtZero: true,
                                    // Sin decimales: son sucesos contados, no una media.
                                    ticks: { precision: 0, font: { size: 10 } },
                                    grid: { color: '#f1f3f9' },
                                },
                            },
                            plugins: { legend: { display: false } },
                        },
                    });
                },
            };
        }
    </script>
</x-layouts.admin>
