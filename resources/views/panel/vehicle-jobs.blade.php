<x-layouts.admin title="Taller" heading="Taller y preparación" subheading="Lo que se le hace a cada unidad antes de venderla, y lo que cuesta">
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
            <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div class="bmos-stat">
                    <p class="bmos-stat-label">Gastado en preparación</p>
                    <p class="bmos-stat-value">RD$ {{ $gastadoEnTotal }}</p>
                    <p class="mt-0.5 text-xs text-slate-400">ya contado en el costo de cada unidad</p>
                </div>
                <div class="bmos-stat">
                    <p class="bmos-stat-label">Unidades en el patio</p>
                    <p class="bmos-stat-value">{{ $vehiculos->count() }}</p>
                    <p class="mt-0.5 text-xs text-slate-400">disponibles o apartadas</p>
                </div>
            </div>

            <div class="bmos-card">
                <div class="flex flex-wrap items-center gap-3 border-b border-slate-100 p-4">
                    <form method="GET" class="flex flex-wrap items-center gap-3">
                        {{-- El ancho, en el contenedor: `.bmos-input` impone `width: 100%` y le gana
                             a las utilidades de Tailwind. --}}
                        <div class="w-full sm:w-64">
                            <select name="vehiculo" class="bmos-input" onchange="this.form.submit()">
                                <option value="">Todas las unidades</option>
                                @foreach ($vehiculos as $v)
                                    <option value="{{ $v->id }}" @selected(request('vehiculo') == $v->id)>
                                        {{ $v->code }} — {{ $v->make }} {{ $v->model }} {{ $v->year }}
                                    </option>
                                @endforeach
                            </select>
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
                    </form>

                    @can('vehicle_jobs.manage')
                        <div class="ms-auto">
                            <x-panel.create-modal title="Anotar trabajo" label="Anotar trabajo" form="trabajo"
                                                  action="{{ route('panel.vehicle-jobs.store') }}">
                                <div class="space-y-4">
                                    <div>
                                        <label class="bmos-field-label">Vehículo <span class="text-rose-500">&nbsp;*</span></label>
                                        <select name="vehicle_id" required class="bmos-input">
                                            <option value="">Elige la unidad…</option>
                                            @foreach ($vehiculos as $v)
                                                <option value="{{ $v->id }}" @selected(old('vehicle_id') == $v->id)>
                                                    {{ $v->code }} — {{ $v->make }} {{ $v->model }} {{ $v->year }}
                                                </option>
                                            @endforeach
                                        </select>
                                    </div>

                                    <x-panel.field name="description" label="Qué se le hizo" required
                                                   placeholder="Cambio de gomas, pintura del bumper…" />

                                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                        <x-panel.field name="cost" label="Costo" type="number" step="0.01" />
                                        <x-panel.field name="performed_at" label="Fecha" type="date" />
                                    </div>

                                    {{-- En texto libre: casi siempre es un taller de fuera que no es
                                         proveedor del sistema. Obligar a darlo de alta para anotar un
                                         cambio de gomas haría que nadie lo anotara. --}}
                                    <x-panel.field name="performed_by" label="Quién lo hizo" placeholder="Taller Ramírez" />

                                    <div>
                                        <label class="bmos-field-label">Notas</label>
                                        <textarea name="notes" rows="2" class="bmos-input">{{ old('notes') }}</textarea>
                                    </div>

                                    <label class="flex items-center gap-2 text-sm text-slate-600">
                                        <input type="checkbox" name="done" value="1" class="rounded border-slate-300">
                                        Ya está hecho
                                    </label>
                                </div>
                            </x-panel.create-modal>
                        </div>
                    @endcan
                </div>

                @if ($trabajos->isEmpty())
                    <div class="p-10 text-center text-sm text-slate-400">Todavía no hay trabajos anotados.</div>
                @else
                    <div class="bmos-tabla-envoltura">
                        <table class="bmos-table">
                            <thead>
                                <tr>
                                    <th>Vehículo</th>
                                    <th>Trabajo</th>
                                    <th>Quién</th>
                                    <th>Costo</th>
                                    <th>Fecha</th>
                                    <th>Estado</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($trabajos as $trabajo)
                                    <tr>
                                        <td class="text-sm text-slate-600">
                                            {{ $trabajo->vehicle?->code }}
                                            <span class="block text-xs text-slate-400">
                                                {{ $trabajo->vehicle?->make }} {{ $trabajo->vehicle?->model }} {{ $trabajo->vehicle?->year }}
                                            </span>
                                        </td>
                                        <td class="text-sm text-slate-700">{{ $trabajo->description }}</td>
                                        <td class="text-sm text-slate-500">{{ $trabajo->performed_by ?? '—' }}</td>
                                        <td class="text-sm text-slate-600">{{ money($trabajo->cost) }}</td>
                                        <td class="text-sm text-slate-500">{{ $trabajo->performed_at?->format('d/m/Y') ?? '—' }}</td>
                                        <td>
                                            <span class="bmos-badge {{ $trabajo->status->badgeClass() }}">{{ $trabajo->status->label() }}</span>
                                        </td>
                                        <td class="text-right">
                                            @can('vehicle_jobs.manage')
                                                @if ($trabajo->status->value === 'pending')
                                                    <form method="POST" action="{{ route('panel.vehicle-jobs.complete', $trabajo) }}" class="inline">
                                                        @csrf
                                                        <button class="bmos-btn bmos-btn-ghost">Marcar hecho</button>
                                                    </form>
                                                @endif
                                            @endcan
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div class="border-t border-slate-100 p-4">{{ $trabajos->links() }}</div>
                @endif
            </div>
        @endif
    </div>
</x-layouts.admin>
