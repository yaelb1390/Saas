            {{-- ------------------------------------------------------------------ Registro --}}
            @php
                // El enlace de «ver más días», que conserva todo lo demás que ya estaba filtrado.
                $conVentana = fn (string $dias): string => route('platform.monitoring', array_filter([
                    'pestana' => 'registro', 'busca' => $f->busca, 'familia' => $f->familia,
                    'nivel' => $f->nivel, 'servicio' => $f->servicio, 'empresa' => $f->empresa,
                    'dias' => $dias,
                ], fn ($v) => $v !== null && $v !== ''));
            @endphp

            <div class="border-b border-slate-100 px-3 py-2.5">
                <form method="GET" class="flex flex-wrap items-center gap-2">
                    <input type="hidden" name="pestana" value="registro">
                    {{-- El filtro de empresa viaja escondido: no tiene su propio control en esta
                         pestaña —se llega a él desde «Empresas» o desde un enlace de fuera— y
                         perderlo al tocar otro filtro sería confuso. --}}
                    @if (filled($f->empresa)) <input type="hidden" name="empresa" value="{{ $f->empresa }}"> @endif
                    <input type="search" name="busca" value="{{ $f->busca }}" class="bmos-input"
                           style="width:15rem" placeholder="Buscar correo, nombre, IP, empresa…">
                    <select name="familia" class="bmos-input" style="width:11rem" onchange="this.form.submit()">
                        <option value="">Todo</option>
                        @foreach ($familias as $clave => $etiqueta)
                            <option value="{{ $clave }}" @selected($f->familia === $clave)>{{ $etiqueta }}</option>
                        @endforeach
                    </select>
                    <select name="nivel" class="bmos-input" style="width:9rem" onchange="this.form.submit()">
                        <option value="">Cualquiera</option>
                        <option value="critical" @selected($f->nivel === 'critical')>Grave</option>
                        <option value="warning" @selected($f->nivel === 'warning')>Aviso</option>
                        <option value="info" @selected($f->nivel === 'info')>Normal</option>
                    </select>
                    @if ($conServicioEnRegistro)
                        <select name="servicio" class="bmos-input" style="width:11rem" onchange="this.form.submit()">
                            <option value="">Todo servicio</option>
                            @foreach ($servicios as $clave => $etiqueta)
                                <option value="{{ $clave }}" @selected($f->servicio === $clave)>{{ $etiqueta }}</option>
                            @endforeach
                        </select>
                    @endif
                    <select name="dias" class="bmos-input" style="width:9rem" onchange="this.form.submit()">
                        <option value="" @selected($f->ventanaElegida === null)>Todo el historial</option>
                        <option value="7" @selected($f->ventanaElegida === '7')>Últimos 7 días</option>
                        <option value="30" @selected($f->ventanaElegida === '30')>Últimos 30 días</option>
                        <option value="90" @selected($f->ventanaElegida === '90')>Últimos 90 días</option>
                    </select>
                </form>

                {{-- Al buscar sin elegir ventana, se mira solo la última semana: un `like` sobre el
                     historial entero es caro y crece cada día. Se AVISA para que no parezca que el
                     texto no existía. --}}
                @if ($f->busca !== null && $f->ventanaElegida === null)
                    <p class="mt-2 text-xs text-slate-400">
                        Buscando en los últimos 7 días.
                        <a href="{{ $conVentana('todo') }}" class="underline">Buscar en todo el historial</a>.
                    </p>
                @endif
            </div>

            @if ($registro->items() === [])
                <p class="bmos-empty">
                    @if ($f->hayFiltros())
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
