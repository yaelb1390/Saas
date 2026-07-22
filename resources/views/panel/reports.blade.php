@php
    use Illuminate\Support\Carbon;

    $cards = [
        ['Ventas (histórico)', number_format((float) $summary['sales_total'], 2), $summary['sales_count'].' ventas completadas', 'tone-emerald'],
        ['Balance de caja', number_format((float) $summary['cash_balance'], 2), 'Efectivo en cuentas', 'tone-indigo'],
        ['Oportunidades abiertas', (string) $summary['open_opportunities'], 'Pipeline del CRM', 'tone-violet'],
        ['Entregas pendientes', (string) $summary['pending_deliveries'], 'En logística', 'tone-amber'],
        ['Productos', (string) $summary['products'], 'En catálogo', 'tone-sky'],
        ['Stock bajo', (string) $summary['low_stock'], 'Requieren reposición', 'tone-rose'],
    ];

    $days = $report['days'];
    $chartLabels = array_map(fn ($d) => Carbon::parse($d)->format('d/m'), array_keys($days));
    $chartValues = array_map(fn ($v) => (float) $v, array_values($days));

    $topProducts = array_slice($report['top_products'], 0, 6);
    $topLabels = array_map(fn ($p) => $p['name'], $topProducts);
    $topValues = array_map(fn ($p) => (float) $p['total'], $topProducts);
@endphp
<x-layouts.admin title="Reportes" heading="Reporte ejecutivo" subheading="Indicadores del negocio y ventas por período">
    {{-- KPIs históricos --}}
    <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 xl:grid-cols-6">
        @foreach ($cards as [$label, $value, $hint, $tone])
            <div class="bmos-stat">
                <div class="bmos-stat-icon {{ $tone }}">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 0 1 3 19.875v-6.75ZM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V8.625ZM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V4.125Z"/></svg>
                </div>
                <p class="bmos-stat-label">{{ $label }}</p>
                <p class="bmos-stat-value">{{ $value }}</p>
                <p class="mt-1 text-xs text-slate-400">{{ $hint }}</p>
            </div>
        @endforeach
    </div>

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

        <div class="mt-5 grid grid-cols-1 gap-4 sm:grid-cols-3">
            <div class="rounded-xl bg-slate-50 p-4">
                <p class="bmos-stat-label">Total vendido</p>
                <p class="text-2xl font-bold text-emerald-600" data-testid="report-total">{{ number_format((float) $report['total'], 2) }}</p>
            </div>
            <div class="rounded-xl bg-slate-50 p-4">
                <p class="bmos-stat-label">N.º de ventas</p>
                <p class="text-2xl font-bold text-indigo-600">{{ $report['count'] }}</p>
            </div>
            <div class="rounded-xl bg-slate-50 p-4">
                <p class="bmos-stat-label">Ticket promedio</p>
                <p class="text-2xl font-bold text-slate-800">{{ number_format((float) $report['avg_ticket'], 2) }}</p>
            </div>
        </div>

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
                render() {
                    if (this.chart) this.chart.destroy();
                    const ctx = this.$refs.canvas.getContext('2d');
                    const grad = ctx.createLinearGradient(0, 0, 0, 230);
                    grad.addColorStop(0, 'rgba(99,102,241,0.85)');
                    grad.addColorStop(1, this.type === 'bar' ? 'rgba(79,70,229,0.55)' : 'rgba(99,102,241,0.03)');
                    const isArea = this.type === 'area';
                    this.chart = new window.Chart(ctx, {
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
                init() {
                    const ctx = this.$refs.canvas.getContext('2d');
                    const palette = ['#6366f1', '#8b5cf6', '#ec4899', '#f59e0b', '#10b981', '#0ea5e9'];
                    this.chart = new window.Chart(ctx, {
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
