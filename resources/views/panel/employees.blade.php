<x-layouts.admin title="RRHH" heading="Recursos Humanos" subheading="Empleados y control de asistencia">
    <div x-data="employeesCrud()">
    <div class="bmos-card overflow-hidden">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 p-4">
            <p class="font-semibold text-slate-800">Empleados</p>
            <div class="flex flex-wrap items-center gap-3">
                <x-panel.search-bar placeholder="Buscar empleado..." />
            @can('hr.manage')
            <x-panel.create-modal title="Contratar empleado" label="Contratar" form="employee_create" :action="route('panel.employees.store')">
                <x-panel.field name="name" label="Nombre" required placeholder="Nombre del empleado" />
                <x-panel.field name="position" label="Cargo" placeholder="Cajero, Gerente..." />
                <div class="grid grid-cols-2 gap-3">
                    <x-panel.field name="email" label="Correo" type="email" />
                    <x-panel.field name="salary" label="Salario" type="number" step="0.01" />
                </div>
            </x-panel.create-modal>
            @endcan
            </div>
        </div>
        <div class="overflow-x-auto">
            <table class="bmos-table">
                <thead><tr><th>Empleado</th><th>Cargo</th><th>Correo</th><th>Ingreso</th><th>Asistencias</th><th>Estado</th><th class="text-right">Acciones</th></tr></thead>
                <tbody>
                    @forelse ($employees as $employee)
                        <tr>
                            <td class="flex items-center gap-2 font-medium text-slate-800">
                                <span class="flex h-8 w-8 items-center justify-center rounded-full bg-indigo-100 text-xs font-bold text-indigo-600">
                                    {{ strtoupper(mb_substr($employee->name, 0, 1)) }}
                                </span>
                                {{ $employee->name }}
                            </td>
                            <td>{{ $employee->position ?? '—' }}</td>
                            <td class="text-slate-500">{{ $employee->email ?? '—' }}</td>
                            <td class="text-slate-400">{{ $employee->hired_at?->format('d/m/Y') ?? '—' }}</td>
                            <td><span class="bmos-badge badge-blue">{{ $employee->attendances_count }}</span></td>
                            <td><span class="bmos-badge {{ $employee->is_active ? 'badge-green' : 'badge-gray' }}">{{ $employee->is_active ? 'Activo' : 'Inactivo' }}</span></td>
                            <td>
                                <div class="flex items-center justify-end gap-1">
                                    @can('hr.manage')
                                    <button type="button" class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-indigo-600" title="Editar"
                                            @click="edit({ id: {{ $employee->id }}, name: @js($employee->name), position: @js($employee->position), email: @js($employee->email), salary: '{{ $employee->salary }}' })">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" style="width:1.1rem;height:1.1rem"><path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Z"/></svg>
                                    </button>
                                    <form method="POST" action="{{ route('panel.employees.destroy', $employee) }}" onsubmit="return confirm('¿Eliminar a «{{ $employee->name }}»?')">
                                        @csrf @method('DELETE')
                                        <button type="submit" class="rounded-lg p-1.5 text-slate-400 hover:bg-rose-50 hover:text-rose-600" title="Eliminar">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" style="width:1.1rem;height:1.1rem"><path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166M18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916"/></svg>
                                        </button>
                                    </form>
                                    @endcan
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="bmos-empty">Sin empleados.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    <div class="mt-4">{{ $employees->links() }}</div>

        {{-- Modal de edición de empleado --}}
        <div x-show="open" x-cloak class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-900/50 p-4 py-10" @keydown.escape.window="open=false">
            <div @click.outside="open=false" x-transition class="w-full max-w-lg rounded-2xl bg-white p-6 shadow-2xl">
                <div class="mb-4 flex items-center justify-between">
                    <h3 class="text-lg font-semibold text-slate-800">Editar empleado</h3>
                    <button type="button" @click="open=false" class="text-slate-400 hover:text-slate-600">✕</button>
                </div>
                @if (old('_form') === 'employee_edit' && $errors->any())
                    <div class="mb-4 rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700">
                        <ul class="list-disc pl-4">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
                    </div>
                @endif
                <form method="POST" :action="editUrl" class="space-y-3">
                    @csrf
                    <input type="hidden" name="_method" value="PUT">
                    <input type="hidden" name="_form" value="employee_edit">
                    <input type="hidden" name="id" x-model="row.id">
                    <div><label class="bmos-field-label">Nombre</label><input name="name" x-model="row.name" class="bmos-input" required></div>
                    <div><label class="bmos-field-label">Cargo</label><input name="position" x-model="row.position" class="bmos-input"></div>
                    <div class="grid grid-cols-2 gap-3">
                        <div><label class="bmos-field-label">Correo</label><input name="email" type="email" x-model="row.email" class="bmos-input"></div>
                        <div><label class="bmos-field-label">Salario</label><input name="salary" type="number" step="0.01" x-model="row.salary" class="bmos-input"></div>
                    </div>
                    <div class="flex justify-end gap-2 pt-3">
                        <button type="button" @click="open=false" class="bmos-btn bmos-btn-ghost">Cancelar</button>
                        <button type="submit" class="bmos-btn bmos-btn-primary">Guardar cambios</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        function employeesCrud() {
            return {
                open: false,
                row: { id: '', name: '', position: '', email: '', salary: '' },
                get editUrl() { return '{{ url('panel/rrhh') }}/' + this.row.id; },
                edit(data) { this.row = { ...data }; this.open = true; },
                init() {
                    @if (old('_form') === 'employee_edit')
                        this.row = {
                            id: '{{ old('id') }}', name: @js(old('name')), position: @js(old('position')),
                            email: @js(old('email')), salary: @js(old('salary')),
                        };
                        this.open = true;
                    @endif
                },
            };
        }
    </script>
</x-layouts.admin>
