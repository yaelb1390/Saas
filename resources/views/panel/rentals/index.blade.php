<x-layouts.admin title="Alquiler" heading="Alquiler de vehículos" subheading="Reservas, entregas, devoluciones y cobros">
    <div>
        <div class="mb-5 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <div class="bmos-stat">
                <div class="bmos-stat-icon tone-violet"><x-icono name="truck" /></div>
                <p class="bmos-stat-label">Alquilados ahora</p>
                <p class="bmos-stat-value">{{ number_format($resumen['alquilados_ahora']) }}</p>
            </div>
            <div class="bmos-stat">
                <div class="bmos-stat-icon tone-amber"><x-icono name="reloj" /></div>
                <p class="bmos-stat-label">Reservas próximas</p>
                <p class="bmos-stat-value">{{ number_format($resumen['reservas_proximas']) }}</p>
            </div>
            <div class="bmos-stat">
                <div class="bmos-stat-icon tone-emerald"><x-icono name="cash" /></div>
                <p class="bmos-stat-label">Ingresos del mes</p>
                <p class="bmos-stat-value">{{ money((float) $resumen['ingresos_mes']) }}</p>
            </div>
            <div class="bmos-stat">
                <div class="bmos-stat-icon tone-sky"><x-icono name="chart" /></div>
                <p class="bmos-stat-label">Utilidad del mes</p>
                <p class="bmos-stat-value {{ $resumen['utilidad_mes'] < 0 ? 'text-rose-600' : '' }}">{{ money((float) $resumen['utilidad_mes']) }}</p>
            </div>
        </div>

        <div class="mb-5 grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div class="bmos-stat">
                <div class="bmos-stat-icon tone-indigo"><x-icono name="cube" /></div>
                <p class="bmos-stat-label">Valor de la flota en alquiler</p>
                <p class="bmos-stat-value">{{ money((float) $resumen['valor_flota']) }}</p>
            </div>
            <div class="bmos-stat">
                <div class="bmos-stat-icon tone-violet"><x-icono name="pulse" /></div>
                <p class="bmos-stat-label">Tasa de utilización (este mes)</p>
                <p class="bmos-stat-value">{{ number_format((float) $resumen['tasa_utilizacion'], 1) }}%</p>
            </div>
        </div>

        <div class="bmos-card">
            <div class="flex flex-wrap items-center gap-3 border-b border-slate-100 p-4">
                <form method="GET" class="flex flex-wrap items-center gap-3">
                    <div class="w-full sm:w-64">
                        <input type="search" name="q" value="{{ request('q') }}"
                               placeholder="Buscar por código…" class="bmos-input">
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

                <a href="{{ route('panel.rentals.calendar') }}" class="bmos-btn bmos-btn-suave">
                    <x-icono name="reloj" class="h-4 w-4" />
                    Calendario
                </a>

                <a href="{{ route('panel.rentals.reports') }}" class="bmos-btn bmos-btn-suave">
                    <x-icono name="chart" class="h-4 w-4" />
                    Reportes
                </a>

                <div class="ms-auto">
                    @include('panel.rentals.partials.new-rental-modal')
                </div>
            </div>

            @if ($rentals->isEmpty())
                <div class="p-10 text-center text-sm text-slate-400">Todavía no hay alquileres registrados.</div>
            @else
                <div class="bmos-tabla-envoltura">
                    <table class="bmos-table bmos-tabla-tarjetas">
                        <thead>
                            <tr>
                                <th>Alquiler</th>
                                <th>Vehículo</th>
                                <th>Cliente</th>
                                <th>Fechas</th>
                                <th>Total</th>
                                <th>Saldo</th>
                                <th>Estado</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($rentals as $rental)
                                <tr>
                                    <td data-rotulo="Alquiler">
                                        <span class="font-medium text-slate-700">{{ $rental->code }}</span>
                                    </td>
                                    <td data-rotulo="Vehículo" class="text-sm text-slate-600">
                                        {{ $rental->vehicle?->code }}
                                        <span class="block text-xs text-slate-400">
                                            {{ $rental->vehicle?->make }} {{ $rental->vehicle?->model }} {{ $rental->vehicle?->year }}
                                        </span>
                                    </td>
                                    <td data-rotulo="Cliente" class="text-sm text-slate-600">{{ $rental->customer?->name }}</td>
                                    <td data-rotulo="Fechas" class="text-sm text-slate-600">
                                        {{ $rental->start_at?->format('d/m/Y') }} — {{ $rental->end_at?->format('d/m/Y') }}
                                        <span class="block text-xs text-slate-400">{{ $rental->days }} {{ $rental->days === 1 ? 'día' : 'días' }}</span>
                                    </td>
                                    <td data-rotulo="Total" class="text-sm text-slate-600">{{ money($rental->total) }}</td>
                                    <td data-rotulo="Saldo" class="text-sm">{{ money($rental->balance) }}</td>
                                    <td>
                                        <span class="bmos-badge {{ $rental->status->badgeClass() }}">{{ $rental->status->label() }}</span>
                                    </td>
                                    <td class="text-right">
                                        <a href="{{ route('panel.rentals.show', $rental) }}" class="bmos-btn bmos-btn-ghost">Ver</a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="border-t border-slate-100 p-4">{{ $rentals->links() }}</div>
            @endif
        </div>
    </div>
</x-layouts.admin>
