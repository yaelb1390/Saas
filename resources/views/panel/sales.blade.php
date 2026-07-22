@php
    $saleBadge = fn ($status) => match ($status->value) {
        'completed' => 'badge-green', 'cancelled' => 'badge-red', default => 'badge-gray',
    };
@endphp
<x-layouts.admin title="Ventas" heading="Ventas" subheading="Historial de ventas y su estado">
    <div class="bmos-card overflow-hidden">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 p-4">
            <p class="font-semibold text-slate-800">Ventas</p>
            <div class="flex flex-wrap items-center gap-3">
                <x-panel.search-bar placeholder="Buscar por código o cliente..." />
                <x-panel.export-button route="panel.export.sales" />
            </div>
        </div>
        <div class="overflow-x-auto">
            <table class="bmos-table">
                <thead>
                    <tr><th>Código</th><th>Cliente</th><th>Líneas</th><th>Subtotal</th><th>Total</th><th>Pago</th><th>Estado</th><th>Fecha</th><th class="text-right">Recibo</th></tr>
                </thead>
                <tbody>
                    @forelse ($sales as $sale)
                        <tr>
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
                        <tr><td colspan="9" class="bmos-empty">Aún no hay ventas registradas.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    <div class="mt-4">{{ $sales->links() }}</div>
</x-layouts.admin>
