            {{-- ----------------------------------------------------------------- Actividad --}}
            <div class="border-b border-slate-100 px-3 py-2.5">
                <form method="GET" class="flex flex-wrap items-center gap-2">
                    <input type="hidden" name="pestana" value="actividad">
                    <input type="search" name="busca" value="{{ $f->busca }}" class="bmos-input"
                           style="width:15rem" placeholder="Buscar usuario, empresa, IP…">
                    <select name="empresa" class="bmos-input" style="width:13rem" onchange="this.form.submit()">
                        <option value="">Todas las empresas</option>
                        @foreach ($empresas as $emp)
                            <option value="{{ $emp->id }}" @selected((string) $f->empresa === (string) $emp->id)>{{ $emp->name }}</option>
                        @endforeach
                    </select>
                    <select name="accion" class="bmos-input" style="width:10rem" onchange="this.form.submit()">
                        <option value="">Todo</option>
                        @foreach ($acciones as $clave => $etiqueta)
                            <option value="{{ $clave }}" @selected($f->accion === $clave)>{{ ucfirst($etiqueta) }}</option>
                        @endforeach
                    </select>
                </form>
            </div>

            @if ($actividad->items() === [])
                <p class="bmos-empty">
                    {{ $f->hayFiltros() ? 'No hay actividad con esos filtros.' : 'No hay actividad todavía.' }}
                </p>
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
