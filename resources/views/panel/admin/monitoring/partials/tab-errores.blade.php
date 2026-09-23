            {{-- ------------------------------------------------------------------- Errores --}}
            <div class="border-b border-slate-100 px-3 py-2.5">
                <form method="GET" class="flex flex-wrap items-center gap-2">
                    <input type="hidden" name="pestana" value="errores">
                    @if (filled($f->empresa)) <input type="hidden" name="empresa" value="{{ $f->empresa }}"> @endif
                    <input type="search" name="busca" value="{{ $f->busca }}" class="bmos-input"
                           style="width:15rem" placeholder="Buscar mensaje, clase, ruta, empresa…">
                    @if ($conDesgloseDeErrores)
                        <select name="estado" class="bmos-input" style="width:9rem" onchange="this.form.submit()">
                            <option value="active" @selected($f->estado === 'active')>Activos</option>
                            <option value="resolved" @selected($f->estado === 'resolved')>Resueltos</option>
                            <option value="ignored" @selected($f->estado === 'ignored')>Ignorados</option>
                            <option value="todos" @selected($f->estado === 'todos')>Todos</option>
                        </select>
                        <select name="servicio" class="bmos-input" style="width:11rem" onchange="this.form.submit()">
                            <option value="">Todo servicio</option>
                            @foreach ($servicios as $clave => $etiqueta)
                                <option value="{{ $clave }}" @selected($f->servicio === $clave)>{{ $etiqueta }}</option>
                            @endforeach
                        </select>
                        <select name="orden" class="bmos-input" style="width:10rem" onchange="this.form.submit()">
                            <option value="recientes" @selected($f->orden === 'recientes')>Más recientes</option>
                            <option value="frecuentes" @selected($f->orden === 'frecuentes')>Más veces</option>
                            <option value="empresas" @selected($f->orden === 'empresas')>Más empresas</option>
                        </select>
                    @endif
                </form>
            </div>

            @forelse ($errores as $e)
                <a href="{{ route('platform.monitoring.error', $e) }}" class="bmos-suceso" style="--tono: {{ $e->hits > 10 ? '#e11d48' : '#f59e0b' }}">
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
                            @if ($conDesgloseDeErrores && $e->companies_count > 1)
                                <span>{{ $e->companies_count }} empresas</span>
                            @else
                                <span>{{ $e->company?->name ?? 'sin empresa' }}</span>
                            @endif
                            @if ($conDesgloseDeErrores && $e->status !== 'active')
                                <span class="bmos-badge {{ $e->status === 'resolved' ? 'badge-green' : 'badge-gray' }}">
                                    {{ $e->status === 'resolved' ? 'Resuelto' : 'Ignorado' }}
                                </span>
                            @endif
                            @if ($e->esHistorico())
                                <span class="bmos-badge badge-gray" title="Agrupado antes de que existiera el desglose por empresa: su reparto es aproximado.">
                                    histórico v1
                                </span>
                            @endif
                        </p>
                    </div>
                    <span class="bmos-badge {{ $e->hits > 10 ? 'badge-red' : 'badge-amber' }} shrink-0">
                        {{ number_format($e->hits) }} {{ $e->hits === 1 ? 'vez' : 'veces' }}
                    </span>
                </a>
            @empty
                {{-- Una lista con cabecera y sin filas parece rota; esto dice lo que pasa. --}}
                <div class="p-8 text-center">
                    <p class="text-sm font-medium text-emerald-700">
                        {{ $f->hayFiltros() ? 'Nada con esos filtros' : 'Ningún error registrado' }}
                    </p>
                    @unless ($f->hayFiltros())
                        <p class="mt-1 text-xs text-slate-400">Es la mejor noticia de esta pantalla.</p>
                    @endunless
                </div>
            @endforelse

            @if ($errores->isNotEmpty())
                <div class="border-t border-slate-100 p-3">
                    <p class="mb-2 text-xs text-slate-400">
                        Agrupados por huella: el mismo fallo repetido es una fila con su contador, no cien filas.
                    </p>
                    {{ $errores->links() }}
                </div>
            @endif
