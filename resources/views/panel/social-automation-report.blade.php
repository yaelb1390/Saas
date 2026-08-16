@use('App\Modules\Social\Enums\SocialPlatform')

{{--
    El reporte de una respuesta automática.

    Responde a las dos preguntas que los contadores del listado no pueden: «¿por qué no le contestó a
    fulano?» y «¿qué palabra debería añadir?». Las dos son por automatización, y de ahí que esto sea
    una pantalla por automatización en vez de un panel general.

    Los nombres de campo son los que manda el servidor —`commenterName`, `commentText`, `status`—, no
    los que traía el manual. La versión anterior de esta pantalla buscaba `contactName` y `text`, y por
    eso enseñaba «Alguien» en todas las filas.
--}}
@php
    $red = SocialPlatform::tryFrom($a['platform']);
    $s = $a['stats'];

    // El porcentaje de clics se calcula sobre los mensajes MEDIBLES, no sobre los enviados. Es un
    // aviso explícito de la especificación: con 100 enviados, 40 medibles y 10 clics, la verdad es
    // 25 % y no 10 %.
    $mostrarClics = $s['medibles'] > 0;
    $ctr = $mostrarClics ? ($s['clics'] / $s['medibles']) * 100 : 0;

    // «Entregados» NO existe en Instagram: la red no manda acuse. Enseñar «0 de 120» ahí se leería
    // como una catástrofe cuando solo es que no hay dato.
    $mostrarEntregados = $a['platform'] === 'facebook' && $s['entregados'] > 0;

    $leidos = $s['sent'] > 0 ? ($s['leidos'] / $s['sent']) * 100 : 0;

    // El desglose de estados, para la barra.
    $porEstado = [];
    foreach ($logs as $log) {
        $e = (string) ($log['status'] ?? '');
        $porEstado[$e] = ($porEstado[$e] ?? 0) + 1;
    }

    $vocabulario = [
        'sent' => ['Enviado', 'badge-green', 'estado-enviado'],
        'failed' => ['Falló', 'badge-red', 'estado-fallo'],
        'pending' => ['En espera', 'badge-amber', 'estado-espera'],
        'gated' => ['Esperando que confirme', 'badge-violet', 'estado-confirmar'],
        'skipped' => ['No se envió', 'badge-gray', 'estado-omitido'],
    ];

    $serie = array_values($porDia);
@endphp

<x-layouts.admin title="Reporte" :heading="$a['name']"
                 subheading="A quién le ha contestado esta respuesta automática y qué se le escapó">
    <div class="mx-auto max-w-4xl">
        <a href="{{ route('panel.social.automations') }}" class="mb-4 inline-block text-sm text-indigo-600 hover:underline">
            ← Volver a las respuestas automáticas
        </a>

        {{-- Las mismas cuatro cifras del listado, para no obligar a volver a mirarlas allí. --}}
        <div class="bmos-card bmos-card-pad bmos-marca mb-5" style="--tono: {{ $red?->color() ?? '#94a3b8' }}">
            <div class="mb-3 flex flex-wrap items-center gap-2">
                <span class="bmos-badge {{ $a['isActive'] ? 'badge-green' : 'badge-gray' }}">
                    {{ $a['isActive'] ? 'Encendida' : 'Apagada' }}
                </span>
                <span class="text-sm text-slate-500">
                    En {{ $red?->label() ?? $a['platform'] }}, contesta a
                    @forelse ($a['keywords'] as $palabra)<span class="bmos-clave ml-1">{{ $palabra }}</span>@empty cualquier comentario @endforelse
                </span>
            </div>

            @include('partials.social-automation-cifras', ['fichas' => [
                ['tone-sky', 'comentario', $s['triggered'], 'veces'],
                ['tone-indigo', 'mensaje', $s['sent'], 'mensajes'],
                ['tone-violet', 'personas', $s['people'], 'personas'],
                $s['failed'] > 0
                    ? ['tone-rose', 'fallo', $s['failed'], 'fallaron']
                    : ['tone-emerald', 'bien', 0, 'sin fallos'],
            ]])

            {{-- Lo que pasó DESPUÉS de mandar el mensaje. Solo lo que la red informa de verdad. --}}
            @if ($mostrarClics || $mostrarEntregados || $s['leidos'] > 0)
                <div class="mt-4 grid grid-cols-1 gap-3 border-t border-slate-100 pt-4 sm:grid-cols-3">
                    @if ($mostrarClics)
                        <div>
                            {{-- La cuenta primero: «12 personas pulsaron» se entiende; «24 % CTR» no. --}}
                            <p class="text-xl font-bold text-indigo-600">{{ number_format($s['clicaron']) }}</p>
                            <p class="text-xs text-slate-500">personas pulsaron el enlace</p>
                            <p class="mt-0.5 text-xs text-slate-400">
                                {{ number_format($ctr, 0) }}% de los {{ number_format($s['medibles']) }} mensajes con enlace medible
                            </p>
                        </div>
                    @endif
                    @if ($s['leidos'] > 0)
                        <div>
                            <p class="text-xl font-bold text-slate-800">{{ number_format($leidos, 0) }}%</p>
                            <p class="text-xs text-slate-500">lo leyeron</p>
                            <p class="mt-0.5 text-xs text-slate-400">{{ number_format($s['leidos']) }} de {{ number_format($s['sent']) }}</p>
                        </div>
                    @endif
                    @if ($mostrarEntregados)
                        <div>
                            <p class="text-xl font-bold text-slate-800">{{ number_format($s['entregados']) }}</p>
                            <p class="text-xs text-slate-500">entregados</p>
                        </div>
                    @endif
                </div>
            @elseif ($s['sent'] > 0)
                {{-- Sin enlace medible no se dice «0 %»: sería afirmar que nadie pulsó, cuando lo que
                     pasa es que no había nada que contar. --}}
                <p class="mt-4 border-t border-slate-100 pt-4 text-xs text-slate-400">
                    Los clics no se cuentan aquí: el mensaje no lleva un enlace medible.
                </p>
            @endif
        </div>

        {{-- LO QUE SE ESCAPÓ.

             Va ARRIBA de la tabla a propósito: la tabla responde a una pregunta que el dueño ya
             tiene; esto responde a una que no sabe que tiene. Al fondo no lo vería nadie. --}}
        @if (filled($escapes) && ($escapes['total'] ?? 0) > 0)
            <div class="bmos-escape mb-5">
                <p class="font-semibold text-amber-900">Comentarios que se te escaparon</p>
                <p class="mt-1 text-sm text-amber-800">
                    <b>{{ number_format($escapes['total']) }}</b>
                    {{ $escapes['total'] === 1 ? 'comentario llegó' : 'comentarios llegaron' }}
                    y no coincidieron con ninguna de tus palabras
                    {{-- La ventana es obligatoria: sin ella se lee como histórico y el dueño concluye
                         que va casi perfecto cuando son siete días. --}}
                    @if (filled($escapes['retentionDays'] ?? null))(últimos {{ $escapes['retentionDays'] }} días)@endif.
                </p>

                <div class="mt-3 space-y-2">
                    @foreach (($escapes['samples'] ?? []) as $muestra)
                        <div class="bmos-escape-cita">
                            <p>«{{ $muestra['commentText'] ?? '' }}»</p>
                            <p class="mt-1 text-xs text-amber-700">
                                {{ $muestra['commenterName'] ?? 'Alguien' }}
                                @if (filled($muestra['excludedBy'] ?? null))
                                    {{-- No se escapó: lo vetó una regla. Ofrecer «añadir esta palabra»
                                         aquí haría que el dueño añadiera algo que nunca va a casar, y
                                         concluyera que el producto está roto. --}}
                                    · excluido por «{{ $muestra['excludedBy'] }}»
                                @endif
                            </p>
                        </div>
                    @endforeach
                </div>

                <p class="mt-3 text-xs text-amber-700">
                    Si alguno de estos debía recibir respuesta, añade su palabra en
                    <a href="{{ route('panel.social.automations') }}" class="underline">las palabras que la disparan</a>.
                </p>
            </div>
        @elseif ($s['triggered'] > 0)
            <p class="mb-5 text-sm text-emerald-600">Ningún comentario se quedó sin respuesta.</p>
        @endif

        {{-- Disparos por día.

             Solo cuando el registro que tenemos ES el histórico completo. Con más disparos de los que
             caben, el eje sería «los últimos días» y se leería como un desplome que en realidad es el
             borde de la ventana. --}}
        @if ($completo && array_sum($serie) > 0 && count($serie) > 1)
            <div class="bmos-card bmos-card-pad mb-5">
                <p class="font-semibold text-slate-800">Disparos por día</p>
                <div class="mt-4" x-data="disparosPorDia(@js(array_keys($porDia)), @js($serie))">
                    <div style="height:230px"><canvas x-ref="canvas"></canvas></div>
                </div>
            </div>
        @elseif (! $completo)
            <p class="mb-5 text-xs text-slate-400">
                Mostrando los últimos {{ number_format(count($logs)) }} de {{ number_format($total) }} disparos.
                No dibujamos la gráfica porque solo cubriría los más recientes y se leería como una caída.
            </p>
        @endif

        {{-- El registro --}}
        <div class="bmos-card overflow-hidden">
            <div class="border-b border-slate-100 p-4">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <p class="font-semibold text-slate-800">
                        Últimos disparos
                        @if ($estado !== null)
                            <span class="bmos-badge badge-gray ml-1">filtrado</span>
                        @endif
                    </p>
                    @if ($estado !== null)
                        <a href="{{ route('panel.social.automations.reporte', $a['id']) }}"
                           class="bmos-btn bmos-btn-ghost text-xs">Ver todos</a>
                    @endif
                </div>

                @if ($porEstado !== [])
                    @php $totalEstados = array_sum($porEstado); @endphp
                    <div class="bmos-barra-estados mt-3">
                        @foreach ($porEstado as $clave => $cuantos)
                            <span class="{{ $vocabulario[$clave][2] ?? 'estado-omitido' }}"
                                  style="width: {{ ($cuantos / $totalEstados) * 100 }}%"></span>
                        @endforeach
                    </div>
                    <div class="mt-2 flex flex-wrap gap-x-3 gap-y-1 text-xs text-slate-500">
                        @foreach ($porEstado as $clave => $cuantos)
                            <span>{{ $vocabulario[$clave][0] ?? $clave }}: <b>{{ $cuantos }}</b></span>
                        @endforeach
                    </div>
                @endif
            </div>

            @forelse ($logs as $log)
                @php
                    $clave = (string) ($log['status'] ?? '');
                    [$etiqueta, $insignia] = $vocabulario[$clave] ?? [$clave ?: '—', 'badge-gray'];
                @endphp
                <div class="border-b border-slate-50 p-4 last:border-0">
                    <div class="flex flex-wrap items-start justify-between gap-2">
                        <p class="font-medium text-slate-800">
                            {{ $log['commenterName'] ?? 'Alguien' }}
                            @if (($log['commenterFollowerCount'] ?? 0) > 0)
                                <span class="ml-1 text-xs font-normal text-slate-400">
                                    {{ number_format($log['commenterFollowerCount']) }} seguidores
                                </span>
                            @endif
                        </p>
                        <div class="flex flex-wrap items-center gap-1.5">
                            <span class="bmos-badge {{ $insignia }}">{{ $etiqueta }}</span>
                            {{-- Que salga el privado y falle la respuesta pública es un fallo distinto,
                                 y hasta ahora no se veía por ninguna parte. --}}
                            @if (($log['commentReplyStatus'] ?? null) === 'failed')
                                <span class="bmos-badge badge-amber">Respuesta pública falló</span>
                            @endif
                        </div>
                    </div>

                    @if (filled($log['commentText'] ?? null))
                        <p class="mt-1 text-sm text-slate-600">«{{ $log['commentText'] }}»</p>
                    @endif

                    {{-- Cada estado que no sea «enviado» necesita su explicación, o genera una
                         pregunta. «En espera» con su hora es lo que convierte «no funciona» en
                         «todavía no le toca». --}}
                    @if ($clave === 'pending')
                        <p class="mt-1 text-xs text-amber-600">
                            Todavía no le toca: esta respuesta espera antes de escribir.
                            @if (filled($log['nextDueAt'] ?? null))
                                Le escribe a las
                                {{ \Carbon\Carbon::parse($log['nextDueAt'])->timezone(config('app.timezone'))->format('H:i') }}.
                            @endif
                        </p>
                    @elseif ($clave === 'gated')
                        <p class="mt-1 text-xs text-violet-600">
                            Le mandamos la confirmación de seguimiento y estamos esperando que la pulse.
                        </p>
                    @elseif ($clave === 'skipped' && filled($log['audienceOutcome'] ?? null))
                        <p class="mt-1 text-xs text-slate-500">Motivo: {{ $log['audienceOutcome'] }}</p>
                    @endif

                    @if (filled($log['error'] ?? null))
                        <p class="mt-1 text-xs text-rose-600">{{ $log['error'] }}</p>
                    @endif
                    @if (filled($log['commentReplyError'] ?? null))
                        <p class="mt-1 text-xs text-amber-700">En el comentario: {{ $log['commentReplyError'] }}</p>
                    @endif

                    <p class="mt-1 flex flex-wrap gap-x-2 text-xs text-slate-400">
                        @if (filled($log['createdAt'] ?? null))
                            <span>{{ \Carbon\Carbon::parse($log['createdAt'])->timezone(config('app.timezone'))->format('d/m/Y H:i') }}</span>
                        @endif
                        {{-- De qué puerta vino: explica «esto no salió de un comentario». --}}
                        @if (($log['source'] ?? 'comment') !== 'comment')
                            <span>· {{ $log['source'] === 'dm' ? 'por privado' : 'respuesta a una historia' }}</span>
                        @endif
                        @if ($a['followGate'] && array_key_exists('commenterIsFollower', $log))
                            <span>· {{ $log['commenterIsFollower'] ? 'te sigue' : 'no te seguía' }}</span>
                        @endif
                    </p>
                </div>
            @empty
                {{-- Vacío no significa roto: puede que nadie haya comentado todavía. Se dice, porque
                     una pantalla en blanco se lee como un fallo. --}}
                <p class="bmos-empty">
                    @if ($estado !== null)
                        No hay disparos con ese estado.
                    @else
                        Todavía no se ha disparado. Si acabas de crearla, es lo normal: espera a que alguien
                        comente una de tus palabras.
                    @endif
                </p>
            @endforelse
        </div>
    </div>

    {{-- En línea y no en una pila: el layout del panel no tiene `@stack('scripts')`, así que un
         `@push` se tragaría el guion en silencio y la gráfica no se dibujaría nunca. Es como lo
         hacen Reportes y el Dashboard. --}}
    <script>
            /* Chart.js se carga BAJO DEMANDA: `window.loadChart()` lo trae en su propio archivo, para
               que no lastre las pantallas que no dibujan nada. Nunca un import estático. */
            function disparosPorDia(etiquetas, valores) {
                return {
                    chart: null,
                    async init() {
                        const Chart = await window.loadChart();
                        this.chart = new Chart(this.$refs.canvas, {
                            type: 'bar',
                            data: {
                                labels: etiquetas.map((d) => d.slice(8) + '/' + d.slice(5, 7)),
                                datasets: [{
                                    data: valores,
                                    backgroundColor: '#6366f1',
                                    borderRadius: 5,
                                    maxBarThickness: 34,
                                }],
                            },
                            options: {
                                responsive: true,
                                maintainAspectRatio: false,
                                animation: { duration: 900, easing: 'easeOutQuart' },
                                interaction: { intersect: false, mode: 'index' },
                                plugins: {
                                    legend: { display: false },
                                    tooltip: {
                                        backgroundColor: '#0f1220',
                                        padding: 10,
                                        cornerRadius: 8,
                                        callbacks: {
                                            label: (c) => ' ' + c.parsed.y + (c.parsed.y === 1 ? ' disparo' : ' disparos'),
                                        },
                                    },
                                },
                                scales: {
                                    y: {
                                        beginAtZero: true,
                                        grid: { color: '#eef0f6' },
                                        // Sin esto, un eje de «0,5 disparos» en volúmenes pequeños.
                                        ticks: { color: '#94a3b8', precision: 0 },
                                    },
                                    x: { grid: { display: false }, ticks: { color: '#94a3b8', maxTicksLimit: 8, autoSkip: true } },
                                },
                            },
                        });
                    },
                    destroy() { if (this.chart) this.chart.destroy(); },
                };
            }
    </script>
</x-layouts.admin>
