{{--
    Facturación Electrónica — los e-CF emitidos por la empresa, con su estado ante la DGII.
--}}
<x-layouts.admin title="Documentos electrónicos" heading="Documentos electrónicos"
                 subheading="Los e-CF emitidos y su estado ante la DGII">
    <div class="mx-auto max-w-6xl space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <a href="{{ route('panel.e-invoicing') }}" class="text-sm text-slate-500 hover:text-slate-700">← Facturación Electrónica</a>
        </div>

        <form method="GET" class="bmos-card bmos-card-pad flex flex-wrap items-end gap-2">
            <div class="min-w-0 flex-1">
                <label class="bmos-field-label" for="doc-q">Buscar</label>
                <input id="doc-q" name="q" value="{{ request('q') }}" placeholder="e-NCF, cliente o RNC" class="bmos-input">
            </div>
            <div>
                <label class="bmos-field-label" for="doc-estado">Estado</label>
                <select id="doc-estado" name="estado" class="bmos-input">
                    <option value="">Todos</option>
                    @foreach ($estados as $e)
                        <option value="{{ $e->value }}" @selected(request('estado') === $e->value)>{{ $e->label() }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="bmos-field-label" for="doc-tipo">Tipo</label>
                <select id="doc-tipo" name="tipo" class="bmos-input">
                    <option value="">Todos</option>
                    @foreach ($tipos as $t)
                        <option value="{{ $t->value }}" @selected((string) request('tipo') === (string) $t->value)>{{ $t->value }} · {{ $t->label() }}</option>
                    @endforeach
                </select>
            </div>
            <button type="submit" class="bmos-btn bmos-btn-ghost">Filtrar</button>
        </form>

        <div class="bmos-card overflow-hidden">
            <div class="overflow-x-auto">
                <table class="bmos-table bmos-tabla-tarjetas">
                    <thead>
                        <tr>
                            <th>e-NCF</th><th>Tipo</th><th>Cliente / proveedor</th><th>Fecha</th>
                            <th class="text-right">Total</th><th>Ambiente</th><th>Estado</th><th class="text-right"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($documentos ?? [] as $d)
                            <tr>
                                <td data-rotulo="e-NCF" class="font-mono text-xs font-semibold text-slate-800">{{ $d->e_ncf }}</td>
                                <td data-rotulo="Tipo">{{ $d->ecf_type->value }} · {{ $d->ecf_type->label() }}</td>
                                <td data-rotulo="Cliente / proveedor">
                                    {{ $d->buyer_name ?: 'Consumidor final' }}
                                    @if ($d->buyer_tax_id)<span class="block font-mono text-xs text-slate-400">{{ $d->buyer_tax_id }}</span>@endif
                                </td>
                                <td data-rotulo="Fecha" class="text-slate-500">{{ $d->issue_date?->format('d/m/Y') }}</td>
                                <td data-rotulo="Total" class="text-right font-semibold">{{ number_format((float) $d->total, 2) }}</td>
                                <td data-rotulo="Ambiente" class="text-slate-500">{{ $d->environment->label() }}</td>
                                <td data-rotulo="Estado"><span class="bmos-badge {{ $d->status->badge() }}">{{ $d->status->label() }}</span></td>
                                <td class="text-right">
                                    <a href="{{ route('panel.e-invoicing.documents.show', $d) }}" class="text-sm font-medium text-indigo-600 hover:text-indigo-700">Ver</a>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="8" class="bmos-empty">Todavía no hay documentos electrónicos.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        @if ($documentos)
            <div>{{ $documentos->links() }}</div>
        @endif
    </div>
</x-layouts.admin>
