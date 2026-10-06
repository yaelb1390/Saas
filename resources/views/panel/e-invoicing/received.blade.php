{{--
    Comprobantes que otros contribuyentes enviaron a la empresa (como receptora) y el acuse de recibo
    que se les devolvió [Descripción Técnica Servicios Emisores Electrónicos].
    Diseño con daisyUI (clases `d-`, tema «bmia» limitado a este contenedor: ver app.css).
--}}
<x-layouts.admin :back="route('panel.e-invoicing')" :back-label="'Facturación Electrónica'"
                 title="Comprobantes recibidos" heading="Comprobantes recibidos"
                 subheading="Los e-CF que te envían tus proveedores, con su acuse de recibo">
    <div data-theme="bmia" class="bmos-ecf mx-auto max-w-6xl space-y-4">
        @if ($urls)
            <div role="alert" class="d-alert d-alert-soft d-alert-info block">
                <p class="font-semibold">Tus direcciones de recepción</p>
                <p class="mt-1 text-xs opacity-80">
                    Regístralas en la Oficina Virtual de la DGII para que tus proveedores te envíen sus e-CF y sus
                    aprobaciones comerciales. Son únicas de tu empresa: no las compartas fuera de ese registro.
                </p>
                <dl class="mt-3 space-y-2 text-sm">
                    @foreach (['recepcion' => 'Recepción de e-CF', 'aprobacion' => 'Aprobación comercial', 'autenticacion' => 'Autenticación (semilla)'] as $clave => $rotulo)
                        <div>
                            <dt class="text-xs font-medium opacity-80">{{ $rotulo }}</dt>
                            <dd class="flex items-center gap-2 break-all font-mono text-xs" x-data="{ copiado: false }">
                                <span>{{ $urls[$clave] }}</span>
                                <button type="button" class="d-btn d-btn-xs d-btn-primary d-btn-soft shrink-0"
                                        @click="navigator.clipboard?.writeText(@js($urls[$clave])); copiado = true; setTimeout(() => copiado = false, 1500)"
                                        x-text="copiado ? '¡Copiado!' : 'Copiar'">Copiar</button>
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
            <button type="submit" class="d-btn d-btn-primary">Filtrar</button>
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
                                        <x-panel.ecf-badge tono="badge-green">Recibido</x-panel.ecf-badge>
                                    @else
                                        <x-panel.ecf-badge tono="badge-red">No recibido</x-panel.ecf-badge>
                                        <span class="block text-xs text-slate-500">{{ \App\Modules\ElectronicInvoicing\Models\ElectronicReceivedDocument::MOTIVOS[$f->receipt_reason] ?? '' }}</span>
                                    @endif
                                </td>
                                <td data-rotulo="Aprobación comercial">
                                    @if ($f->approval_status === 1)
                                        <x-panel.ecf-badge tono="badge-green">Aceptado</x-panel.ecf-badge>
                                    @elseif ($f->approval_status === 2)
                                        <x-panel.ecf-badge tono="badge-red">Rechazado</x-panel.ecf-badge>
                                        <span class="block text-xs text-slate-500">{{ $f->approval_reason }}</span>
                                    @elseif ($f->receipt_status === \App\Modules\ElectronicInvoicing\Models\ElectronicReceivedDocument::RECIBIDO)
                                        @can('ecf.issue')
                                            <div x-data="{ rechazar: false }" class="space-y-1">
                                                <form method="POST" action="{{ route('panel.e-invoicing.received.approve', $f) }}" class="inline">
                                                    @csrf
                                                    <input type="hidden" name="decision" value="aceptar">
                                                    <button type="submit" class="d-btn d-btn-xs d-btn-success d-btn-soft">Aceptar</button>
                                                </form>
                                                <button type="button" class="d-btn d-btn-xs d-btn-error d-btn-soft ml-1" @click="rechazar = ! rechazar">Rechazar</button>
                                                <form x-show="rechazar" x-cloak method="POST" action="{{ route('panel.e-invoicing.received.approve', $f) }}" class="flex gap-1">
                                                    @csrf
                                                    <input type="hidden" name="decision" value="rechazar">
                                                    <input name="motivo" maxlength="250" required class="bmos-input py-1 text-xs" placeholder="Motivo del rechazo">
                                                    <button type="submit" class="d-btn d-btn-xs d-btn-error">Enviar</button>
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
                                        <a href="{{ route('panel.e-invoicing.received.file', [$f, 'ecf']) }}" class="d-btn d-btn-xs d-btn-ghost">e-CF</a>
                                        <a href="{{ route('panel.e-invoicing.received.file', [$f, 'arecf']) }}" class="d-btn d-btn-xs d-btn-ghost">Acuse</a>
                                        @if ($f->acecf_path)
                                            <a href="{{ route('panel.e-invoicing.received.file', [$f, 'acecf']) }}" class="d-btn d-btn-xs d-btn-ghost">Aprobación</a>
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
