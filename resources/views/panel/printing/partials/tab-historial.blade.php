{{--
    Historial de impresiones: quién imprimió qué, cuándo, en cuál impresora y cómo salió.

    Los filtros viajan por la URL —como el resto del panel— para que se puedan copiar y compartir, y
    porque cambiar de pestaña con `x-show` no debe perder lo que ya se filtró.
--}}
<div class="bmos-card overflow-hidden">
    <form method="GET" class="flex flex-wrap items-end gap-3 border-b border-slate-100 p-4">
        <input type="hidden" name="tab_actual" value="historial">
        <div>
            <label class="bmos-field-label">Desde</label>
            <input type="date" name="desde" value="{{ request('desde') }}" class="bmos-input">
        </div>
        <div>
            <label class="bmos-field-label">Hasta</label>
            <input type="date" name="hasta" value="{{ request('hasta') }}" class="bmos-input">
        </div>
        <div>
            <label class="bmos-field-label">Usuario</label>
            <select name="user_id" class="bmos-input">
                <option value="">Todos</option>
                @foreach ($usuariosConHistorial as $u)
                    <option value="{{ $u->id }}" @selected(request('user_id') == $u->id)>{{ $u->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="bmos-field-label">Impresora</label>
            <select name="printer_id" class="bmos-input">
                <option value="">Todas</option>
                @foreach ($printers as $printer)
                    <option value="{{ $printer->id }}" @selected(request('printer_id') == $printer->id)>{{ $printer->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="bmos-field-label">Tipo de documento</label>
            <select name="document_type" class="bmos-input">
                <option value="">Todos</option>
                @foreach ($documentTypeGroups as $categoria => $tipos)
                    <optgroup label="{{ $documentTypeCategories[$categoria] }}">
                        @foreach ($tipos as $clave => $etiqueta)
                            <option value="{{ $clave }}" @selected(request('document_type') === $clave)>{{ $etiqueta }}</option>
                        @endforeach
                    </optgroup>
                @endforeach
            </select>
        </div>
        <button type="submit" class="bmos-btn bmos-btn-primary">Filtrar</button>
        @if (request()->hasAny(['desde', 'hasta', 'user_id', 'printer_id', 'document_type']))
            <a href="{{ route('panel.printing.index') }}" class="bmos-btn bmos-btn-ghost text-xs">Quitar filtros</a>
        @endif
    </form>

    <div class="overflow-x-auto">
        <table class="bmos-table bmos-tabla-tarjetas">
            <thead>
                <tr>
                    <th>Documento</th><th>Usuario</th><th>Fecha</th><th>Impresora</th>
                    <th class="text-right">Copias</th><th>Estado</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($historial as $job)
                    <tr>
                        <td data-rotulo="Documento" class="font-medium text-slate-700">{{ $job->tipoLegible() }}</td>
                        <td data-rotulo="Usuario">{{ $job->user?->name ?? '—' }}</td>
                        <td data-rotulo="Fecha">{{ $job->created_at?->format('d/m/Y H:i') }}</td>
                        <td data-rotulo="Impresora">{{ $job->printer?->name ?? 'Diálogo del navegador' }}</td>
                        <td data-rotulo="Copias" class="text-right tabular-nums">{{ $job->copies }}</td>
                        <td data-rotulo="Estado">
                            <span class="bmos-badge {{ $job->status->badgeClass() }}">{{ $job->status->label() }}</span>
                            @if ($job->status->value === 'error' && $job->error_message)
                                <p class="mt-0.5 text-xs text-rose-500">{{ $job->error_message }}</p>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="bmos-empty">Todavía no se ha impreso nada.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
<div class="mt-4">{{ $historial->links() }}</div>
