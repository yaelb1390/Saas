        {{--
            La pestaña Resumen: el dashboard. Responde «¿BMIA está saludable?» sin entrar a ninguna
            otra pestaña. Todo lo de aquí es agregado o recortado a un puñado de filas: el detalle
            completo vive en Registro, Errores, Empresas y Actividad.
        --}}

        {{-- El pulso de las últimas 24 horas. --}}
        <x-panel.metricas :items="[
            ['valor' => $pulso['dia']['sucesos'], 'etiqueta' => 'sucesos 24 h', 'tono' => 'indigo', 'icono' => 'pulse',
                'tendencia' => $frenteAAyer('sucesos', null, 'Sucesos registrados')],
            ['valor' => $pulso['dia']['problemas'], 'etiqueta' => 'avisos y graves', 'tono' => 'ambar', 'icono' => 'alert',
                'tendencia' => $frenteAAyer('problemas', false, 'Avisos y errores graves')],
            ['valor' => $pulso['dia']['accesos'], 'etiqueta' => 'accesos', 'tono' => 'azul', 'icono' => 'login',
                'tendencia' => $frenteAAyer('accesos', true, 'Entradas al sistema')],
            ['valor' => $pulso['dia']['fallidos'], 'etiqueta' => 'accesos fallidos', 'tono' => 'rojo', 'icono' => 'ban',
                'tendencia' => $frenteAAyer('fallidos', false, 'Intentos de entrada fallidos')],
        ]" />

        {{-- Cuatro tarjetas desde que existe la de «Estado de las empresas». En pantallas medianas van de dos en dos; la serie se queda con lo que sobre, que es la que de verdad necesita ancho. --}}
        <div class="grid grid-cols-1 items-start gap-4 lg:grid-cols-2 2xl:grid-cols-[minmax(0,1fr)_16rem_16rem_15rem]">
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

            {{-- Servicios externos: cada uno con su punto, y el punto late solo si pide algo. --}}
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
                            {{-- El contador real puede ser mayor que la lista de arriba, que se
                                 recorta a diez para pintarse: el «y N más» es lo que dice que hay más
                                 sin tener que traerlos todos. --}}
                            @if ($contadores['webhooks_pendientes'] > $webhooks->count())
                                <p class="mt-1 text-xs font-medium text-amber-900">
                                    …y {{ $contadores['webhooks_pendientes'] - $webhooks->count() }} más.
                                </p>
                            @endif
                        </div>
                    @endif
            </div>

            {{--
                Lo que le impide vender a alguien AHORA MISMO.

                Va antes que las suscripciones a propósito: una empresa que no puede cobrar tiene un
                problema hoy; una que vence en diez días, la semana que viene. Y las tres primeras
                señales no aparecían en ninguna pantalla —se descubrían cuando el cobro fallaba con un
                cliente delante—.

                Cada línea solo se pinta si tiene a alguien detrás: una lista de ceros no es un panel
                de control, es ruido que se deja de leer.
            --}}
            @php
                $bloqueos = collect([
                    ['n' => $avisos['sin_almacen'], 'texto' => 'sin almacén: no pueden cobrar', 'tono' => 'text-rose-700 bg-rose-50'],
                    ['n' => $avisos['sin_ncf'], 'texto' => 'sin NCF disponible: no pueden facturar', 'tono' => 'text-rose-700 bg-rose-50'],
                    ['n' => $avisos['sin_productos'], 'texto' => 'sin productos que vender', 'tono' => 'text-rose-700 bg-rose-50'],
                    ['n' => $avisos['caja_abierta'], 'texto' => 'con la caja sin cerrar', 'tono' => 'text-amber-800 bg-amber-50'],
                    ['n' => $avisos['nunca_vendio'], 'texto' => 'que nunca han vendido', 'tono' => 'text-amber-800 bg-amber-50'],
                    ['n' => $avisos['sin_vender'], 'texto' => 'sin vender hace semanas', 'tono' => 'text-amber-800 bg-amber-50'],
                    ['n' => $avisos['pasada_de_plan'], 'texto' => 'pasadas de su plan', 'tono' => 'text-violet-700 bg-violet-50'],
                    ['n' => $avisos['bot_sin_info'], 'texto' => 'con el bot encendido y sin información', 'tono' => 'text-amber-800 bg-amber-50'],
                ])->where('n', '>', 0);
            @endphp

            <div class="bmos-card bmos-card-pad">
                    <p class="mb-3 text-xs font-semibold uppercase tracking-wide text-slate-400">Estado de las empresas</p>

                    @forelse ($bloqueos as $b)
                        <p class="mb-1.5 rounded-lg px-3 py-2 text-xs font-medium {{ $b['tono'] }}">
                            <b>{{ $b['n'] }}</b>
                            {{ $b['n'] === 1 ? 'empresa' : 'empresas' }} {{ $b['texto'] }}
                        </p>
                    @empty
                        <div class="py-4 text-center">
                            <p class="text-2xl font-bold text-emerald-600">{{ $salud['empresas'] }}</p>
                            <p class="mt-0.5 text-xs text-slate-400">todas pueden vender y ninguna está parada</p>
                        </div>
                    @endforelse

                    @if ($bloqueos->isNotEmpty())
                        <p class="mt-2 text-xs text-slate-400">
                            <a href="{{ route('platform.monitoring', ['pestana' => 'empresas']) }}" class="underline">El detalle, en la pestaña «Empresas»</a>.
                        </p>
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

        {{--
            Un adelanto de las dos listas más largas, no la lista: el detalle completo, con sus
            filtros y su búsqueda, vive en las pestañas Errores y Registro. Sin esto había que
            entrar a otra pestaña para saber si había algo que mirar.
        --}}
        <div class="grid grid-cols-1 gap-4 lg:grid-cols-2 xl:grid-cols-3">
            <div class="bmos-card overflow-hidden">
                <p class="border-b border-slate-100 px-4 py-2.5 text-xs font-semibold uppercase tracking-wide text-slate-400">
                    Errores activos
                </p>
                @forelse ($erroresResumen as $e)
                    <a href="{{ route('platform.monitoring.error', $e) }}" class="bmos-suceso" style="--tono: {{ $e->hits > 10 ? '#e11d48' : '#f59e0b' }}">
                        <span class="bmos-suceso-hora" title="{{ $e->last_seen_at?->format('d/m/Y H:i:s') }}">
                            {{ $e->last_seen_at?->diffForHumans() }}
                        </span>
                        <div class="min-w-0 flex-1">
                            <p class="bmos-suceso-texto">
                                <b>{{ class_basename($e->class) }}</b>
                                {{ Str::limit($e->message, 100) }}
                            </p>
                            <p class="bmos-suceso-meta">
                                <span>{{ $e->company?->name ?? 'sin empresa' }}</span>
                            </p>
                        </div>
                        <span class="bmos-badge {{ $e->hits > 10 ? 'badge-red' : 'badge-amber' }} shrink-0">
                            {{ number_format($e->hits) }} {{ $e->hits === 1 ? 'vez' : 'veces' }}
                        </span>
                    </a>
                @empty
                    <div class="p-6 text-center">
                        <p class="text-sm font-medium text-emerald-700">Ningún error activo</p>
                    </div>
                @endforelse
                @if ($contadores['errores_activos'] > $erroresResumen->count())
                    <p class="border-t border-slate-100 p-3 text-xs">
                        <a href="{{ route('platform.monitoring', ['pestana' => 'errores']) }}" class="underline text-slate-500">
                            Ver los {{ $contadores['errores_activos'] }} errores activos
                        </a>
                    </p>
                @endif
            </div>

            <div class="bmos-card overflow-hidden">
                <p class="border-b border-slate-100 px-4 py-2.5 text-xs font-semibold uppercase tracking-wide text-slate-400">
                    Incidentes activos
                </p>
                @forelse ($incidentesResumen as $i)
                    <a href="{{ route('platform.monitoring.incidents.show', $i) }}" class="bmos-suceso" style="--tono: {{ $i->severity === 'critical' ? '#e11d48' : '#f59e0b' }}">
                        <span class="bmos-suceso-hora" title="{{ $i->last_detected_at?->format('d/m/Y H:i:s') }}">
                            {{ $i->last_detected_at?->diffForHumans() }}
                        </span>
                        <div class="min-w-0 flex-1">
                            <p class="bmos-suceso-texto">
                                <b>{{ $i->code }}</b>
                                {{ Str::limit($i->title, 100) }}
                            </p>
                            <p class="bmos-suceso-meta">
                                <span>{{ $i->companies_count }} {{ $i->companies_count === 1 ? 'empresa' : 'empresas' }}</span>
                            </p>
                        </div>
                        <span class="bmos-badge {{ $i->severity === 'critical' ? 'badge-red' : 'badge-amber' }} shrink-0">
                            {{ number_format($i->occurrences) }} {{ $i->occurrences === 1 ? 'vez' : 'veces' }}
                        </span>
                    </a>
                @empty
                    <div class="p-6 text-center">
                        <p class="text-sm font-medium text-emerald-700">Ningún incidente activo</p>
                    </div>
                @endforelse
                @if ($contadores['incidentes_activos'] > $incidentesResumen->count())
                    <p class="border-t border-slate-100 p-3 text-xs">
                        <a href="{{ route('platform.monitoring', ['pestana' => 'incidentes']) }}" class="underline text-slate-500">
                            Ver los {{ $contadores['incidentes_activos'] }} incidentes activos
                        </a>
                    </p>
                @endif
            </div>

            <div class="bmos-card overflow-hidden">
                <p class="border-b border-slate-100 px-4 py-2.5 text-xs font-semibold uppercase tracking-wide text-slate-400">
                    Últimos sucesos
                </p>
                @forelse ($sucesosResumen as $s)
                    <div class="bmos-suceso" style="--tono: {{ $carril[$s->level] ?? '#cbd5e1' }}">
                        <span class="bmos-suceso-hora" title="{{ $s->created_at?->diffForHumans() }}">
                            {{ $s->created_at?->format('d/m H:i:s') }}
                        </span>
                        <div class="min-w-0 flex-1">
                            <p class="bmos-suceso-texto">{{ Str::limit($s->message, 100) }}</p>
                            <p class="bmos-suceso-meta">
                                <span>{{ $s->type }}</span>
                                @if ($s->company)<span>{{ $s->company->name }}</span>@endif
                            </p>
                        </div>
                    </div>
                @empty
                    <p class="bmos-empty">Todavía no hay nada registrado.</p>
                @endforelse
                @if ($sucesosResumen->isNotEmpty())
                    <p class="border-t border-slate-100 p-3 text-xs">
                        <a href="{{ route('platform.monitoring', ['pestana' => 'registro']) }}" class="underline text-slate-500">
                            Ver el registro completo
                        </a>
                    </p>
                @endif
            </div>
        </div>
