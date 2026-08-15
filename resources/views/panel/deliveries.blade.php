@use('App\Modules\Delivery\Enums\DeliveryStatus')

@php
    $filtro = $estadoActivo ? DeliveryStatus::tryFrom((string) $estadoActivo) : null;
@endphp

<x-layouts.admin title="Entregas" heading="Entregas"
                 subheading="Quién lleva cada pedido y cuánto dinero trae de vuelta">
    <div>
        {{-- Cuadre por repartidor.

             Va ARRIBA porque es la pregunta del cierre del día y hasta ahora no tenía respuesta en
             ninguna pantalla: el motorista salía con mercancía, volvía con efectivo y ese dinero no
             aparecía en ningún sitio. El arqueo lo cantaba como faltante sin que nadie supiera por
             qué. --}}
        @if ($porLiquidar->isNotEmpty())
            <div class="mb-5 bmos-card bmos-card-pad">
                <p class="font-semibold text-slate-800">Dinero en la calle</p>
                <p class="mt-1 text-xs text-slate-500">
                    Cobrado por los repartidores y todavía sin entregar en caja.
                </p>

                <div class="mt-3 grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($porLiquidar as $fila)
                        <div class="flex items-center justify-between gap-3 rounded-xl border border-amber-200 bg-amber-50 p-3">
                            <div class="min-w-0">
                                <p class="truncate font-medium text-slate-800">{{ $fila->employee?->name ?? 'Sin repartidor' }}</p>
                                <p class="text-xs text-amber-800">
                                    {{ $fila->entregas }} {{ $fila->entregas === 1 ? 'entrega' : 'entregas' }} ·
                                    <b>{{ money($fila->total) }}</b>
                                </p>
                            </div>
                            @can('delivery.manage')
                                @if ($fila->employee)
                                    <x-panel.confirm-action
                                        :action="route('panel.deliveries.settle', $fila->employee)"
                                        method="POST"
                                        tone="neutral"
                                        title="¿{{ $fila->employee->name }} entregó el dinero?"
                                        message="Son {{ money($fila->total) }} de {{ $fila->entregas }} {{ $fila->entregas === 1 ? 'entrega' : 'entregas' }}. Cuéntalo antes de confirmar."
                                        note="No se registra ningún ingreso nuevo: la venta ya lo anotó al cobrarse. Esto solo dice que el efectivo ya está en caja y no en la mochila."
                                        confirm="Sí, lo entregó"
                                        class="bmos-btn bmos-btn-ghost shrink-0 text-xs">
                                        Liquidar
                                    </x-panel.confirm-action>
                                @endif
                            @endcan
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- Filtro por estado --}}
        <div class="mb-4 flex flex-wrap items-center gap-2">
            <a href="{{ route('panel.deliveries', ['q' => request('q')]) }}"
               class="bmos-btn {{ $filtro ? 'bmos-btn-ghost' : 'bmos-btn-primary' }} text-xs">
                Todas
                @if ($deliveries->total() > 0)<span class="ml-1.5 opacity-60">{{ $deliveries->total() }}</span>@endif
            </a>
            @foreach ($statuses as $s)
                <a href="{{ route('panel.deliveries', ['estado' => $s->value, 'q' => request('q')]) }}"
                   class="bmos-btn text-xs {{ $filtro === $s ? 'bmos-btn-primary' : 'bmos-btn-ghost' }}">
                    {{ $s->label() }}
                </a>
            @endforeach
        </div>

        <div class="bmos-card overflow-hidden">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 p-4">
                <p class="font-semibold text-slate-800">
                    Entregas
                    @if ($abiertas > 0)
                        <span class="ml-1 rounded-full bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-700">
                            {{ $abiertas }} sin cerrar
                        </span>
                    @endif
                </p>
                <div class="flex flex-wrap items-center gap-3">
                    <x-panel.search-bar placeholder="Buscar por código, cliente, dirección o repartidor..." />
                    @can('delivery.manage')
                        <x-panel.create-modal title="Nueva entrega" label="Nueva entrega" form="delivery_create"
                                              :action="route('panel.deliveries.store')" width="max-w-lg">
                            <div class="space-y-3">
                                <div>
                                    <label class="bmos-field-label">Dirección <span class="text-rose-500">*</span></label>
                                    <input type="text" name="address" value="{{ old('address') }}" class="bmos-input"
                                           placeholder="Calle Duarte 45, casa amarilla" required>
                                    <p class="mt-1 text-xs text-slate-400">Lo único imprescindible: sin dirección no hay reparto.</p>
                                </div>
                                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                    <div>
                                        <label class="bmos-field-label">Cliente</label>
                                        <input type="text" name="customer_name" value="{{ old('customer_name') }}" class="bmos-input">
                                    </div>
                                    <div>
                                        <label class="bmos-field-label">Teléfono</label>
                                        <input type="text" name="phone" value="{{ old('phone') }}" class="bmos-input">
                                    </div>
                                    <div>
                                        <label class="bmos-field-label">Repartidor</label>
                                        <select name="employee_id" class="bmos-input">
                                            <option value="">— Asignar después —</option>
                                            @foreach ($drivers as $d)
                                                <option value="{{ $d->id }}">{{ $d->name }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div>
                                        <label class="bmos-field-label">A cobrar (RD$)</label>
                                        <input type="number" step="0.01" min="0" name="amount_to_collect"
                                               value="{{ old('amount_to_collect') }}" class="bmos-input" placeholder="0.00">
                                        <p class="mt-1 text-xs text-slate-400">Déjalo en blanco si ya está pagada.</p>
                                    </div>
                                </div>
                                <div>
                                    <label class="bmos-field-label">Notas</label>
                                    <input type="text" name="notes" value="{{ old('notes') }}" class="bmos-input"
                                           placeholder="Referencia, timbre, portón azul...">
                                </div>
                            </div>
                        </x-panel.create-modal>
                    @endcan
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="bmos-table">
                    <thead>
                        <tr>
                            <th>Código</th><th>Cliente</th><th>Dirección</th><th>Repartidor</th>
                            <th>Estado</th><th class="text-right">A cobrar</th>
                            @can('delivery.manage')<th class="text-right">Qué hacer</th>@endcan
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($deliveries as $d)
                            <tr>
                                <td class="font-mono text-xs text-slate-500">
                                    {{ $d->code }}
                                    @if ($d->sale)
                                        <span class="block text-[11px] text-indigo-500">{{ $d->sale->code }}</span>
                                    @endif
                                </td>
                                <td class="font-medium text-slate-800">
                                    {{ $d->paraQuien() }}
                                    @if ($d->phone)<span class="block text-xs text-slate-400">{{ $d->phone }}</span>@endif
                                </td>
                                <td class="max-w-xs text-sm text-slate-600">
                                    {{ $d->address }}
                                    @if ($d->notes)<span class="block text-xs text-slate-400">{{ $d->notes }}</span>@endif
                                </td>
                                <td class="text-sm text-slate-600">
                                    @can('delivery.manage')
                                        @unless ($d->status->isFinal())
                                            <form method="POST" action="{{ route('panel.deliveries.assign', $d) }}" class="flex items-center gap-1">
                                                @csrf
                                                <select name="employee_id" class="bmos-input py-1 text-xs" onchange="this.form.submit()">
                                                    <option value="">— Sin asignar —</option>
                                                    @foreach ($drivers as $dr)
                                                        <option value="{{ $dr->id }}" @selected($d->employee_id === $dr->id)>{{ $dr->name }}</option>
                                                    @endforeach
                                                </select>
                                            </form>
                                            {{-- Entregas de antes de que el repartidor fuera un empleado de la ficha:
                                                 llevan el nombre escrito y nada más. Sin esta línea el desplegable las
                                                 enseñaría como «sin asignar» y se perdería quién la llevó. --}}
                                            @if ($d->employee_id === null && $d->driver_name)
                                                <span class="mt-1 block text-xs text-slate-400">Iba con {{ $d->driver_name }}</span>
                                            @endif
                                        @else
                                            {{ $d->driver_name ?? '—' }}
                                        @endunless
                                    @else
                                        {{ $d->driver_name ?? '—' }}
                                    @endcan
                                </td>
                                <td><span class="bmos-badge {{ $d->status->badge() }}">{{ $d->status->label() }}</span></td>
                                <td class="text-right">
                                    @if ($d->cobraEnLaPuerta())
                                        <span class="font-semibold text-slate-700">{{ money($d->amount_to_collect) }}</span>
                                        @if ($d->settled_at)
                                            <span class="block text-[11px] text-emerald-600">liquidado</span>
                                        @elseif ($d->collected_at)
                                            <span class="block text-[11px] text-amber-600">cobrado, sin liquidar</span>
                                        @endif
                                    @else
                                        <span class="text-xs text-slate-400">pagada</span>
                                    @endif
                                </td>
                                @can('delivery.manage')
                                    <td>
                                        <div class="flex flex-wrap items-center justify-end gap-1">
                                            {{-- Solo los pasos que caben desde donde está. Enseñar la lista
                                                 entera dejaría volver atrás una entrega ya cerrada, y el
                                                 reparto del día dejaría de cuadrar sin que nadie hubiera
                                                 hecho nada raro. --}}
                                            @foreach ($statuses as $s)
                                                @continue($s === $d->status || ! $d->status->admiteIr($s))
                                                <form method="POST" action="{{ route('panel.deliveries.transition', $d) }}" class="inline">
                                                    @csrf
                                                    <input type="hidden" name="status" value="{{ $s->value }}">
                                                    <button type="submit" class="bmos-btn bmos-btn-ghost text-xs">{{ $s->label() }}</button>
                                                </form>
                                            @endforeach

                                            @if ($d->cobraEnLaPuerta() && $d->collected_at === null)
                                                <x-panel.confirm-action
                                                    :action="route('panel.deliveries.collect', $d)"
                                                    method="POST"
                                                    tone="neutral"
                                                    title="¿Cobró {{ money($d->amount_to_collect) }}?"
                                                    message="La entrega queda como entregada y ese dinero pasa a estar en manos de {{ $d->driver_name ?? 'el repartidor' }}."
                                                    note="Quedará pendiente hasta que lo entregue en caja."
                                                    confirm="Sí, cobró"
                                                    class="bmos-btn bmos-btn-ghost text-xs text-emerald-700">
                                                    Cobrada
                                                </x-panel.confirm-action>
                                            @endif
                                        </div>
                                    </td>
                                @endcan
                            </tr>
                        @empty
                            <tr><td colspan="7" class="bmos-empty">
                                @if (request('q') || $filtro)
                                    Ninguna entrega coincide.
                                    <a href="{{ route('panel.deliveries') }}" class="text-indigo-600 hover:underline">Ver todas</a>
                                @else
                                    Sin entregas todavía. Registra la primera con «Nueva entrega», o créala
                                    desde una venta para que lleve su monto a cobrar.
                                @endif
                            </td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($deliveries->hasPages())
                <div class="border-t border-slate-100 p-4">{{ $deliveries->links() }}</div>
            @endif
        </div>
    </div>
</x-layouts.admin>
