{{--
    Números de Social Commerce. Ver DashboardController por qué «Instagram → WhatsApp» no está: el
    enlace wa.me no pasa por nuestro servidor, así que un clic ahí no deja rastro que se pueda contar.
--}}
<x-layouts.admin title="Social Commerce · Dashboard" heading="Social Commerce"
                 subheading="Lo que han hecho tus reglas desde que las encendiste">
    <div class="mx-auto max-w-4xl">
        <div class="mb-5 flex flex-wrap gap-4">
            <a href="{{ route('panel.social-commerce.index') }}" class="text-sm text-indigo-600 hover:underline">← Reglas</a>
            <a href="{{ route('panel.social-commerce.conversations.index') }}" class="text-sm text-indigo-600 hover:underline">Conversaciones</a>
        </div>

        <div class="mb-5">
            @include('partials.cifras', ['fichas' => [
                ['tone-amber', 'encendida', $reglasActivas, $reglasActivas === 1 ? 'regla activa' : 'reglas activas'],
                ['tone-indigo', 'comentario', $conversaciones, $conversaciones === 1 ? 'persona te escribió' : 'personas te escribieron'],
                ['tone-violet', 'mensaje', $mensajesEntrantes, 'mensajes recibidos'],
                ['tone-emerald', 'personas', $clientesEnlazados, 'enlazados al CRM'],
                [$oportunidades > 0 ? 'tone-emerald' : 'es-neutra', 'dinero', $oportunidades, $oportunidades === 1 ? 'oportunidad' : 'oportunidades'],
            ]])
        </div>

        @if ($reglas === 0)
            <div class="bmos-card bmos-card-pad text-center">
                <p class="text-slate-600">Todavía no has creado ninguna regla.</p>
                <a href="{{ route('panel.social-commerce.create') }}" class="mt-2 inline-block text-sm text-indigo-600 hover:underline">Crear la primera →</a>
            </div>
        @else
            @if (array_sum($porDia) > 0)
                <div class="bmos-card bmos-card-pad mb-5">
                    <p class="font-semibold text-slate-800">Comentarios recibidos por día</p>
                    <div class="mt-4" x-data="interaccionesPorDia(@js(array_keys($porDia)), @js(array_values($porDia)))">
                        <div style="height:220px"><canvas x-ref="canvas"></canvas></div>
                    </div>
                </div>
            @endif

            @if ($porProducto !== [])
                <div class="bmos-card bmos-card-pad">
                    <p class="font-semibold text-slate-800">Productos más consultados</p>
                    <div class="mt-3 space-y-2">
                        @php $tope = max($porProducto); @endphp
                        @foreach ($porProducto as $producto => $cuantos)
                            <div>
                                <div class="flex justify-between text-sm text-slate-600">
                                    <span>{{ $producto }}</span>
                                    <span>{{ $cuantos }}</span>
                                </div>
                                <div class="mt-0.5 h-1.5 rounded-full bg-slate-100">
                                    <div class="h-1.5 rounded-full bg-indigo-500" style="width: {{ ($cuantos / $tope) * 100 }}%"></div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            @if ($valorOportunidades > 0)
                <p class="mt-4 text-center text-sm text-slate-500">
                    Valor de las oportunidades abiertas desde aquí: <b>{{ number_format($valorOportunidades, 2) }}</b>
                </p>
            @endif
        @endif
    </div>

    <script>
        function interaccionesPorDia(etiquetas, valores) {
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
                                        label: (c) => ' ' + c.parsed.y + (c.parsed.y === 1 ? ' comentario' : ' comentarios'),
                                    },
                                },
                            },
                            scales: {
                                y: { beginAtZero: true, grid: { color: '#eef0f6' }, ticks: { color: '#94a3b8', precision: 0 } },
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
