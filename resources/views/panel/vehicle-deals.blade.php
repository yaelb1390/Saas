<x-layouts.admin title="Ventas y apartados" heading="Ventas y apartados" subheading="Quién se llevó qué unidad, en cuánto y qué falta por cobrar">
    <div>
        @if ($faltaMigrar)
            <div class="bmos-card bmos-card-pad text-center">
                <p class="text-lg font-semibold text-slate-700">Falta preparar la base de datos</p>
                <p class="mt-1 text-sm text-slate-500">
                    El módulo de vehículos está instalado, pero sus tablas todavía no.
                    Avisa a quien administre el sistema para que aplique las migraciones pendientes.
                </p>
            </div>
        @else
            <div class="bmos-card">
                <div class="flex flex-wrap items-center gap-3 border-b border-slate-100 p-4">
                    <form method="GET" class="flex flex-wrap items-center gap-3">
                        {{-- El ancho, en el contenedor: `.bmos-input` impone `width: 100%` y le gana
                             a las utilidades de Tailwind. --}}
                        <div class="w-full sm:w-64">
                            <input type="search" name="q" value="{{ request('q') }}"
                                   placeholder="Buscar por código o cliente…" class="bmos-input">
                        </div>

                        <div class="w-full sm:w-40">
                            <select name="estado" class="bmos-input" onchange="this.form.submit()">
                                <option value="">Todos</option>
                                @foreach ($estados as $estado)
                                    <option value="{{ $estado->value }}" @selected(request('estado') === $estado->value)>
                                        {{ $estado->label() }}
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        <button type="submit" class="bmos-btn bmos-btn-ghost">Buscar</button>
                    </form>

                    @can('vehicle_deals.manage')
                        <div class="ms-auto">
                            <x-panel.create-modal title="Nuevo trato" label="Nuevo trato" form="trato"
                                                  width="max-w-2xl" action="{{ route('panel.vehicle-deals.store') }}">
                                <div x-data="{ financiado: '{{ old('financing', 'none') }}' === 'installments' }">
                                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                        <div class="sm:col-span-2">
                                            <label class="bmos-field-label">Vehículo <span class="text-rose-500">&nbsp;*</span></label>
                                            <select name="vehicle_id" required class="bmos-input">
                                                <option value="">Elige la unidad…</option>
                                                @foreach ($disponibles as $v)
                                                    <option value="{{ $v->id }}" @selected(old('vehicle_id') == $v->id)>
                                                        {{ $v->code }} — {{ $v->make }} {{ $v->model }} {{ $v->year }} ({{ money($v->asking_price) }})
                                                    </option>
                                                @endforeach
                                            </select>
                                            @if ($disponibles->isEmpty())
                                                {{-- Se dice por qué la lista está vacía. Un desplegable sin
                                                     opciones y sin explicación parece un fallo del sistema. --}}
                                                <p class="mt-1 text-xs text-amber-600">
                                                    No hay unidades disponibles: todas están apartadas o vendidas.
                                                </p>
                                            @endif
                                        </div>

                                        <div class="sm:col-span-2">
                                            <label class="bmos-field-label">Cliente <span class="text-rose-500">&nbsp;*</span></label>
                                            <input type="number" name="customer_id" required class="bmos-input"
                                                   value="{{ old('customer_id') }}" placeholder="Código del cliente en el CRM">
                                        </div>

                                        <x-panel.field name="agreed_price" label="Precio pactado" type="number" step="0.01" required />
                                        <x-panel.field name="down_payment" label="Inicial" type="number" step="0.01" />

                                        <div>
                                            <label class="bmos-field-label">Recibe en parte de pago</label>
                                            <select name="trade_in_vehicle_id" class="bmos-input">
                                                <option value="">Ninguno</option>
                                                @foreach ($disponibles as $v)
                                                    <option value="{{ $v->id }}">{{ $v->code }} — {{ $v->make }} {{ $v->model }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                        <x-panel.field name="trade_in_value" label="Valor del recibido" type="number" step="0.01" />

                                        <div class="sm:col-span-2">
                                            <label class="bmos-field-label">Forma de pago</label>
                                            <select name="financing" class="bmos-input"
                                                    x-on:change="financiado = $event.target.value === 'installments'">
                                                <option value="none">De contado</option>
                                                <option value="installments" @selected(old('financing') === 'installments')>
                                                    Financiado por nosotros
                                                </option>
                                            </select>
                                        </div>

                                        {{-- Las cuotas solo se piden si se financia: exigirlas siempre
                                             obligaría a rellenarlas en la venta de contado, que es la
                                             mayoría. --}}
                                        <template x-if="financiado">
                                            <div class="grid grid-cols-1 gap-4 sm:col-span-2 sm:grid-cols-2">
                                                <x-panel.field name="installments_count" label="Número de cuotas" type="number" />
                                                <div>
                                                    <label class="bmos-field-label">Cada cuánto</label>
                                                    <select name="frequency" class="bmos-input">
                                                        <option value="monthly">Mensual</option>
                                                        <option value="biweekly">Quincenal</option>
                                                        <option value="weekly">Semanal</option>
                                                    </select>
                                                </div>
                                                <x-panel.field name="interest_rate" label="Interés (%)" type="number" step="0.01" />
                                                <x-panel.field name="interest_amount" label="…o interés en pesos" type="number" step="0.01" />
                                                <x-panel.field name="start_date" label="Primera cuota" type="date" />
                                            </div>
                                        </template>
                                    </div>

                                    <div class="mt-4">
                                        <label class="bmos-field-label">Notas</label>
                                        <textarea name="notes" rows="2" class="bmos-input">{{ old('notes') }}</textarea>
                                    </div>

                                    <label class="mt-3 flex items-center gap-2 text-sm text-slate-600">
                                        <input type="checkbox" name="close" value="1" class="rounded border-slate-300">
                                        Se lo lleva hoy (cerrar la venta ahora)
                                    </label>
                                </div>
                            </x-panel.create-modal>
                        </div>
                    @endcan
                </div>

                @if ($tratos->isEmpty())
                    <div class="p-10 text-center text-sm text-slate-400">Todavía no hay tratos registrados.</div>
                @else
                    <div class="bmos-tabla-envoltura">
                        <table class="bmos-table">
                            <thead>
                                <tr>
                                    <th>Trato</th>
                                    <th>Vehículo</th>
                                    <th>Cliente</th>
                                    <th>Precio</th>
                                    <th>Falta por cobrar</th>
                                    <th>Estado</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($tratos as $trato)
                                    <tr>
                                        <td>
                                            <span class="font-medium text-slate-700">{{ $trato->code }}</span>
                                            <span class="block text-xs text-slate-400">{{ $trato->created_at?->format('d/m/Y') }}</span>
                                        </td>
                                        <td class="text-sm text-slate-600">
                                            {{ $trato->vehicle?->code }}
                                            <span class="block text-xs text-slate-400">
                                                {{ $trato->vehicle?->make }} {{ $trato->vehicle?->model }} {{ $trato->vehicle?->year }}
                                            </span>
                                        </td>
                                        <td class="text-sm text-slate-600">{{ $trato->customer_name ?? $trato->customer?->name }}</td>
                                        <td class="text-sm text-slate-600">{{ money($trato->agreed_price) }}</td>
                                        <td class="text-sm">
                                            {{ money($trato->balance) }}
                                            @if ($trato->cuotas_vencidas > 0)
                                                {{-- Lo que hay que perseguir. Sin esto habría que abrir
                                                     trato por trato para saber quién está atrasado. --}}
                                                <span class="bmos-badge badge-rose ml-1">
                                                    {{ $trato->cuotas_vencidas }}
                                                    {{ $trato->cuotas_vencidas === 1 ? 'cuota vencida' : 'cuotas vencidas' }}
                                                </span>
                                            @endif
                                        </td>
                                        <td>
                                            <span class="bmos-badge {{ $trato->status->badgeClass() }}">{{ $trato->status->label() }}</span>
                                        </td>
                                        <td class="text-right">
                                            @can('vehicle_deals.manage')
                                                @if ($trato->status->value === 'reserved')
                                                    <form method="POST" action="{{ route('panel.vehicle-deals.close', $trato) }}" class="inline">
                                                        @csrf
                                                        <button class="bmos-btn bmos-btn-ghost">Cerrar</button>
                                                    </form>
                                                    {{-- «neutral» y no «danger»: no se destruye nada.
                                                         El trato queda marcado como caído y la unidad
                                                         vuelve al patio. --}}
                                                    <x-panel.confirm-action
                                                        :action="route('panel.vehicle-deals.cancel', $trato)"
                                                        method="POST"
                                                        tone="neutral"
                                                        confirm="Dar de baja"
                                                        title="¿Dar de baja el trato {{ $trato->code }}?"
                                                        message="La unidad vuelve al patio y queda disponible."
                                                        class="bmos-btn bmos-btn-ghost">
                                                        Dar de baja
                                                    </x-panel.confirm-action>
                                                @endif
                                            @endcan
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div class="border-t border-slate-100 p-4">{{ $tratos->links() }}</div>
                @endif
            </div>
        @endif
    </div>
</x-layouts.admin>
