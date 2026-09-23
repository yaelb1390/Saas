            {{-- ------------------------------------------------------------------ Incidentes --}}
            @php
                $tonoIncidente = fn (string $severidad): string => match ($severidad) {
                    'critical' => '#e11d48', 'high' => '#f59e0b', 'medium' => '#f59e0b', default => '#94a3b8',
                };
                $etiquetaEstadoIncidente = fn (string $estado): string => match ($estado) {
                    'open' => 'Abierto', 'investigating' => 'Investigando',
                    'resolved' => 'Resuelto', default => 'Ignorado',
                };
            @endphp

            <div class="border-b border-slate-100 px-3 py-2.5">
                <form method="GET" class="flex flex-wrap items-center gap-2">
                    <input type="hidden" name="pestana" value="incidentes">
                    @if (filled($f->empresa)) <input type="hidden" name="empresa" value="{{ $f->empresa }}"> @endif
                    <input type="search" name="busca" value="{{ $f->busca }}" class="bmos-input"
                           style="width:15rem" placeholder="Buscar código o título…">
                    <select name="estado" class="bmos-input" style="width:9rem" onchange="this.form.submit()">
                        <option value="active" @selected($f->estado === 'active')>Activos</option>
                        <option value="open" @selected($f->estado === 'open')>Abiertos</option>
                        <option value="investigating" @selected($f->estado === 'investigating')>Investigando</option>
                        <option value="resolved" @selected($f->estado === 'resolved')>Resueltos</option>
                        <option value="ignored" @selected($f->estado === 'ignored')>Ignorados</option>
                        <option value="todos" @selected($f->estado === 'todos')>Todos</option>
                    </select>
                    <select name="severidad" class="bmos-input" style="width:9rem" onchange="this.form.submit()">
                        <option value="">Toda severidad</option>
                        @foreach (['critical' => 'Crítica', 'high' => 'Alta', 'medium' => 'Media', 'low' => 'Baja'] as $clave => $etiqueta)
                            <option value="{{ $clave }}" @selected($f->severidad === $clave)>{{ $etiqueta }}</option>
                        @endforeach
                    </select>
                    <select name="servicio" class="bmos-input" style="width:11rem" onchange="this.form.submit()">
                        <option value="">Todo servicio</option>
                        @foreach ($servicios as $clave => $etiqueta)
                            <option value="{{ $clave }}" @selected($f->servicio === $clave)>{{ $etiqueta }}</option>
                        @endforeach
                    </select>
                </form>
            </div>

            @forelse ($incidentes as $i)
                <a href="{{ route('platform.monitoring.incidents.show', $i) }}" class="bmos-suceso" style="--tono: {{ $tonoIncidente($i->severity) }}">
                    <span class="bmos-suceso-hora" title="{{ $i->last_detected_at?->format('d/m/Y H:i:s') }}">
                        {{ $i->last_detected_at?->diffForHumans() }}
                    </span>
                    <div class="min-w-0 flex-1">
                        <p class="bmos-suceso-texto">
                            <b>{{ $i->code }}</b>
                            {{ $i->title }}
                        </p>
                        <p class="bmos-suceso-meta">
                            <span>{{ $i->companies_count }} {{ $i->companies_count === 1 ? 'empresa' : 'empresas' }}</span>
                            @if ($i->service)<span>{{ $servicios[$i->service] ?? $i->service }}</span>@endif
                            @unless ($i->estaActivo())
                                <span class="bmos-badge {{ $i->status === 'resolved' ? 'badge-green' : 'badge-gray' }}">
                                    {{ $etiquetaEstadoIncidente($i->status) }}
                                </span>
                            @endunless
                        </p>
                    </div>
                    <span class="bmos-badge {{ $i->severity === 'critical' ? 'badge-red' : 'badge-amber' }} shrink-0">
                        {{ number_format($i->occurrences) }} {{ $i->occurrences === 1 ? 'vez' : 'veces' }}
                    </span>
                </a>
            @empty
                <div class="p-8 text-center">
                    <p class="text-sm font-medium text-emerald-700">
                        {{ $f->hayFiltros() ? 'Nada con esos filtros' : 'Ningún incidente' }}
                    </p>
                    @unless ($f->hayFiltros())
                        <p class="mt-1 text-xs text-slate-400">Es la mejor noticia de esta pantalla.</p>
                    @endunless
                </div>
            @endforelse

            @if ($incidentes->isNotEmpty())
                <div class="border-t border-slate-100 p-3">
                    <p class="mb-2 text-xs text-slate-400">
                        Un incidente agrupa lo que le pasó a un mismo problema mientras siguió activo: no es
                        una fila por cada vez que se detectó.
                    </p>
                    {{ $incidentes->links() }}
                </div>
            @endif
