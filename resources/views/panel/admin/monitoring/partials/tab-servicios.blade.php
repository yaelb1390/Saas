            {{-- ------------------------------------------------------------------- Servicios --}}
            @php
                $etiquetaDeEstado = fn (?string $estado): string => match ($estado) {
                    'healthy' => 'Bien', 'degraded' => 'A medias', 'unhealthy' => 'Caído', default => 'Sin comprobar',
                };

                // Vencida: nunca comprobada, o hace más de 5 minutos. El panel las pide solo a ellas
                // al abrir la pestaña; el render de la pantalla en sí nunca llama a nada remoto.
                $vencidas = $saludServicios
                    ->filter(fn (array $s): bool => $s['fila']?->last_checked_at === null
                        || \Illuminate\Support\Carbon::parse($s['fila']->last_checked_at)->lt(now()->subMinutes(5)))
                    ->pluck('clave')
                    ->values();
            @endphp

            <div x-data="saludServicios(@js($vencidas))" x-init="comprobarVencidas()">
                <p class="border-b border-slate-100 px-4 py-2.5 text-xs text-slate-400">
                    «Configurado» dice si hay credencial puesta; «disponible» dice si la última vez que
                    se comprobó respondió de verdad. Las dos son preguntas distintas.
                </p>

                @foreach ($saludServicios as $s)
                    @php $fila = $s['fila']; @endphp
                    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-50 px-4 py-3 text-sm last:border-0">
                        <div class="min-w-0">
                            <p class="flex items-center gap-2 font-medium text-slate-700">
                                <span class="bmos-pulso" :style="{ '--tono': tonoDe('{{ $s['clave'] }}') }"
                                      style="--tono: {{ ['healthy' => '#059669', 'degraded' => '#d97706', 'unhealthy' => '#e11d48'][$fila?->status ?? ''] ?? '#94a3b8' }}"></span>
                                {{ $s['etiqueta'] }}
                            </p>
                            <p class="mt-0.5 text-xs text-slate-400" x-text="mensaje['{{ $s['clave'] }}'] ?? {{ Illuminate\Support\Js::from($fila?->message ?? $fila?->last_error ?? 'Sin comprobar todavía') }}"></p>
                        </div>
                        <div class="flex items-center gap-4 text-xs text-slate-400">
                            <span>{{ $fila?->configured ? 'Configurado' : 'Sin configurar' }}</span>
                            <span class="bmos-badge" x-text="estadoTexto['{{ $s['clave'] }}'] ?? {{ Illuminate\Support\Js::from($etiquetaDeEstado($fila?->status)) }}"></span>
                            <span x-text="latenciaTexto['{{ $s['clave'] }}'] ?? {{ Illuminate\Support\Js::from($fila?->latency_ms !== null ? $fila->latency_ms.' ms' : '—') }}"></span>
                            <span title="{{ $fila?->last_checked_at }}">{{ $fila?->last_checked_at ? \Illuminate\Support\Carbon::parse($fila->last_checked_at)->diffForHumans() : 'nunca' }}</span>
                            <button type="button" class="bmos-btn bmos-btn-ghost text-xs"
                                    @click="comprobar('{{ $s['clave'] }}')" :disabled="comprobando['{{ $s['clave'] }}']">
                                <span x-show="!comprobando['{{ $s['clave'] }}']">Comprobar ahora</span>
                                <span x-show="comprobando['{{ $s['clave'] }}']">Comprobando…</span>
                            </button>
                        </div>
                    </div>
                @endforeach
            </div>

            {{-- ------------------------------------------------------------- Colas y trabajos --}}
            <div class="border-t border-slate-100">
                <p class="border-b border-slate-100 bg-slate-50/60 px-4 py-2.5 text-xs font-semibold uppercase tracking-wide text-slate-400">
                    Colas y trabajos
                </p>

                @if (! $colas['snapshot']['aplica'])
                    <p class="p-4 text-sm text-slate-400">
                        Sin cola propia (driver «{{ $colas['snapshot']['driver'] }}»): cada trabajo corre
                        dentro de su propia petición. {{ $colas['snapshot']['pendientes'] }} mensajes de
                        WhatsApp llevan más de 15 minutos sin salir, la única señal que queda de que algo
                        se atascó.
                    </p>
                @else
                    <x-panel.metricas :columnas="4" :items="[
                        ['valor' => $colas['snapshot']['pendientes'], 'etiqueta' => 'pendientes', 'tono' => 'indigo', 'icono' => 'pulse'],
                        ['valor' => $colas['snapshot']['reservados'], 'etiqueta' => 'procesando', 'tono' => 'azul'],
                        ['valor' => $colas['snapshot']['retrasados'], 'etiqueta' => 'retrasados', 'tono' => 'ambar'],
                        ['valor' => $colas['snapshot']['antiguedad_segundos'] !== null ? intdiv($colas['snapshot']['antiguedad_segundos'], 60).' min' : '—', 'etiqueta' => 'el más viejo espera', 'tono' => 'rojo'],
                    ]" />

                    @if ($colas['p95_por_cola']->isNotEmpty())
                        <div class="border-t border-slate-100 px-4 py-3">
                            <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-400">P95 por cola, últimas 24 h</p>
                            @foreach ($colas['p95_por_cola'] as $c)
                                <div class="flex items-center justify-between gap-3 border-b border-slate-50 py-1.5 text-sm last:border-0">
                                    <span class="text-slate-700">{{ $c['cola'] }}</span>
                                    <span class="bmos-mono text-slate-400">
                                        {{ $c['p95_ms'] !== null ? number_format($c['p95_ms']).' ms' : '—' }}
                                        · {{ number_format($c['total']) }} {{ $c['total'] === 1 ? 'trabajo' : 'trabajos' }}
                                    </span>
                                </div>
                            @endforeach
                        </div>
                    @endif
                @endif

                @if ($colas['fallidos']->isNotEmpty())
                    <div class="border-t border-slate-100 px-4 py-3">
                        <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-400">Fallos recientes</p>
                        @foreach ($colas['fallidos'] as $f)
                            <div class="flex items-center justify-between gap-3 border-b border-slate-50 py-1.5 text-sm last:border-0">
                                <span class="text-slate-700">{{ $f->queue }}</span>
                                <span class="bmos-mono text-xs text-slate-400" title="{{ $f->failed_at }}">
                                    {{ \Illuminate\Support\Carbon::parse($f->failed_at)->diffForHumans() }}
                                </span>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>

            <script>
                function saludServicios(vencidas) {
                    return {
                        comprobando: {},
                        mensaje: {},
                        estadoTexto: {},
                        latenciaTexto: {},
                        colorPorEstado: { healthy: '#059669', degraded: '#d97706', unhealthy: '#e11d48', unknown: '#94a3b8' },
                        estados: {},
                        tonoDe(clave) {
                            return this.colorPorEstado[this.estados[clave]] ?? null;
                        },
                        async comprobar(clave) {
                            if (this.comprobando[clave]) return;

                            this.comprobando[clave] = true;

                            try {
                                const token = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
                                const r = await fetch(`/plataforma/monitoreo/salud/${clave}`, {
                                    method: 'POST',
                                    headers: { 'X-CSRF-TOKEN': token, 'Accept': 'application/json' },
                                });
                                const datos = await r.json();

                                if (datos.ok && !datos.repetido) {
                                    this.estados[clave] = datos.estado;
                                    this.estadoTexto[clave] = { healthy: 'Bien', degraded: 'A medias', unhealthy: 'Caído', unknown: 'Sin comprobar' }[datos.estado] ?? datos.estado;
                                    this.latenciaTexto[clave] = datos.latencia_ms !== null ? `${datos.latencia_ms} ms` : '—';
                                    this.mensaje[clave] = datos.mensaje ?? '';
                                }
                            } catch (e) {
                                // Sin conexión o el servidor no contestó: se deja lo último que ya había
                                // en pantalla, que sigue siendo un dato real, solo que no del todo fresco.
                            } finally {
                                this.comprobando[clave] = false;
                            }
                        },
                        comprobarVencidas() {
                            vencidas.forEach((clave) => this.comprobar(clave));
                        },
                    };
                }
            </script>
