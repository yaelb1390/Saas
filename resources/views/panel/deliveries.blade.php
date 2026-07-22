@php
    $delBadge = fn ($status) => match ($status->value) {
        'delivered' => 'badge-green', 'failed' => 'badge-red',
        'in_transit' => 'badge-blue', 'assigned' => 'badge-violet', default => 'badge-amber',
    };
@endphp
<x-layouts.admin title="Entregas" heading="Entregas" subheading="Reparto y seguimiento de estados">
    <div class="bmos-card overflow-hidden">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 p-4">
            <p class="font-semibold text-slate-800">Entregas</p>
            <x-panel.search-bar placeholder="Buscar por código, cliente o repartidor..." />
        </div>
        <div class="overflow-x-auto">
            <table class="bmos-table">
                <thead><tr><th>Código</th><th>Cliente</th><th>Dirección</th><th>Repartidor</th><th>Estado</th><th>Actualizado</th></tr></thead>
                <tbody>
                    @forelse ($deliveries as $delivery)
                        <tr>
                            <td class="font-mono text-xs font-semibold text-indigo-600">{{ $delivery->code }}</td>
                            <td>{{ $delivery->customer_name ?? '—' }}</td>
                            <td class="text-slate-500">{{ $delivery->address }}</td>
                            <td>{{ $delivery->driver_name ?? '—' }}</td>
                            <td><span class="bmos-badge {{ $delBadge($delivery->status) }}">{{ $delivery->status->label() }}</span></td>
                            <td class="text-slate-400">{{ $delivery->updated_at?->format('d/m/Y H:i') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="bmos-empty">Sin entregas registradas.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    <div class="mt-4">{{ $deliveries->links() }}</div>
</x-layouts.admin>
