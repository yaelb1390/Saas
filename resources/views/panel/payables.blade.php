<x-layouts.admin title="Cuentas por Pagar" heading="Cuentas por Pagar"
                subheading="Lo que el negocio todavía le debe a sus proveedores">

    <div class="bmos-card overflow-hidden shadow-lg" x-data="{ editando: null }">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 p-4">
            <div class="flex flex-wrap items-center gap-2">
                <x-panel.search-bar placeholder="Buscar por código o proveedor...">
                    <select name="estado" onchange="this.form.submit()" class="bmos-input">
                        <option value="">Todos los estados</option>
                        @foreach ($statuses as $s)
                            <option value="{{ $s->value }}" @selected(request('estado') === $s->value)>{{ $s->label() }}</option>
                        @endforeach
                    </select>
                    <label class="flex items-center gap-1.5 text-sm text-slate-600">
                        <input type="checkbox" name="vencidas" value="1" onchange="this.form.submit()"
                               @checked(request()->boolean('vencidas')) class="rounded border-slate-300 text-indigo-600">
                        Solo vencidas
                    </label>
                </x-panel.search-bar>
                <x-panel.export-button route="panel.export.payables" />
            </div>

            @can('finance.manage')
            <x-panel.create-modal title="Nueva cuenta por pagar" label="Cuenta por pagar"
                                   form="payable_create" :action="route('panel.payables.store')">
                <div>
                    <label class="bmos-field-label">Proveedor</label>
                    <select name="supplier_id" class="bmos-input">
                        <option value="">— Sin ficha de proveedor —</option>
                        @foreach ($proveedores as $proveedor)
                            <option value="{{ $proveedor->id }}">{{ $proveedor->name }}</option>
                        @endforeach
                    </select>
                </div>
                <x-panel.field name="supplier_name" label="Nombre (si no eligió proveedor)" placeholder="Distribuidora XYZ" />
                <x-panel.field name="total" label="Monto" type="number" step="0.01" required />
                <x-panel.field name="due_date" label="Vencimiento" type="date" />
                <div>
                    <label class="bmos-field-label">Notas</label>
                    <textarea name="notes" rows="2" class="bmos-input"></textarea>
                </div>
            </x-panel.create-modal>
            @endcan
        </div>

        <div class="overflow-x-auto">
            <table class="bmos-table bmos-tabla-tarjetas">
                <thead>
                    <tr>
                        <th>Código</th><th>Proveedor</th><th class="text-right">Total</th>
                        <th class="text-right">Saldo</th><th>Vencimiento</th><th>Estado</th><th class="text-right">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($cuentas as $cuenta)
                        <tr>
                            <td data-rotulo="Código">
                                <a href="{{ route('panel.payables.show', $cuenta) }}" class="font-mono text-xs font-semibold text-indigo-600 hover:underline">
                                    {{ $cuenta->code }}
                                </a>
                            </td>
                            <td data-rotulo="Proveedor">{{ $cuenta->supplier_name ?? $cuenta->supplier?->name ?? '—' }}</td>
                            <td data-rotulo="Total" class="text-right">{{ number_format((float) $cuenta->total, 2) }}</td>
                            <td data-rotulo="Saldo" class="text-right font-semibold">{{ number_format((float) $cuenta->balance, 2) }}</td>
                            <td data-rotulo="Vencimiento" class="text-slate-500">
                                {{ $cuenta->due_date?->format('d/m/Y') ?? '—' }}
                                @if ($cuenta->estaVencida())
                                    <span class="bmos-badge badge-red">Vencida</span>
                                @endif
                            </td>
                            <td data-rotulo="Estado"><span class="bmos-badge {{ $cuenta->status->badge() }}">{{ $cuenta->status->label() }}</span></td>
                            <td class="text-right">
                                <div class="flex justify-end gap-1.5">
                                    <a href="{{ route('panel.payables.show', $cuenta) }}"
                                       class="bmos-btn border border-indigo-200 bg-indigo-50 text-indigo-700 hover:bg-indigo-100">Ver</a>
                                    @can('finance.manage')
                                        <button type="button" class="bmos-btn border border-sky-200 bg-sky-50 text-sky-700 hover:bg-sky-100"
                                                @click="editando = { id: {{ $cuenta->id }}, supplier_name: @js($cuenta->supplier_name), total: '{{ $cuenta->total }}', due_date: @js(optional($cuenta->due_date)->toDateString()), notes: @js($cuenta->notes), url: '{{ route('panel.payables.update', $cuenta) }}' }">
                                            Editar
                                        </button>
                                        @if ($cuenta->payments_count > 0)
                                            {{-- Ya tiene pagos: se avisa de una vez en vez de dejar que intente y falle. --}}
                                            <button type="button"
                                                    onclick="window.avisoRapido('Esta cuenta ya tiene pagos registrados: no se puede eliminar sin perder ese historial.')"
                                                    class="bmos-btn border border-rose-100 bg-rose-50/60 text-rose-400 hover:bg-rose-50">
                                                Eliminar
                                            </button>
                                        @else
                                            <x-panel.confirm-action
                                                :action="route('panel.payables.destroy', $cuenta)"
                                                :title="'¿Eliminar la cuenta '.$cuenta->code.'?'"
                                                message="Todavía no tiene pagos registrados, así que se elimina por completo."
                                                class="bmos-btn border border-rose-200 bg-rose-50 text-rose-700 hover:bg-rose-100">
                                                Eliminar
                                            </x-panel.confirm-action>
                                        @endif
                                    @endcan
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="bmos-empty">No hay cuentas por pagar con estos filtros.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div x-show="editando" x-cloak class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-900/50 p-4 py-10" @keydown.escape.window="editando = null">
            <div @click.outside="editando = null" x-transition class="w-full max-w-lg rounded-2xl bg-white p-6 shadow-2xl">
                <div class="mb-4 flex items-center justify-between">
                    <h3 class="text-lg font-semibold text-slate-800">Editar cuenta por pagar</h3>
                    <button type="button" @click="editando = null" class="text-slate-400 hover:text-slate-600">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-5 w-5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>
                <form method="POST" :action="editando?.url" class="space-y-3">
                    @csrf @method('PUT')
                    <div>
                        <label class="bmos-field-label">Nombre del proveedor</label>
                        <input type="text" name="supplier_name" class="bmos-input" x-bind:value="editando?.supplier_name">
                    </div>
                    <div>
                        <label class="bmos-field-label">Monto</label>
                        <input type="number" step="0.01" name="total" class="bmos-input" x-bind:value="editando?.total">
                        <p class="mt-1 text-xs text-slate-400">Solo se puede cambiar si la cuenta todavía no tiene abonos.</p>
                    </div>
                    <div>
                        <label class="bmos-field-label">Vencimiento</label>
                        <input type="date" name="due_date" class="bmos-input" x-bind:value="editando?.due_date">
                    </div>
                    <div>
                        <label class="bmos-field-label">Notas</label>
                        <textarea name="notes" rows="2" class="bmos-input" x-text="editando?.notes"></textarea>
                    </div>
                    <div class="flex justify-end gap-2 pt-3">
                        <button type="button" @click="editando = null" class="bmos-btn bmos-btn-ghost">Cancelar</button>
                        <button type="submit" class="bmos-btn bmos-btn-primary">Guardar cambios</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="mt-4">{{ $cuentas->links() }}</div>
</x-layouts.admin>
