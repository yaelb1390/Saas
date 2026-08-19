{{--
    Monitoreo de la plataforma.

    Responde a tres preguntas, en este orden: ¿está todo bien?, ¿qué se está rompiendo?, ¿quién hizo
    qué? Lo primero va arriba del todo y en una sola línea, porque es lo único que se mira cuando se
    abre con prisa; las cifras vienen después.

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

    // Cuántas cosas piden atención de verdad. Es el titular de la pantalla.
    $pendientes = collect($salud['integraciones'])->where('estado', 'aviso')->count()
        + $salud['bloqueadas']
        + $errores->count()
        + $webhooks->count();
@endphp

<x-layouts.admin title="Monitoreo" heading="Monitoreo"
                 subheading="Qué está pasando en todas las empresas, quién lo hizo y qué se está rompiendo">
    <div class="space-y-5">
        {{-- La respuesta, antes que ningún dato. El punto solo late cuando hay algo que mirar: uno
             latiendo siempre deja de significar nada a los cinco minutos. --}}
        <div class="bmos-estado" style="--tono: {{ $pendientes > 0 ? '#d97706' : '#059669' }}">
            <span class="bmos-pulso {{ $pendientes > 0 ? 'late' : '' }}"></span>
            <div class="min-w-0 flex-1">
                <p class="bmos-estado-titulo">
                    {{ $pendientes > 0
                        ? ($pendientes === 1 ? 'Una cosa pide atención' : $pendientes.' cosas piden atención')
                        : 'Todo en orden' }}
                </p>
                <p class="bmos-estado-nota">
                    {{ $salud['empresas_activas'] }} {{ $salud['empresas_activas'] === 1 ? 'empresa activa' : 'empresas activas' }}
                    · {{ $salud['usuarios'] }} {{ $salud['usuarios'] === 1 ? 'usuario' : 'usuarios' }}
                    · comprobado {{ now()->format('H:i') }}
                </p>
            </div>
        </div>

        <div class="grid grid-cols-1 gap-5 lg:grid-cols-3">
            {{-- Servicios externos --}}
            <div class="bmos-card bmos-card-pad lg:col-span-2">
                <p class="mb-3 text-xs font-semibold uppercase tracking-wide text-slate-400">Servicios externos</p>
                <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
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

                {{-- Un webhook «sin resolver» significa literalmente que alguien tiene que mirarlo:
                     es la señal más accionable que hay en toda la plataforma. --}}
                @if ($webhooks->isNotEmpty())
                    <div class="mt-3 rounded-xl border border-amber-200 bg-amber-50 p-3">
                        <p class="text-sm font-semibold text-amber-900">Avisos de cobro sin resolver</p>
                        @foreach ($webhooks as $w)
                            <p class="mt-1 text-xs text-amber-800">
                                <span class="bmos-mono">{{ $w->type }}</span> · {{ $w->note ?? 'sin motivo' }}
                                <span class="text-amber-600">{{ $w->created_at?->format('d/m H:i') }}</span>
                            </p>
                        @endforeach
                    </div>
                @endif
            </div>

            {{-- Suscripciones --}}
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
                    <div class="py-6 text-center">
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

        {{-- ¿Qué se está rompiendo? --}}
        <div class="bmos-card overflow-hidden">
            <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 p-4">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Errores</p>
                    <p class="mt-0.5 text-xs text-slate-400">
                        Agrupados: el mismo fallo repetido es una fila con su contador, no cien filas.
                    </p>
                </div>
                @if ($errores->isNotEmpty())
                    <span class="bmos-badge badge-red">{{ $errores->count() }}</span>
                @endif
            </div>

            @forelse ($errores as $e)
                <div class="flex flex-wrap items-start justify-between gap-3 border-b border-slate-50 p-4 last:border-0">
                    <div class="min-w-0 flex-1">
                        <p class="font-medium text-slate-800">{{ class_basename($e->class) }}</p>
                        <p class="mt-0.5 text-sm text-slate-600">{{ Str::limit($e->message, 140) }}</p>
                        <p class="mt-1 flex flex-wrap gap-x-3 text-xs">
                            <span class="bmos-mono">{{ $e->origin }}</span>
                            <span class="text-slate-400">{{ $e->company?->name ?? 'sin empresa' }}</span>
                            <span class="text-slate-400">{{ $e->last_seen_at?->diffForHumans() }}</span>
                        </p>
                    </div>
                    <span class="bmos-badge {{ $e->hits > 10 ? 'badge-red' : 'badge-amber' }} shrink-0">
                        {{ number_format($e->hits) }} {{ $e->hits === 1 ? 'vez' : 'veces' }}
                    </span>
                </div>
            @empty
                {{-- Una tabla con cabeceras y sin filas parece rota; esto dice lo que pasa. --}}
                <div class="p-8 text-center">
                    <p class="text-sm font-medium text-emerald-700">Ningún error registrado</p>
                    <p class="mt-1 text-xs text-slate-400">Es la mejor noticia de esta pantalla.</p>
                </div>
            @endforelse
        </div>

        {{-- ¿Quién hizo qué? Como línea de tiempo y no como tabla: lo que importa es el ORDEN y
             quién lo hizo, no comparar columnas entre filas. --}}
        <div class="bmos-card overflow-hidden">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 p-4">
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Actividad</p>
                <form method="GET" class="flex flex-wrap items-center gap-2">
                    <select name="empresa" class="bmos-input" style="min-width:11rem" onchange="this.form.submit()">
                        <option value="">Todas las empresas</option>
                        @foreach ($empresas as $emp)
                            <option value="{{ $emp->id }}" @selected($filtros['empresa'] == $emp->id)>{{ $emp->name }}</option>
                        @endforeach
                    </select>
                    <select name="accion" class="bmos-input" style="min-width:9rem" onchange="this.form.submit()">
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
                <div class="bmos-linea p-4 pl-8">
                    @foreach ($actividad as $a)
                        @php
                            $quien = $a->user?->name ?? 'El sistema';
                            $color = match ($a->event) {
                                'created' => '#10b981', 'deleted' => '#f43f5e',
                                'restored' => '#0ea5e9', default => '#818cf8',
                            };
                        @endphp
                        <div class="bmos-linea-item" style="--tono: {{ $color }}">
                            <div class="flex items-start gap-3">
                                <span class="bmos-linea-quien">{{ mb_strtoupper(mb_substr($quien, 0, 1)) }}</span>
                                <div class="min-w-0 flex-1">
                                    <p class="text-sm text-slate-700">
                                        <b class="text-slate-800">{{ $quien }}</b>
                                        {{ $acciones[$a->event] ?? $a->event }}
                                        <b class="text-slate-800">{{ $enEspanol($a->auditable_type) }}</b>
                                        <span class="bmos-mono">#{{ $a->auditable_id }}</span>
                                    </p>
                                    <p class="mt-0.5 flex flex-wrap items-center gap-x-2">
                                        <span class="bmos-linea-cuando">{{ $a->created_at?->format('d/m/Y H:i') }}</span>
                                        {{-- Sin empresa = de antes de que se guardara, o de algo que
                                             no la tiene. Inventarle una sería peor. --}}
                                        <span class="text-xs text-slate-400">{{ $a->company?->name ?? '—' }}</span>
                                    </p>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif

            <div class="border-t border-slate-100 p-4">{{ $actividad->links() }}</div>
        </div>

        <form method="POST" action="{{ route('platform.monitoring.clean') }}" class="text-right">
            @csrf
            <button type="submit" class="bmos-btn bmos-btn-ghost text-xs">Borrar la actividad de más de un año</button>
        </form>
    </div>
</x-layouts.admin>
