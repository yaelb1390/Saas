{{--
    Comprobantes que otros contribuyentes enviaron a la empresa (como receptora) y el acuse de recibo
    que se les devolvió [Descripción Técnica Servicios Emisores Electrónicos].
--}}
<x-layouts.admin title="Comprobantes recibidos" heading="Comprobantes recibidos"
                 subheading="Los e-CF que te envían tus proveedores, con su acuse de recibo">
    <div class="mx-auto max-w-6xl space-y-4">
        <a href="{{ route('panel.e-invoicing') }}" class="text-sm text-slate-500 hover:text-slate-700">← Facturación Electrónica</a>

        @if ($urls)
            <div class="bmos-card bmos-card-pad">
                <p class="font-semibold text-slate-800">Tus direcciones de recepción</p>
                <p class="mt-1 text-xs text-slate-500">
                    Regístralas en la Oficina Virtual de la DGII para que tus proveedores te envíen sus e-CF y sus
                    aprobaciones comerciales. Son únicas de tu empresa: no las compartas fuera de ese registro.
                </p>
                <dl class="mt-3 space-y-2 text-sm">
                    @foreach (['recepcion' => 'Recepción de e-CF', 'aprobacion' => 'Aprobación comercial', 'autenticacion' => 'Autenticación (semilla)'] as $clave => $rotulo)
                        <div>
                            <dt class="text-xs text-slate-500">{{ $rotulo }}</dt>
                            <dd class="break-all font-mono text-xs text-slate-800" x-data>
                                <span>{{ $urls[$clave] }}</span>
                                <button type="button" class="ml-1 text-indigo-600 hover:text-indigo-700"
                                        @click="navigator.clipboard?.writeText(@js($urls[$clave]))">Copiar</button>
                            </dd>
                        </div>
                    @endforeach
                </dl>
            </div>
        @endif

        <form method="GET" class="bmos-card bmos-card-pad flex flex-wrap items-end gap-2">
            <div class="min-w-0 flex-1">
                <label class="bmos-field-label" for="rx-q">Buscar</label>
                <input id="rx-q" name="q" value="{{ request('q') }}" placeholder="e-NCF, proveedor o RNC" class="bmos-input">
            </div>
            <button type="submit" class="bmos-btn bmos-btn-ghost">Filtrar</button>
        </form>

        <div class="bmos-card overflow-hidden">
            <div class="overflow-x-auto">
                <table class="bmos-table bmos-tabla-tarjetas">
                    <thead>
                        <tr><th>Recibido</th><th>Proveedor</th><th>e-NCF</th><th class="text-right">Total</th><th>Acuse</th><th>Aprobación comercial</th><th class="text-right"></th></tr>
                    </thead>
                    <tbody>
                        @forelse ($filas ?? [] as $f)
                            <tr>
                                <td data-rotulo="Recibido" class="whitespace-nowrap text-slate-500">{{ $f->created_at?->format('d/m/Y H:i') }}</td>
                                <td data-rotulo="Proveedor">
                                    {{ $f->emitter_name ?? '—' }}
                                    @if ($f->emitter_tax_id)<span class="block font-mono text-xs text-slate-400">{{ $f->emitter_tax_id }}</span>@endif
                                </td>
                                <td data-rotulo="e-NCF" class="font-mono text-xs">{{ $f->e_ncf ?? '—' }}</td>
                                <td data-rotulo="Total" class="text-right">{{ $f->total !== null ? number_format((float) $f->total, 2) : '—' }}</td>
                                <td data-rotulo="Acuse">
                                    @if ($f->receipt_status === \App\Modules\ElectronicInvoicing\Models\ElectronicReceivedDocument::RECIBIDO)
                                        <span class="bmos-badge badge-green">Recibido</span>
                                    @else
                                        <span class="bmos-badge badge-red">No recibido</span>
                                        <span class="block text-xs text-slate-500">{{ \App\Modules\ElectronicInvoicing\Models\ElectronicReceivedDocument::MOTIVOS[$f->receipt_reason] ?? '' }}</span>
                                    @endif
                                </td>
                                <td data-rotulo="Aprobación comercial">
                                    @if ($f->approval_status === 1)
                                        <span class="bmos-badge badge-green">Aceptado</span>
                                    @elseif ($f->approval_status === 2)
                                        <span class="bmos-badge badge-red">Rechazado</span>
                                        <span class="block text-xs text-slate-500">{{ $f->approval_reason }}</span>
                                    @elseif ($f->receipt_status === \App\Modules\ElectronicInvoicing\Models\ElectronicReceivedDocument::RECIBIDO)
                                        @can('ecf.issue')
                                            <div x-data="{ rechazar: false }" class="space-y-1">
                                                <form method="POST" action="{{ route('panel.e-invoicing.received.approve', $f) }}" class="inline">
                                                    @csrf
                                                    <input type="hidden" name="decision" value="aceptar">
                                                    <button type="submit" class="text-sm font-medium text-emerald-700 hover:text-emerald-800">Aceptar</button>
                                                </form>
                                                <button type="button" class="ml-2 text-sm font-medium text-rose-600 hover:text-rose-700" @click="rechazar = ! rechazar">Rechazar</button>
                                                <form x-show="rechazar" x-cloak method="POST" action="{{ route('panel.e-invoicing.received.approve', $f) }}" class="flex gap-1">
                                                    @csrf
                                                    <input type="hidden" name="decision" value="rechazar">
                                                    <input name="motivo" maxlength="250" required class="bmos-input py-1 text-xs" placeholder="Motivo del rechazo">
                                                    <button type="submit" class="bmos-btn bmos-btn-ghost py-1 text-xs">Enviar</button>
                                                </form>
                                            </div>
                                        @else
                                            <span class="text-xs text-slate-400">Pendiente</span>
                                        @endcan
                                    @else
                                        <span class="text-xs text-slate-400">—</span>
                                    @endif
                                    @if ($f->approval_error)
                                        <span class="block text-xs text-rose-600">{{ $f->approval_error }}</span>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap text-right text-sm">
                                    @can('ecf.download')
                                        <a href="{{ route('panel.e-invoicing.received.file', [$f, 'ecf']) }}" class="text-indigo-600 hover:text-indigo-700">e-CF</a>
                                        <a href="{{ route('panel.e-invoicing.received.file', [$f, 'arecf']) }}" class="ml-2 text-indigo-600 hover:text-indigo-700">Acuse</a>
                                        @if ($f->acecf_path)
                                            <a href="{{ route('panel.e-invoicing.received.file', [$f, 'acecf']) }}" class="ml-2 text-indigo-600 hover:text-indigo-700">Aprobación</a>
                                        @endif
                                    @endcan
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="bmos-empty">Todavía no has recibido comprobantes electrónicos.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        @if ($filas)
            <div>{{ $filas->links() }}</div>
        @endif
    </div>
</x-layouts.admin>
