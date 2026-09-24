            {{-- ----------------------------------------------------------------- Rendimiento --}}
            <div class="border-b border-slate-100 px-3 py-2.5">
                <form method="GET" class="flex flex-wrap items-center gap-2">
                    <input type="hidden" name="pestana" value="rendimiento">
                    <select name="empresa" class="bmos-input" style="width:13rem" onchange="this.form.submit()">
                        <option value="">Todas las empresas</option>
                        @foreach ($empresas as $emp)
                            <option value="{{ $emp->id }}" @selected((string) $f->empresa === (string) $emp->id)>{{ $emp->name }}</option>
                        @endforeach
                    </select>
                </form>
            </div>

            @unless (config('bmos.monitoreo.metricas_http.activo', true))
                <div class="m-3 rounded-xl border border-amber-200 bg-amber-50 p-3 text-xs text-amber-800">
                    El muestreo de rendimiento está APAGADO (<code class="bmos-mono">BMOS_METRICAS=false</code>):
                    lo de abajo es lo último que se guardó, no lo que pasa ahora mismo.
                </div>
            @endunless

            <div class="p-4">
                <p class="mb-3 text-xs font-semibold uppercase tracking-wide text-slate-400">Últimas 24 h</p>
                <x-panel.metricas :columnas="4" :items="[
                    ['valor' => number_format($rendimiento['app']['requests']), 'etiqueta' => 'requests', 'tono' => 'indigo', 'icono' => 'pulse'],
                    ['valor' => $rendimiento['app']['tasa_error'].'%', 'etiqueta' => 'tasa de error', 'tono' => 'rojo', 'icono' => 'ban'],
                    ['valor' => $rendimiento['app']['p50'] !== null ? number_format($rendimiento['app']['p50']).' ms' : '—', 'etiqueta' => 'P50', 'tono' => 'azul'],
                    ['valor' => $rendimiento['app']['p95'] !== null ? number_format($rendimiento['app']['p95']).' ms' : '—', 'etiqueta' => 'P95', 'tono' => 'violeta'],
                ]" />
                <p class="mt-2 text-xs text-slate-400">
                    P99: {{ $rendimiento['app']['p99'] !== null ? number_format($rendimiento['app']['p99']).' ms' : '—' }}
                    · muestreo 1 de cada {{ config('bmos.monitoreo.metricas_http.uno_de_cada', 5) }}
                    (los errores y las peticiones lentas se guardan siempre).
                </p>
            </div>

            <div class="grid grid-cols-1 gap-4 border-t border-slate-100 p-4 lg:grid-cols-2">
                <div class="bmos-card overflow-hidden">
                    <p class="border-b border-slate-100 px-4 py-2.5 text-xs font-semibold uppercase tracking-wide text-slate-400">
                        Por módulo
                    </p>
                    @forelse ($rendimiento['por_modulo'] as $m)
                        <div class="flex items-center justify-between gap-3 border-b border-slate-50 px-4 py-2.5 text-sm last:border-0">
                            <span class="font-medium text-slate-700">{{ \App\Modules\Core\Support\ModuleRegistry::exists($m['modulo']) ? \App\Modules\Core\Support\ModuleRegistry::label($m['modulo']) : ucfirst($m['modulo']) }}</span>
                            <span class="bmos-mono text-xs text-slate-400">
                                {{ number_format($m['requests']) }} {{ $m['requests'] === 1 ? 'request' : 'requests' }}
                                · P95 {{ $m['p95'] !== null ? number_format($m['p95']).' ms' : '—' }}
                                @if ($m['errores'] > 0)
                                    · <span class="text-rose-600">{{ number_format($m['errores']) }} err</span>
                                @endif
                            </span>
                        </div>
                    @empty
                        <p class="bmos-empty">Sin tráfico medido en las últimas 24 h.</p>
                    @endforelse
                </div>

                <div class="bmos-card overflow-hidden">
                    <p class="border-b border-slate-100 px-4 py-2.5 text-xs font-semibold uppercase tracking-wide text-slate-400">
                        Endpoints más lentos
                    </p>
                    @forelse ($rendimiento['lentos'] as $e)
                        <div class="flex items-center justify-between gap-3 border-b border-slate-50 px-4 py-2.5 text-sm last:border-0">
                            <span class="min-w-0 truncate font-medium text-slate-700" title="{{ $e['endpoint'] }}">
                                @if ($e['metodo']) <span class="bmos-mono text-xs text-slate-400">{{ $e['metodo'] }}</span> @endif
                                {{ $e['endpoint'] }}
                            </span>
                            <span class="shrink-0 bmos-mono text-xs text-slate-400">
                                {{ $e['p95'] !== null ? number_format($e['p95']).' ms' : '—' }} · {{ number_format($e['requests']) }}
                            </span>
                        </div>
                    @empty
                        <p class="bmos-empty">
                            Todavía no hay suficientes muestras para un ranking fiable
                            (mínimo {{ config('bmos.monitoreo.metricas_http.muestras_minimas', 20) }} requests por endpoint).
                        </p>
                    @endforelse
                </div>
            </div>
