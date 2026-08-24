@php
    use App\Modules\Core\Support\Tendencia;
    use Illuminate\Support\Carbon;

    /*
     * Las cifras del histórico, ya con la forma que pide <x-panel.metricas>.
     *
     * Con claves y no por posición: antes cada tarjeta era un array de seis elementos sueltos y había
     * que traducirlo abajo ($c[4] es el tono, $c[5] el icono), lo que además escondía que dos de esos
     * seis —un texto de detalle y una clase de color— ya no los pintaba nadie desde que estas cifras
     * pasaron al componente común. Aquí ya no hay nada que traducir ni nada muerto.
     *
     * Cada una lleva SU icono, no el mismo repetido: un dibujo igual en las seis no distingue una
     * tarjeta de la de al lado, solo ocupa el sitio donde va el número.
     */
    /*
     * QUÉ SIGNIFICA CRECER, cifra por cifra. No es un detalle de color: es el dato.
     *
     * Que suban las ventas es bueno; que suban los productos sin existencia es malo. Si la tendencia
     * se pintara de verde por el mero hecho de crecer, un almacén vaciándose se leería como una
     * buena noticia. Por eso cada tarjeta lo declara y Tendencia no lo adivina.
     *
     * «Entregas pendientes» y «stock bajo» no llevan tendencia, y no por olvido: no hay forma de
     * reconstruir cuántas había hace un mes. Ver ReportService::computeExecutiveTrends().
     */
    $cambio = function (string $clave, ?bool $subeEsBueno, string $que, bool $dinero = false) use ($trends, $trendDays): ?array {
        if (! isset($trends[$clave])) {
            return null;
        }

        $comoTexto = fn (float $v): string => $dinero ? money($v) : number_format($v);
        $antes = (float) $trends[$clave]['antes'];
        $ahora = (float) $trends[$clave]['ahora'];

        return Tendencia::calcular($antes, $ahora, $subeEsBueno, detalle: sprintf(
            '%s: %s hoy frente a %s hace %d días.',
            $que, $comoTexto($ahora), $comoTexto($antes), $trendDays,
        ));
    };

    $cards = [
        ['etiqueta' => 'Ventas (histórico)', 'valor' => number_format((float) $summary['sales_total'], 2), 'tono' => 'verde', 'icono' => 'receipt',
            'tendencia' => $cambio('sales_total', true, 'Ventas acumuladas', dinero: true)],
        ['etiqueta' => 'Balance de caja', 'valor' => number_format((float) $summary['cash_balance'], 2), 'tono' => 'indigo', 'icono' => 'cash',
            'tendencia' => $cambio('cash_balance', true, 'Efectivo en cuentas', dinero: true)],
        ['etiqueta' => 'Oportunidades abiertas', 'valor' => (string) $summary['open_opportunities'], 'tono' => 'violeta', 'icono' => 'target',
            'tendencia' => $cambio('open_opportunities', true, 'Oportunidades sin cerrar')],
        ['etiqueta' => 'Entregas pendientes', 'valor' => (string) $summary['pending_deliveries'], 'tono' => 'ambar', 'icono' => 'truck'],
        ['etiqueta' => 'Productos', 'valor' => (string) $summary['products'], 'tono' => 'azul', 'icono' => 'cube',
            'tendencia' => $cambio('products', true, 'Productos en catálogo')],
        ['etiqueta' => 'Stock bajo', 'valor' => (string) $summary['low_stock'], 'tono' => 'rojo', 'icono' => 'alert'],
    ];

    $days = $report['days'];
    $chartLabels = array_map(fn ($d) => Carbon::parse($d)->format('d/m'), array_keys($days));
    $chartValues = array_map(fn ($v) => (float) $v, array_values($days));

    $topProducts = array_slice($report['top_products'], 0, 6);
    $topLabels = array_map(fn ($p) => $p['name'], $topProducts);
    $topValues = array_map(fn ($p) => (float) $p['total'], $topProducts);
@endphp
<x-layouts.admin title="Reportes" heading="Reporte ejecutivo" subheading="Indicadores del negocio y ventas por período">
    {{-- La respuesta del período, antes que ninguna cifra suelta. Es lo que se viene a saber. --}}
    <x-panel.estado class="mb-4" tono="ok"
        :titulo="money($report['total']).' vendidos en el período'"
        :nota="$report['count'].' '.($report['count'] === 1 ? 'venta' : 'ventas')
            .' · ticket promedio '.money($report['avg_ticket'])
            .' · del '.Carbon::parse($from)->format('d/m/Y').' al '.Carbon::parse($to)->format('d/m/Y')" />

    {{-- Las cifras del histórico.
         Antes eran seis tarjetas con EL MISMO icono de barras repetido seis veces, que no distinguía
         una de otra ni decía nada: solo ocupaba el sitio donde va el número. Ahora el color hace ese
         trabajo, y se apaga a gris cuando el valor es cero. --}}
    <x-panel.metricas :items="$cards" :columnas="3" />

    {{-- Ventas por período --}}
    <div class="mt-8 bmos-card bmos-card-pad">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <h2 class="text-lg font-semibold text-slate-800">Ventas por período</h2>
                <p class="text-sm text-slate-500">Del {{ Carbon::parse($from)->format('d/m/Y') }} al {{ Carbon::parse($to)->format('d/m/Y') }}</p>
            </div>
            <div class="flex flex-wrap items-end gap-2">
                <form method="GET" class="flex flex-wrap items-end gap-2">
                    <div><label class="bmos-field-label">Desde</label><input type="date" name="from" value="{{ $from }}" class="bmos-input"></div>
                    <div><label class="bmos-field-label">Hasta</label><input type="date" name="to" value="{{ $to }}" class="bmos-input"></div>
                    <button type="submit" class="bmos-btn bmos-btn-primary">Aplicar</button>
                </form>
                <x-panel.export-button route="panel.export.sales-report" />
            </div>
        </div>

        {{-- Aquí había tres recuadros con el total, el número de ventas y el ticket promedio.
             Son exactamente los tres datos que ya dice la tira de arriba, así que repetirlos solo
             obligaba a leer dos veces lo mismo para acabar en el mismo sitio. --}}

        <div class="mt-6" x-data="salesChart(@js($chartLabels), @js($chartValues))">
            <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                <p class="text-sm font-semibold text-slate-600">Ventas por día</p>
                <div class="inline-flex rounded-lg bg-slate-100 p-1">
                    <template x-for="opt in types" :key="opt.k">
                        <button type="button" @click="setType(opt.k)"
                                class="rounded-md px-3 py-1 text-xs font-semibold transition"
                                :class="type === opt.k ? 'bg-white text-indigo-600 shadow-sm' : 'text-slate-500 hover:text-slate-700'"
                                x-text="opt.t"></button>
                    </template>
                </div>
            </div>
            <div style="height:230px"><canvas x-ref="canvas"></canvas></div>
        </div>
    </div>

    {{-- Top productos --}}
    <div class="mt-6 grid grid-cols-1 gap-6 xl:grid-cols-3">
        {{-- Dónut de participación --}}
        <div class="bmos-card bmos-card-pad xl:col-span-1">
            <p class="font-semibold text-slate-800">Participación por producto</p>
            <p class="text-sm text-slate-500">Importe vendido en el período</p>
            @if (count($topValues) > 0 && array_sum($topValues) > 0)
                <div class="mt-4" x-data="topChart(@js($topLabels), @js($topValues))">
                    <div style="height:230px"><canvas x-ref="canvas"></canvas></div>
                </div>
            @else
                <div class="mt-4 flex h-[230px] items-center justify-center text-sm text-slate-400">Sin datos en el período.</div>
            @endif
        </div>

        {{-- Tabla detallada --}}
        <div class="bmos-card overflow-hidden xl:col-span-2">
            <div class="border-b border-slate-100 p-4"><p class="font-semibold text-slate-800">Productos más vendidos (período)</p></div>
            <div class="overflow-x-auto">
                <table class="bmos-table">
                    <thead><tr><th>#</th><th>Producto</th><th class="text-right">Cantidad</th><th class="text-right">Importe</th></tr></thead>
                    <tbody>
                        @forelse ($report['top_products'] as $i => $p)
                            <tr>
                                <td class="text-slate-400">{{ $i + 1 }}</td>
                                <td class="font-medium text-slate-800">{{ $p['name'] }}</td>
                                <td class="text-right">{{ rtrim(rtrim(number_format((float) $p['qty'], 3), '0'), '.') }}</td>
                                <td class="text-right font-semibold">{{ number_format((float) $p['total'], 2) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="bmos-empty">No hubo ventas en el período seleccionado.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script>
        function salesChart(labels, data) {
            return {
                type: 'bar',
                chart: null,
                types: [
                    { k: 'bar', t: 'Barras' },
                    { k: 'line', t: 'Línea' },
                    { k: 'area', t: 'Área' },
                ],
                init() {
                    this.render();
                },
                setType(t) {
                    if (t === this.type) return;
                    this.type = t;
                    this.render();
                },
                async render() {
                    const Chart = await window.loadChart();
                    if (this.chart) this.chart.destroy();
                    const ctx = this.$refs.canvas.getContext('2d');
                    const grad = ctx.createLinearGradient(0, 0, 0, 230);
                    grad.addColorStop(0, 'rgba(99,102,241,0.85)');
                    grad.addColorStop(1, this.type === 'bar' ? 'rgba(79,70,229,0.55)' : 'rgba(99,102,241,0.03)');
                    const isArea = this.type === 'area';
                    this.chart = new Chart(ctx, {
                        type: isArea ? 'line' : this.type,
                        data: {
                            labels,
                            datasets: [{
                                label: 'Ventas',
                                data,
                                backgroundColor: grad,
                                borderColor: '#4f46e5',
                                borderWidth: this.type === 'bar' ? 0 : 2.5,
                                borderRadius: 5,
                                fill: isArea,
                                tension: 0.38,
                                pointRadius: this.type === 'bar' ? 0 : 2,
                                pointHoverRadius: 5,
                                pointBackgroundColor: '#4f46e5',
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
                                    backgroundColor: '#0f1220', padding: 10, cornerRadius: 8,
                                    callbacks: { label: (c) => ' ' + Number(c.parsed.y).toLocaleString('es', { minimumFractionDigits: 2 }) },
                                },
                            },
                            scales: {
                                y: { beginAtZero: true, grid: { color: '#eef0f6' }, ticks: { color: '#94a3b8' } },
                                x: { grid: { display: false }, ticks: { color: '#94a3b8', maxTicksLimit: 8, autoSkip: true } },
                            },
                        },
                    });
                },
            };
        }

        function topChart(labels, data) {
            return {
                chart: null,
                async init() {
                    const Chart = await window.loadChart();
                    const ctx = this.$refs.canvas.getContext('2d');
                    const palette = ['#6366f1', '#8b5cf6', '#ec4899', '#f59e0b', '#10b981', '#0ea5e9'];
                    this.chart = new Chart(ctx, {
                        type: 'doughnut',
                        data: {
                            labels,
                            datasets: [{
                                data,
                                backgroundColor: palette,
                                borderColor: '#ffffff',
                                borderWidth: 2,
                                hoverOffset: 8,
                            }],
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            cutout: '62%',
                            animation: { animateRotate: true, duration: 900, easing: 'easeOutQuart' },
                            plugins: {
                                legend: { position: 'bottom', labels: { color: '#64748b', boxWidth: 10, padding: 12, font: { size: 11 } } },
                                tooltip: {
                                    backgroundColor: '#0f1220', padding: 10, cornerRadius: 8,
                                    callbacks: {
                                        label: (c) => {
                                            const total = c.dataset.data.reduce((a, b) => a + Number(b), 0) || 1;
                                            const pct = (Number(c.parsed) / total * 100).toFixed(1);
                                            return ' ' + Number(c.parsed).toLocaleString('es', { minimumFractionDigits: 2 }) + ' (' + pct + '%)';
                                        },
                                    },
                                },
                            },
                        },
                    });
                },
            };
        }
    </script>
</x-layouts.admin>
