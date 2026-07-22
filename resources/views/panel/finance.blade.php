<x-layouts.admin title="Finanzas" heading="Finanzas" subheading="Cuentas y movimientos financieros">
    <div class="grid grid-cols-1 gap-5 sm:grid-cols-2 xl:grid-cols-4">
        @foreach ($accounts as $account)
            <div class="bmos-stat">
                <div class="bmos-stat-icon tone-emerald">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 0 0 2.25-2.25V6.75A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25v10.5A2.25 2.25 0 0 0 4.5 19.5Z"/>
                    </svg>
                </div>
                <p class="bmos-stat-label">{{ $account->name }} · {{ $account->type->label() }}</p>
                <p class="bmos-stat-value text-emerald-600">{{ number_format((float) $account->balance, 2) }}</p>
            </div>
        @endforeach
    </div>

    <div class="mt-6 bmos-card overflow-hidden">
        <div class="border-b border-slate-100 p-4"><p class="font-semibold text-slate-800">Movimientos</p></div>
        <div class="overflow-x-auto">
            <table class="bmos-table">
                <thead><tr><th>Fecha</th><th>Cuenta</th><th>Tipo</th><th>Descripción</th><th class="text-right">Importe</th></tr></thead>
                <tbody>
                    @forelse ($movements as $mov)
                        @php $isIncome = $mov->type->value === 'income'; @endphp
                        <tr>
                            <td class="text-slate-400">{{ $mov->occurred_at?->format('d/m/Y H:i') }}</td>
                            <td>{{ $mov->account?->name }}</td>
                            <td><span class="bmos-badge {{ $isIncome ? 'badge-green' : 'badge-amber' }}">{{ $mov->type->label() }}</span></td>
                            <td>{{ $mov->description ?? '—' }}</td>
                            <td class="text-right font-semibold {{ $isIncome ? 'text-emerald-600' : 'text-rose-600' }}">
                                {{ number_format((float) $mov->amount, 2) }}
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="bmos-empty">Sin movimientos.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="p-4">{{ $movements->links() }}</div>
    </div>
</x-layouts.admin>
