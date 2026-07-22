@php
    // Salud de cada secuencia: quedarse sin NCF a mitad de un día de ventas paraliza la caja.
    $sequenceHealth = function ($seq) {
        $remaining = max(0, $seq->range_to - $seq->next_number + 1);
        $expired = $seq->isExpired();
        $expiringSoon = ! $expired && $seq->expires_at !== null && now()->diffInDays($seq->expires_at, false) <= 30;

        return [
            'remaining' => $remaining,
            'badge' => match (true) {
                $expired => ['badge-red', 'Vencida'],
                $remaining === 0 => ['badge-red', 'Agotada'],
                $remaining <= 50 => ['badge-amber', 'Por agotarse'],
                $expiringSoon => ['badge-amber', 'Por vencer'],
                default => ['badge-green', 'Vigente'],
            },
        ];
    };
@endphp
<x-layouts.admin title="Facturación" heading="Facturación (DGII)" subheading="Comprobantes fiscales, secuencias de NCF y envíos 607/608">

    {{-- Secuencias autorizadas --}}
    <div class="bmos-card overflow-hidden">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 p-4">
            <div>
                <p class="font-semibold text-slate-800">Secuencias de NCF autorizadas</p>
                <p class="text-sm text-slate-500">Rangos concedidos por la DGII, con su fecha límite de emisión.</p>
            </div>
            @can('fiscal_sequences.manage')
            <x-panel.create-modal title="Registrar secuencia" label="Secuencia" form="sequence_create" :action="route('panel.sequences.store')">
                <div>
                    <label class="bmos-field-label">Tipo de comprobante</label>
                    <select name="type" class="bmos-input" required>
                        @foreach ($ncfTypes as $t)
                            <option value="{{ $t->value }}" @selected(old('type') === $t->value)>{{ $t->value }} — {{ $t->label() }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <x-panel.field name="range_from" label="Desde" type="number" value="1" required />
                    <x-panel.field name="range_to" label="Hasta" type="number" value="1000" required />
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <x-panel.field name="number_length" label="Longitud del número" type="number" value="8" required />
                    <x-panel.field name="expires_at" label="Fecha límite" type="date" required />
                </div>
            </x-panel.create-modal>
            @endcan
        </div>

        <div class="overflow-x-auto">
            <table class="bmos-table">
                <thead><tr><th>Tipo</th><th>Rango autorizado</th><th>Próximo NCF</th><th class="text-right">Restantes</th><th>Fecha límite</th><th>Estado</th></tr></thead>
                <tbody>
                    @forelse ($sequences as $seq)
                        @php
                            $health = $sequenceHealth($seq);
                            [$badgeClass, $badgeLabel] = $health['badge'];
                        @endphp
                        <tr>
                            <td><span class="bmos-badge badge-gray">{{ $seq->type->value }}</span> {{ $seq->type->label() }}</td>
                            <td class="font-mono text-xs text-slate-500">{{ $seq->range_from }} – {{ $seq->range_to }}</td>
                            <td class="font-mono text-xs font-semibold text-indigo-600">{{ $seq->formatNcf($seq->next_number) }}</td>
                            <td class="text-right font-semibold">{{ number_format($health['remaining']) }}</td>
                            <td class="text-slate-500">{{ $seq->expires_at?->format('d/m/Y') ?? '—' }}</td>
                            <td><span class="bmos-badge {{ $badgeClass }}">{{ $badgeLabel }}</span></td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="bmos-empty">Sin secuencias registradas: sin una secuencia activa no se puede emitir ningún comprobante.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- Envíos DGII --}}
    <div class="mt-5 bmos-card bmos-card-pad">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <p class="font-semibold text-slate-800">Envíos de datos a la DGII</p>
                <p class="text-sm text-slate-500">Formato 607 (ventas del período) y 608 (comprobantes anulados).</p>
            </div>
            <form method="GET" class="flex flex-wrap items-end gap-2">
                <div>
                    <label class="bmos-field-label">Período</label>
                    <input type="month" name="period" value="{{ $period }}" class="bmos-input">
                </div>
                <button type="submit" class="bmos-btn bmos-btn-ghost">Aplicar</button>
                <a href="{{ route('panel.dgii.607', ['period' => $period]) }}" class="bmos-btn bmos-btn-primary">Descargar 607</a>
                <a href="{{ route('panel.dgii.608', ['period' => $period]) }}" class="bmos-btn bmos-btn-ghost">Descargar 608</a>
            </form>
        </div>
    </div>

    {{-- Comprobantes --}}
    <div class="mt-5 bmos-card overflow-hidden" x-data="{ cancelling: null }">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 p-4">
            <p class="font-semibold text-slate-800">Comprobantes emitidos</p>
            <div class="flex flex-wrap items-center gap-3">
                <x-panel.search-bar placeholder="Buscar por NCF o cliente..." />
                <x-panel.export-button route="panel.export.invoices" />

                @can('invoices.issue')
                <x-panel.create-modal title="Emitir comprobante" label="Emitir" form="invoice_issue" :action="route('panel.invoices.issue')">
                    <div>
                        <label class="bmos-field-label">Venta a facturar</label>
                        <select name="sale_id" class="bmos-input" required>
                            @forelse ($invoiceableSales as $sale)
                                <option value="{{ $sale->id }}" @selected(old('sale_id') == $sale->id)>
                                    {{ $sale->code }} — {{ number_format((float) $sale->total, 2) }} — {{ $sale->customer_name ?? 'Consumidor final' }}
                                </option>
                            @empty
                                <option value="" disabled>No hay ventas completadas sin facturar</option>
                            @endforelse
                        </select>
                    </div>
                    <div>
                        <label class="bmos-field-label">Tipo de comprobante</label>
                        <select name="type" class="bmos-input" required>
                            @foreach ($ncfTypes as $t)
                                <option value="{{ $t->value }}" @selected(old('type', 'B02') === $t->value)>
                                    {{ $t->value }} — {{ $t->label() }}{{ $t->requiresTaxId() ? ' (exige RNC)' : '' }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <x-panel.field name="customer_tax_id" label="RNC / Cédula del cliente" placeholder="130123456" />
                    <p class="text-xs text-slate-400">
                        Crédito Fiscal y Gubernamental exigen un RNC válido (se comprueba el dígito verificador).
                        En Consumo es opcional: sin él, la factura sale como consumidor final.
                    </p>
                </x-panel.create-modal>
                @endcan
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="bmos-table">
                <thead>
                    <tr>
                        <th>NCF</th><th>Tipo</th><th>Cliente</th><th>RNC/Cédula</th>
                        <th class="text-right">Subtotal</th><th class="text-right">ITBIS</th><th class="text-right">Total</th>
                        <th>Estado</th><th>Emitida</th><th class="text-right">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($invoices as $invoice)
                        <tr>
                            <td class="font-mono text-xs font-semibold text-indigo-600">{{ $invoice->ncf }}</td>
                            <td><span class="bmos-badge badge-gray">{{ $invoice->type->value }}</span></td>
                            <td>{{ $invoice->customer_name ?? 'Consumidor final' }}</td>
                            <td class="font-mono text-xs text-slate-500">{{ $invoice->customer_tax_id ?? '—' }}</td>
                            <td class="text-right">{{ number_format((float) $invoice->subtotal, 2) }}</td>
                            <td class="text-right">{{ number_format((float) $invoice->tax, 2) }}</td>
                            <td class="text-right font-semibold">{{ number_format((float) $invoice->total, 2) }}</td>
                            <td>
                                <span class="bmos-badge {{ $invoice->status->badge() }}">{{ $invoice->status->label() }}</span>
                                @if ($invoice->isCancelled())
                                    <p class="mt-0.5 text-xs text-slate-400">{{ $invoice->cancellation_code?->label() }}</p>
                                @endif
                            </td>
                            <td class="text-slate-400">{{ $invoice->issued_at?->format('d/m/Y H:i') }}</td>
                            <td class="text-right">
                                @unless ($invoice->isCancelled())
                                    @can('invoices.cancel')
                                    <button type="button" class="rounded-lg p-1.5 text-slate-400 hover:bg-rose-50 hover:text-rose-600" title="Anular"
                                            @click="cancelling = { id: {{ $invoice->id }}, ncf: @js($invoice->ncf) }">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" style="width:1.1rem;height:1.1rem"><path stroke-linecap="round" stroke-linejoin="round" d="M9.75 9.75l4.5 4.5m0-4.5-4.5 4.5M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/></svg>
                                    </button>
                                    @endcan
                                @endunless
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="10" class="bmos-empty">Aún no hay comprobantes emitidos.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- Anulación: la DGII exige un código de motivo y el NCF queda inutilizado. --}}
        <div x-show="cancelling" x-cloak class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-900/50 p-4 py-10" @keydown.escape.window="cancelling = null">
            <div @click.outside="cancelling = null" x-transition class="w-full max-w-lg rounded-2xl bg-white p-6 shadow-2xl">
                <div class="mb-1 flex items-center justify-between">
                    <h3 class="text-lg font-semibold text-slate-800">Anular comprobante</h3>
                    <button type="button" @click="cancelling = null" class="text-slate-400 hover:text-slate-600">✕</button>
                </div>
                <p class="mb-4 text-sm text-slate-500">
                    Vas a anular <span class="font-mono font-semibold text-slate-700" x-text="cancelling?.ncf"></span>.
                    El NCF <strong>no se reutiliza</strong>: queda inutilizado y se reportará en el formato 608.
                </p>

                <form method="POST" :action="`{{ url('panel/facturas') }}/${cancelling?.id}/anular`" class="space-y-3">
                    @csrf
                    <div>
                        <label class="bmos-field-label">Motivo de anulación (código DGII)</label>
                        <select name="reason" class="bmos-input" required>
                            @foreach ($cancellationReasons as $reason)
                                <option value="{{ $reason->value }}">{{ $reason->value }} — {{ $reason->label() }}</option>
                            @endforeach
                        </select>
                    </div>
                    <x-panel.field name="note" label="Nota interna (opcional)" placeholder="Detalle del motivo" />
                    <div class="flex justify-end gap-2 pt-3">
                        <button type="button" @click="cancelling = null" class="bmos-btn bmos-btn-ghost">Volver</button>
                        <button type="submit" class="bmos-btn bmos-btn-primary">Anular comprobante</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="mt-4">{{ $invoices->links() }}</div>
</x-layouts.admin>
