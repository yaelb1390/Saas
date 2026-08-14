@php
    $saleBadge = fn ($status) => match ($status->value) {
        'completed' => 'badge-green', 'cancelled' => 'badge-red', default => 'badge-gray',
    };
@endphp
<x-layouts.admin title="Ventas" heading="Ventas" subheading="Historial de ventas y su estado">
    @php $puedeAnular = auth()->user()?->can('sales.void') ?? false; @endphp

    <div class="bmos-card overflow-hidden" x-data="ventasSeleccion(@js($sales->pluck('id')->all()))">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 p-4">
            <p class="font-semibold text-slate-800">Ventas</p>
            <div class="flex flex-wrap items-center gap-3">
                <x-panel.search-bar placeholder="Buscar por código o cliente..." />
                <x-panel.export-button route="panel.export.sales" />
            </div>
        </div>

        @if ($puedeAnular)
            {{-- Solo aparece con algo marcado: una barra permanente sobre el historial de ventas
                 invitaría a deshacer cobros por descuido. --}}
            <div x-show="marcados.length > 0" x-cloak
                 class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 bg-rose-50/70 px-4 py-3">
                <span class="text-sm text-slate-700">
                    <span class="font-semibold" x-text="etiqueta()"></span>
                    <span class="text-slate-500">— se devolverá el stock y se retirará el cobro de la caja</span>
                </span>
                <button type="button" @click="confirmar()"
                        class="inline-flex items-center gap-2 rounded-xl bg-rose-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-rose-700">
                    Anular
                </button>
            </div>

            <form method="POST" action="{{ route('panel.sales.bulk-void') }}" id="anular_ventas" class="hidden">
                @csrf @method('DELETE')
                <template x-for="id in marcados" :key="id">
                    <input type="hidden" name="ids[]" :value="id">
                </template>
            </form>
        @endif

        <div class="overflow-x-auto">
            <table class="bmos-table">
                <thead>
                    <tr>
                        @if ($puedeAnular)
                            <th class="w-10">
                                <input type="checkbox" @change="alternarPagina($event.target.checked)"
                                       :checked="paginaCompleta()" aria-label="Seleccionar todas"
                                       class="rounded border-slate-300 text-indigo-600">
                            </th>
                        @endif
                        <th>Código</th><th>Cliente</th><th>Líneas</th><th>Subtotal</th><th>Total</th><th>Pago</th><th>Estado</th><th>Fecha</th><th class="text-right">Recibo</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($sales as $sale)
                        <tr :class="marcados.includes({{ $sale->id }}) ? 'bg-rose-50/40' : ''">
                            @if ($puedeAnular)
                                <td>
                                    <input type="checkbox" value="{{ $sale->id }}" x-model.number="marcados"
                                           aria-label="Seleccionar {{ $sale->code }}"
                                           class="rounded border-slate-300 text-indigo-600">
                                </td>
                            @endif
                            <td class="font-mono text-xs font-semibold text-indigo-600">{{ $sale->code }}</td>
                            <td>{{ $sale->customer_name ?? 'Consumidor final' }}</td>
                            <td>{{ $sale->items_count }}</td>
                            <td>{{ number_format((float) $sale->subtotal, 2) }}</td>
                            <td class="font-semibold">{{ number_format((float) $sale->total, 2) }}</td>
                            <td><span class="bmos-badge badge-blue">{{ ucfirst($sale->payment_method) }}</span></td>
                            <td><span class="bmos-badge {{ $saleBadge($sale->status) }}">{{ $sale->status->label() }}</span></td>
                            <td class="text-slate-400">{{ $sale->created_at?->format('d/m/Y H:i') }}</td>
                            <td class="text-right">
                                <a href="{{ route('panel.sales.receipt', $sale) }}" target="_blank" rel="noopener"
                                   class="inline-flex items-center gap-1 rounded-lg px-2 py-1 text-indigo-600 hover:bg-indigo-50" title="Ver recibo">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" style="width:1.1rem;height:1.1rem"><path stroke-linecap="round" stroke-linejoin="round" d="M6.72 13.829c-.24.03-.48.062-.72.096m.72-.096a42.415 42.415 0 0 1 10.56 0m-10.56 0L6.34 18m10.94-4.171c.24.03.48.062.72.096m-.72-.096L17.66 18m0 0 .229 2.523a1.125 1.125 0 0 1-1.12 1.227H7.231c-.662 0-1.18-.568-1.12-1.227L6.34 18m11.318 0h1.091A2.25 2.25 0 0 0 21 15.75V9.456c0-1.081-.768-2.015-1.837-2.175a48.055 48.055 0 0 0-1.913-.247M6.34 18H5.25A2.25 2.25 0 0 1 3 15.75V9.456c0-1.081.768-2.015 1.837-2.175a48.041 48.041 0 0 1 1.913-.247m10.5 0a48.536 48.536 0 0 0-10.5 0m10.5 0V3.375c0-.621-.504-1.125-1.125-1.125h-8.25c-.621 0-1.125.504-1.125 1.125v3.659M18 10.5h.008v.008H18V10.5Zm-3 0h.008v.008H15V10.5Z"/></svg>
                                    Recibo
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="{{ $puedeAnular ? 10 : 9 }}" class="bmos-empty">Aún no hay ventas registradas.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    <div class="mt-4">{{ $sales->links() }}</div>

    <script>
        function ventasSeleccion(enPagina) {
            return {
                marcados: [],
                enPagina,

                paginaCompleta() {
                    return this.enPagina.length > 0 && this.enPagina.every((id) => this.marcados.includes(id));
                },

                alternarPagina(marcar) {
                    this.marcados = marcar ? [...this.enPagina] : [];
                },

                etiqueta() {
                    const n = this.marcados.length;
                    return n === 1 ? '1 venta seleccionada' : `${n} ventas seleccionadas`;
                },

                async confirmar() {
                    // A diferencia del inventario, aquí SIEMPRE se pide teclear la cantidad: anular
                    // una venta mueve dinero y existencias, y no hay «pocas» que no importen.
                    await window.confirmarAnularVentas({
                        cantidad: this.marcados.length,
                        formulario: 'anular_ventas',
                    });
                },
            };
        }
    </script>
</x-layouts.admin>
