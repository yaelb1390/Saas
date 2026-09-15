<x-layouts.admin title="Reportes de alquiler" heading="Reportes de alquiler" subheading="Ingresos, utilización y los vehículos que más rinden">
    <div class="space-y-5">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <a href="{{ route('panel.rentals') }}" class="text-sm text-indigo-600 hover:underline">&larr; Volver a Alquiler</a>
        </div>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <div class="bmos-stat">
                <div class="bmos-stat-icon tone-emerald"><x-icono name="cash" /></div>
                <p class="bmos-stat-label">Ingresos del mes</p>
                <p class="bmos-stat-value">{{ money((float) $resumen['ingresos_mes']) }}</p>
            </div>
            <div class="bmos-stat">
                <div class="bmos-stat-icon tone-amber"><x-icono name="bag" /></div>
                <p class="bmos-stat-label">Gastos de mantenimiento del mes</p>
                <p class="bmos-stat-value">{{ money((float) $resumen['gastos_mes']) }}</p>
            </div>
            <div class="bmos-stat">
                <div class="bmos-stat-icon tone-sky"><x-icono name="chart" /></div>
                <p class="bmos-stat-label">Utilidad del mes</p>
                <p class="bmos-stat-value {{ $resumen['utilidad_mes'] < 0 ? 'text-rose-600' : '' }}">{{ money((float) $resumen['utilidad_mes']) }}</p>
            </div>
            <div class="bmos-stat">
                <div class="bmos-stat-icon tone-violet"><x-icono name="pulse" /></div>
                <p class="bmos-stat-label">Tasa de utilización</p>
                <p class="bmos-stat-value">{{ number_format((float) $resumen['tasa_utilizacion'], 1) }}%</p>
            </div>
        </div>

        <div class="grid grid-cols-1 gap-5 lg:grid-cols-2">
            <div class="bmos-card">
                <div class="border-b border-slate-100 p-4"><p class="font-semibold text-slate-800">Ingresos por mes</p></div>
                <div class="bmos-tabla-envoltura">
                    <table class="bmos-table bmos-tabla-tarjetas">
                        <thead><tr><th>Mes</th><th class="text-right">Ingresos</th></tr></thead>
                        <tbody>
                            @forelse ($ingresosPorMes as $fila)
                                <tr>
                                    <td data-rotulo="Mes" class="capitalize">{{ $fila['mes'] }}</td>
                                    <td data-rotulo="Ingresos" class="text-right font-medium">{{ money((float) $fila['total']) }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="2" class="bmos-empty">Sin datos todavía.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="bmos-card">
                <div class="border-b border-slate-100 p-4"><p class="font-semibold text-slate-800">Vehículos más alquilados</p></div>
                <div class="bmos-tabla-envoltura">
                    <table class="bmos-table bmos-tabla-tarjetas">
                        <thead><tr><th>Vehículo</th><th>Alquileres</th><th>Días</th><th class="text-right">Ingresos</th></tr></thead>
                        <tbody>
                            @forelse ($masAlquilados as $fila)
                                <tr>
                                    <td data-rotulo="Vehículo">{{ $fila['vehiculo'] }}</td>
                                    <td data-rotulo="Alquileres">{{ $fila['alquileres'] }}</td>
                                    <td data-rotulo="Días">{{ $fila['dias'] }}</td>
                                    <td data-rotulo="Ingresos" class="text-right font-medium">{{ money((float) $fila['ingresos']) }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="bmos-empty">Todavía no hay alquileres entregados.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</x-layouts.admin>
