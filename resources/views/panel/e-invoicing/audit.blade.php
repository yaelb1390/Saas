{{--
    Auditoría de facturación electrónica de toda la empresa: cada acción y cambio de estado de cada
    e-CF, con usuario, IP y fecha. Las filas solo se añaden; no se pueden editar ni borrar.
    Diseño con daisyUI (clases `d-`, tema «bmia» limitado a este contenedor: ver app.css).
--}}
<x-layouts.admin :back="route('panel.e-invoicing')" :back-label="'Facturación Electrónica'"
                 title="Auditoría e-CF" heading="Auditoría"
                 subheading="Cada acción sobre los comprobantes electrónicos, con quién y cuándo">
    <div data-theme="bmia" class="bmos-ecf mx-auto max-w-6xl space-y-4">
        <form method="GET" class="bmos-card bmos-card-pad grid grid-cols-1 gap-2 sm:grid-cols-3 lg:grid-cols-6 lg:items-end">
            <div>
                <label class="bmos-field-label" for="au-encf">e-NCF</label>
                <input id="au-encf" name="encf" value="{{ request('encf') }}" class="bmos-input" placeholder="E31…">
            </div>
            <div>
                <label class="bmos-field-label" for="au-usuario">Usuario</label>
                <select id="au-usuario" name="usuario" class="bmos-input">
                    <option value="">Todos</option>
                    @foreach ($usuarios as $u)
                        <option value="{{ $u->id }}" @selected((string) request('usuario') === (string) $u->id)>{{ $u->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="bmos-field-label" for="au-accion">Acción</label>
                <select id="au-accion" name="accion" class="bmos-input">
                    <option value="">Todas</option>
                    @foreach ($acciones as $a)
                        <option value="{{ $a }}" @selected(request('accion') === $a)>{{ $a }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="bmos-field-label" for="au-desde">Desde</label>
                <input id="au-desde" type="date" name="desde" value="{{ request('desde') }}" class="bmos-input">
            </div>
            <div>
                <label class="bmos-field-label" for="au-hasta">Hasta</label>
                <input id="au-hasta" type="date" name="hasta" value="{{ request('hasta') }}" class="bmos-input">
            </div>
            <button type="submit" class="d-btn d-btn-primary">Filtrar</button>
        </form>

        <div class="bmos-card overflow-hidden">
            <div class="overflow-x-auto">
                <table class="bmos-table bmos-tabla-tarjetas">
                    <thead>
                        <tr><th>Fecha</th><th>e-NCF</th><th>Acción</th><th>Estado</th><th>Usuario</th><th>IP</th></tr>
                    </thead>
                    <tbody>
                        @forelse ($filas ?? [] as $f)
                            <tr>
                                <td data-rotulo="Fecha" class="whitespace-nowrap text-slate-500">{{ $f->created_at?->format('d/m/Y H:i:s') }}</td>
                                <td data-rotulo="e-NCF" class="font-mono text-xs">
                                    @if ($f->electronic_invoice_id)
                                        <a href="{{ route('panel.e-invoicing.documents.show', $f->electronic_invoice_id) }}" class="d-link d-link-primary font-semibold">{{ $f->e_ncf }}</a>
                                    @else
                                        {{ $f->e_ncf ?? '—' }}
                                    @endif
                                </td>
                                <td data-rotulo="Acción" class="text-slate-800">{{ $f->action }}</td>
                                <td data-rotulo="Estado" class="text-xs text-slate-500">
                                    @if ($f->from_status || $f->to_status)
                                        <span class="inline-flex flex-wrap items-center gap-1">
                                            <span class="d-badge d-badge-sm d-badge-ghost">{{ $f->from_status ?? '—' }}</span> →
                                            <span class="d-badge d-badge-sm d-badge-primary d-badge-soft">{{ $f->to_status ?? '—' }}</span>
                                        </span>
                                    @else — @endif
                                </td>
                                <td data-rotulo="Usuario">{{ $f->user?->name ?? 'Sistema' }}</td>
                                <td data-rotulo="IP" class="font-mono text-xs text-slate-400">{{ $f->ip ?? '—' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="bmos-empty">Sin registros.</td></tr>
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
