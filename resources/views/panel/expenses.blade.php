@php
    $filtroConcepto = request('concepto');
    $filtroCuenta = request('cuenta');
    $hayFiltro = $filtroConcepto || $filtroCuenta || request('q');
    $mayor = $porConcepto->max('total') ?: 1;
@endphp

<x-layouts.admin title="Gastos" heading="Gastos"
                 subheading="En qué se va el dinero del negocio">
    <div>
        {{-- Rango de fechas. Por defecto el mes en curso, que es como la gente piensa sus gastos. --}}
        <form method="GET" class="mb-5 flex flex-wrap items-end gap-3">
            <div>
                <label class="bmos-field-label">Desde</label>
                <input type="date" name="desde" value="{{ $desde->toDateString() }}" class="bmos-input">
            </div>
            <div>
                <label class="bmos-field-label">Hasta</label>
                <input type="date" name="hasta" value="{{ $hasta->toDateString() }}" class="bmos-input">
            </div>
            <div>
                <label class="bmos-field-label">Concepto</label>
                <select name="concepto" class="bmos-input">
                    <option value="">Todos</option>
                    @foreach ($categories as $c)
                        <option value="{{ $c->id }}" @selected($filtroConcepto == $c->id)>{{ $c->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="bmos-field-label">Cuenta</label>
                <select name="cuenta" class="bmos-input">
                    <option value="">Todas</option>
                    @foreach ($accounts as $a)
                        <option value="{{ $a->id }}" @selected($filtroCuenta == $a->id)>{{ $a->name }}</option>
                    @endforeach
                </select>
            </div>
            <button type="submit" class="bmos-btn bmos-btn-primary">Ver</button>
            @if ($hayFiltro)
                <a href="{{ route('panel.expenses') }}" class="bmos-btn bmos-btn-ghost text-xs">Quitar filtros</a>
            @endif
        </form>

        {{-- El total del período y el desglose por concepto.

             Esto es la pantalla; la tabla de abajo es el detalle. Una lista de gastos sin agrupar no
             contesta «¿en qué se me va el dinero?», que es la única pregunta que se le hace a esta
             sección. --}}
        <div class="mb-6 grid grid-cols-1 gap-5 lg:grid-cols-3">
            <div class="bmos-card bmos-card-pad">
                <p class="bmos-stat-label">Total del período</p>
                <p class="mt-1 text-3xl font-bold text-rose-600">{{ money($total) }}</p>
                <p class="mt-1 text-xs text-slate-400">
                    {{ $desde->format('d/m/Y') }} — {{ $hasta->format('d/m/Y') }}
                    · {{ $expenses->total() }} {{ $expenses->total() === 1 ? 'gasto' : 'gastos' }}
                </p>

                @if ($sesionAbierta && $hayCuentaEfectivo)
                    <p class="mt-4 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800">
                        Hay un <b>turno de caja abierto</b>. Lo que pagues desde una cuenta de efectivo
                        se descontará también del arqueo.
                    </p>
                @endif
            </div>

            <div class="bmos-card bmos-card-pad lg:col-span-2">
                <p class="font-semibold text-slate-800">Por concepto</p>
                @if ($porConcepto->isEmpty())
                    <p class="mt-2 text-sm text-slate-500">Sin gastos en este período.</p>
                @else
                    <div class="mt-3 space-y-2">
                        @foreach ($porConcepto as $fila)
                            @php
                                // Barra proporcional al concepto que más pesa, no al total: con un
                                // concepto que se lleva el 80 %, medir sobre el total dejaría a los
                                // demás como líneas invisibles y no se podrían comparar entre sí.
                                $ancho = max(2, round(((float) $fila->total / (float) $mayor) * 100));
                                $parte = (float) $total > 0 ? ((float) $fila->total / (float) $total) * 100 : 0;
                            @endphp
                            <a href="{{ route('panel.expenses', array_merge(request()->query(), ['concepto' => $fila->expense_category_id])) }}"
                               class="block rounded-lg px-2 py-1.5 transition hover:bg-slate-50">
                                <div class="flex items-baseline justify-between gap-3 text-sm">
                                    <span class="font-medium text-slate-700">{{ $fila->category?->name ?? 'Sin concepto' }}</span>
                                    <span class="shrink-0 font-semibold text-slate-800">
                                        {{ money($fila->total) }}
                                        <span class="ml-1 text-xs font-normal text-slate-400">{{ number_format($parte, 0) }}%</span>
                                    </span>
                                </div>
                                <div class="mt-1 h-1.5 w-full overflow-hidden rounded-full bg-slate-100">
                                    <div class="h-full rounded-full bg-rose-400" style="width: {{ $ancho }}%"></div>
                                </div>
                            </a>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>

        <div class="bmos-card overflow-hidden">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 p-4">
                <p class="font-semibold text-slate-800">Detalle</p>
                <div class="flex flex-wrap items-center gap-3">
                    <x-panel.search-bar placeholder="Buscar por descripción, código o proveedor..." />
                    @can('finance.manage')
                        <x-panel.create-modal title="Nuevo gasto" label="Nuevo gasto" form="expense_create"
                                              :action="route('panel.expenses.store')" width="max-w-2xl">
                            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                <div class="sm:col-span-2">
                                    <label class="bmos-field-label">¿En qué? <span class="text-rose-500">*</span></label>
                                    <input type="text" name="description" value="{{ old('description') }}" class="bmos-input"
                                           placeholder="Factura de luz de agosto" required>
                                </div>
                                <div>
                                    <label class="bmos-field-label">Concepto <span class="text-rose-500">*</span></label>
                                    <select name="expense_category_id" class="bmos-input" required>
                                        @foreach ($categories as $c)
                                            <option value="{{ $c->id }}" @selected(old('expense_category_id') == $c->id)>{{ $c->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div>
                                    <label class="bmos-field-label">Monto (RD$) <span class="text-rose-500">*</span></label>
                                    <input type="number" step="0.01" min="0" name="amount" value="{{ old('amount') }}" class="bmos-input" required>
                                </div>
                                <div>
                                    <label class="bmos-field-label">¿De qué cuenta sale? <span class="text-rose-500">*</span></label>
                                    <select name="account_id" class="bmos-input" required>
                                        @foreach ($accounts as $a)
                                            <option value="{{ $a->id }}" @selected(old('account_id', $accounts->firstWhere('is_default', true)?->id) == $a->id)>
                                                {{ $a->name }} ({{ $a->type->label() }})
                                            </option>
                                        @endforeach
                                    </select>
                                </div>
                                <div>
                                    <label class="bmos-field-label">Fecha de pago <span class="text-rose-500">*</span></label>
                                    <input type="date" name="paid_at" value="{{ old('paid_at', now()->toDateString()) }}"
                                           max="{{ now()->toDateString() }}" class="bmos-input" required>
                                </div>
                                <div>
                                    <label class="bmos-field-label">Proveedor (opcional)</label>
                                    <select name="supplier_id" class="bmos-input">
                                        <option value="">— Ninguno —</option>
                                        @foreach ($suppliers as $s)
                                            <option value="{{ $s->id }}" @selected(old('supplier_id') == $s->id)>{{ $s->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div>
                                    <label class="bmos-field-label">O a quién se le pagó</label>
                                    <input type="text" name="supplier_name" value="{{ old('supplier_name') }}" class="bmos-input"
                                           placeholder="Edenorte, el mensajero...">
                                </div>
                                <div class="sm:col-span-2">
                                    <label class="bmos-field-label">Referencia (opcional)</label>
                                    <input type="text" name="reference" value="{{ old('reference') }}" class="bmos-input"
                                           placeholder="Nº de cheque, transferencia o factura">
                                </div>
                                <div class="sm:col-span-2">
                                    <label class="bmos-field-label">Notas</label>
                                    <textarea name="notes" rows="2" class="bmos-input">{{ old('notes') }}</textarea>
                                </div>
                            </div>

                            @if ($sesionAbierta)
                                <p class="mt-3 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800">
                                    Con la caja abierta, si eliges una cuenta de <b>efectivo</b> el gasto se
                                    descuenta también del arqueo del turno.
                                </p>
                            @endif
                        </x-panel.create-modal>
                    @endcan
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="bmos-table">
                    <thead>
                        <tr>
                            <th>Código</th><th>Fecha</th><th>Descripción</th><th>Concepto</th>
                            <th>A quién</th><th>Cuenta</th><th class="text-right">Monto</th>
                            @can('finance.manage')<th class="text-right">Anular</th>@endcan
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($expenses as $gasto)
                            <tr>
                                <td class="font-mono text-xs text-slate-500">{{ $gasto->code }}</td>
                                <td class="text-xs text-slate-500">{{ $gasto->paid_at?->format('d/m/Y') }}</td>
                                <td class="font-medium text-slate-800">
                                    {{ $gasto->description }}
                                    @if ($gasto->reference)
                                        <span class="block text-xs text-slate-400">Ref. {{ $gasto->reference }}</span>
                                    @endif
                                </td>
                                <td><span class="bmos-badge badge-gray">{{ $gasto->category?->name ?? '—' }}</span></td>
                                <td class="text-sm text-slate-600">{{ $gasto->aQuien() }}</td>
                                <td class="text-sm text-slate-600">{{ $gasto->account?->name ?? '—' }}</td>
                                <td class="text-right font-semibold text-rose-600">−{{ number_format((float) $gasto->amount, 2) }}</td>
                                @can('finance.manage')
                                    <td>
                                        <div class="flex items-center justify-end">
                                            <x-panel.confirm-action
                                                :action="route('panel.expenses.destroy', $gasto)"
                                                title="¿Anular el gasto {{ $gasto->code }}?"
                                                message="Se devuelven {{ money($gasto->amount) }} al saldo de «{{ $gasto->account?->name }}»."
                                                :note="$gasto->cashMovement()->exists()
                                                    ? 'Salió del cajón, así que también vuelve al arqueo del turno. Si ese turno ya se cerró, no se podrá anular.'
                                                    : 'El gasto deja de contar en los informes por concepto.'"
                                                tooltip="Anular"
                                                confirm="Anular el gasto"
                                                class="rounded-lg p-1.5 text-slate-500 hover:bg-rose-50 hover:text-rose-600">
                                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" style="width:1.15rem;height:1.15rem"><path stroke-linecap="round" stroke-linejoin="round" d="M18.364 18.364A9 9 0 0 0 5.636 5.636m12.728 12.728A9 9 0 0 1 5.636 5.636m12.728 12.728L5.636 5.636"/></svg>
                                            </x-panel.confirm-action>
                                        </div>
                                    </td>
                                @endcan
                            </tr>
                        @empty
                            <tr><td colspan="8" class="bmos-empty">
                                @if ($hayFiltro)
                                    Ningún gasto coincide con los filtros.
                                    <a href="{{ route('panel.expenses') }}" class="text-indigo-600 hover:underline">Quitarlos</a>
                                @else
                                    Sin gastos entre el {{ $desde->format('d/m/Y') }} y el {{ $hasta->format('d/m/Y') }}.
                                    Anota el primero con «Nuevo gasto».
                                @endif
                            </td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($expenses->hasPages())
                <div class="border-t border-slate-100 p-4">{{ $expenses->links() }}</div>
            @endif
        </div>

        {{-- Conceptos: se administran aquí abajo y no en otra pantalla, porque solo se tocan cuando
             falta uno al anotar un gasto. --}}
        @can('finance.manage')
            <div class="mt-6 bmos-card overflow-hidden" x-data="{ abierto: false }">
                <button type="button" @click="abierto = !abierto"
                        class="flex w-full items-center justify-between p-4 text-left hover:bg-slate-50">
                    <span class="font-semibold text-slate-800">Conceptos de gasto</span>
                    <span class="text-xs text-slate-400" x-text="abierto ? 'Ocultar' : `Administrar (${{{ $categories->count() }}})`"></span>
                </button>

                <div x-show="abierto" x-cloak class="border-t border-slate-100 p-4">
                    <form method="POST" action="{{ route('panel.expense-categories.store') }}" class="mb-4 flex flex-wrap gap-2">
                        @csrf
                        <input type="text" name="name" class="bmos-input max-w-xs" placeholder="Nombre del concepto nuevo" required>
                        <button type="submit" class="bmos-btn bmos-btn-primary">Añadir</button>
                    </form>

                    <div class="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-3">
                        @foreach ($categories as $c)
                            <form method="POST" action="{{ route('panel.expense-categories.update', $c) }}"
                                  class="flex items-center gap-2 rounded-lg border border-slate-200 p-2">
                                @csrf @method('PUT')
                                <input type="text" name="name" value="{{ $c->name }}" class="bmos-input flex-1 text-sm">
                                <label class="flex shrink-0 items-center gap-1 text-xs text-slate-500" title="Desmarcar lo retira de los formularios sin tocar los gastos que ya lo usan">
                                    <input type="checkbox" name="is_active" value="1" @checked($c->is_active) class="rounded border-slate-300 text-indigo-600">
                                    Activo
                                </label>
                                <button type="submit" class="bmos-btn bmos-btn-ghost text-xs">Guardar</button>
                            </form>
                        @endforeach
                    </div>
                </div>
            </div>
        @endcan
    </div>
</x-layouts.admin>
