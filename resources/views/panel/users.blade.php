@php
    use App\Modules\Core\Support\RoleCatalog;

    $roleBadge = fn (string $role) => match ($role) {
        'owner' => 'badge-violet',
        'admin' => 'badge-blue',
        default => 'badge-gray',
    };
@endphp
<x-layouts.admin title="Usuarios" heading="Usuarios y roles" subheading="Personas que acceden al sistema y qué puede hacer cada una">
    <div x-data="usersCrud()">
        {{-- Leyenda de roles: qué puede hacer cada uno, para elegir con criterio al asignarlo. --}}
        <div class="mb-5 rounded-xl border border-slate-200 bg-slate-50/70 p-4">
            <p class="mb-3 text-sm font-semibold text-slate-700">¿Qué puede hacer cada rol?</p>
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                @foreach ($roles as $role)
                    <div class="rounded-lg bg-white p-3 ring-1 ring-slate-100">
                        <span class="bmos-badge {{ $roleBadge($role) }}">{{ RoleCatalog::label($role) }}</span>
                        <p class="mt-2 text-xs leading-relaxed text-slate-500">{{ RoleCatalog::hint($role) }}</p>
                    </div>
                @endforeach
            </div>
        </div>

        <div class="bmos-card overflow-hidden">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 p-4">
                <div>
                    <p class="font-semibold text-slate-800">Usuarios de la empresa</p>
                    <p class="text-sm text-slate-500">El rol define a qué módulos entra cada persona.</p>
                </div>
                <x-panel.create-modal title="Nuevo usuario" label="Nuevo usuario" form="user_create" :action="route('panel.users.store')">
                    <x-panel.field name="name" label="Nombre" required placeholder="Nombre y apellido" />
                    <x-panel.field name="email" label="Correo (acceso)" type="email" required placeholder="persona@empresa.com" />
                    <div>
                        <label class="bmos-field-label">Rol</label>
                        <select name="role" class="bmos-input" required>
                            @foreach ($roles as $role)
                                <option value="{{ $role }}" @selected(old('role') === $role)>{{ RoleCatalog::label($role) }} — {{ RoleCatalog::hint($role) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <x-panel.field name="password" label="Contraseña" type="password" required />
                        <x-panel.field name="password_confirmation" label="Repetir contraseña" type="password" required />
                    </div>
                    {{-- De quién es la cuenta. Para un repartidor NO es opcional: de este vínculo sale
                         qué entregas ve en su móvil, y sin él su portal aparece vacío. --}}
                    <div>
                        <label class="bmos-field-label">¿Es uno de tus empleados?</label>
                        <select name="employee_id" class="bmos-input">
                            <option value="">— No, es solo una cuenta —</option>
                            @foreach ($employees as $employee)
                                <option value="{{ $employee->id }}" @selected((string) old('employee_id') === (string) $employee->id)>
                                    {{ $employee->name }}@if ($employee->position) · {{ $employee->position }}@endif
                                </option>
                            @endforeach
                        </select>
                        <p class="mt-1 text-xs text-slate-400">
                            Un repartidor necesita esto: es lo que le enseña sus entregas.
                            @if ($employees->isEmpty())
                                <span class="text-amber-600">No hay empleados sin cuenta; dalo de alta primero en Equipo.</span>
                            @endif
                        </p>
                    </div>
                </x-panel.create-modal>
            </div>

            <div class="overflow-x-auto">
                <table class="bmos-table">
                    <thead><tr><th>Usuario</th><th>Correo</th><th>Rol</th><th>Estado</th><th class="text-right">Acciones</th></tr></thead>
                    <tbody>
                        @forelse ($users as $user)
                            @php $role = $user->roles->first()?->name ?? 'staff'; @endphp
                            <tr class="{{ $user->is_active ? '' : 'opacity-60' }}">
                                <td class="flex items-center gap-2 font-medium text-slate-800">
                                    <span class="flex h-8 w-8 items-center justify-center rounded-full bg-indigo-100 text-xs font-bold text-indigo-600">
                                        {{ strtoupper(mb_substr($user->name, 0, 1)) }}
                                    </span>
                                    {{ $user->name }}
                                </td>
                                <td class="text-slate-500">
                                    {{ $user->email }}
                                    @if ($vinculos->has($user->id))
                                        <span class="block text-xs text-slate-400">ficha de {{ $vinculos[$user->id]->name }}</span>
                                    @elseif ($role === 'driver')
                                        {{-- Un repartidor sin ficha entra y no ve nada: la lista de
                                             repartidores de Entregas se saca de los EMPLEADOS, no de
                                             los usuarios. Ya no debería pasar —la ficha se crea sola
                                             al dar de alta la cuenta— pero queda el aviso por si
                                             alguien la borra, y ahora dice qué hacer. --}}
                                        <span class="block text-xs font-medium text-amber-600">
                                            Sin ficha de empleado: no aparecerá en Entregas.
                                            @can('hr.manage')
                                                <a href="{{ route('panel.employees') }}" class="underline">Créala en Equipo</a>
                                                y luego elígela aquí al editarlo.
                                            @endcan
                                        </span>
                                    @endif
                                </td>
                                <td><span class="bmos-badge {{ $roleBadge($role) }}">{{ RoleCatalog::label($role) }}</span></td>
                                <td>
                                    <span class="bmos-badge {{ $user->is_active ? 'badge-green' : 'badge-gray' }}">
                                        {{ $user->is_active ? 'Activo' : 'Inactivo' }}
                                    </span>
                                </td>
                                <td>
                                    <div class="flex items-center justify-end gap-1">
                                        <button type="button" class="rounded-lg p-1.5 text-slate-500 hover:bg-slate-100 hover:text-indigo-600" title="Editar"
                                                @click="edit({ id: {{ $user->id }}, name: @js($user->name), email: @js($user->email), role: @js($role), is_active: {{ $user->is_active ? 'true' : 'false' }}, employee_id: @js((string) ($vinculos[$user->id]->id ?? '')), employee_name: @js($vinculos[$user->id]->name ?? '') })">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" style="width:1.15rem;height:1.15rem"><path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Z"/></svg>
                                        </button>
                                        {{-- Desactivar no borra: el usuario queda ahí y se puede volver a
                                             activar cuando haga falta. De ahí que el aviso sea índigo y no
                                             rojo, aunque el icono siga siendo el de bloquear. --}}
                                        <x-panel.confirm-action
                                            :action="route('panel.users.toggle', $user)"
                                            method="POST"
                                            tone="neutral"
                                            title="{{ $user->is_active ? '¿Desactivar' : '¿Reactivar' }} a «{{ $user->name }}»?"
                                            {{-- La comprobación es al entrar (ver FortifyServiceProvider), no en
                                                 cada petición: quien ya tenga la sesión abierta la conserva. Se
                                                 dice, porque de otro modo se supone que el corte es inmediato. --}}
                                            :message="$user->is_active
                                                ? 'No podrá volver a entrar al sistema. Si tiene la sesión abierta ahora mismo, la conserva hasta que la cierre.'
                                                : 'Podrá volver a entrar con su correo y su contraseña de siempre.'"
                                            :note="$user->is_active ? 'Se puede reactivar en cualquier momento desde esta misma pantalla.' : null"
                                            confirm="{{ $user->is_active ? 'Desactivar' : 'Reactivar' }}"
                                            tooltip="{{ $user->is_active ? 'Desactivar' : 'Reactivar' }}"
                                            class="rounded-lg p-1.5 {{ $user->is_active ? 'text-slate-500 hover:bg-rose-50 hover:text-rose-600' : 'text-slate-500 hover:bg-emerald-50 hover:text-emerald-600' }}">
                                            @if ($user->is_active)
                                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" style="width:1.15rem;height:1.15rem"><path stroke-linecap="round" stroke-linejoin="round" d="M18.364 18.364A9 9 0 0 0 5.636 5.636m12.728 12.728A9 9 0 0 1 5.636 5.636m12.728 12.728L5.636 5.636"/></svg>
                                            @else
                                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" style="width:1.15rem;height:1.15rem"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/></svg>
                                            @endif
                                        </x-panel.confirm-action>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="bmos-empty">Solo estás tú. Crea usuarios para tu equipo.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        <div class="mt-4">{{ $users->links() }}</div>

        {{-- Modal de edición --}}
        <div x-show="open" x-cloak class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-900/50 p-4 py-10" @keydown.escape.window="open=false">
            <div @click.outside="open=false" x-transition class="w-full max-w-lg rounded-2xl bg-white p-6 shadow-2xl">
                <div class="mb-4 flex items-center justify-between">
                    <h3 class="text-lg font-semibold text-slate-800">Editar usuario</h3>
                    <button type="button" @click="open=false" class="text-slate-400 hover:text-slate-600">✕</button>
                </div>
                @if (old('_form') === 'user_edit' && $errors->any())
                    <div class="mb-4 rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700">
                        <ul class="list-disc pl-4">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
                    </div>
                @endif
                <form method="POST" :action="editUrl" class="space-y-3">
                    @csrf
                    <input type="hidden" name="_method" value="PUT">
                    <input type="hidden" name="_form" value="user_edit">
                    <input type="hidden" name="id" x-model="row.id">
                    <div><label class="bmos-field-label">Nombre</label><input name="name" x-model="row.name" class="bmos-input" required></div>
                    <div><label class="bmos-field-label">Correo</label><input name="email" type="email" x-model="row.email" class="bmos-input" required></div>
                    <div>
                        <label class="bmos-field-label">Rol</label>
                        <select name="role" x-model="row.role" class="bmos-input" required>
                            @foreach ($roles as $role)
                                <option value="{{ $role }}">{{ RoleCatalog::label($role) }} — {{ RoleCatalog::hint($role) }}</option>
                            @endforeach
                        </select>
                        {{-- Recordatorio del alcance del rol elegido, visible al editar. --}}
                        <p class="mt-1.5 text-xs text-slate-500" x-text="roleHint(row.role)"></p>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div><label class="bmos-field-label">Nueva contraseña</label><input name="password" type="password" class="bmos-input" placeholder="Dejar vacío para no cambiar"></div>
                        <div><label class="bmos-field-label">Repetir contraseña</label><input name="password_confirmation" type="password" class="bmos-input"></div>
                    </div>
                    <div>
                        <label class="bmos-field-label">¿Es uno de tus empleados?</label>
                        {{-- La lista trae los empleados SIN cuenta; el propio de este usuario se añade
                             delante, porque si no, abrir y guardar sin tocar nada lo desvincularía. --}}
                        <select name="employee_id" x-model="row.employee_id" class="bmos-input">
                            <option value="">— No, es solo una cuenta —</option>
                            <template x-if="row.employee_id">
                                <option :value="row.employee_id" x-text="row.employee_name"></option>
                            </template>
                            @foreach ($employees as $employee)
                                <option value="{{ $employee->id }}">{{ $employee->name }}@if ($employee->position) · {{ $employee->position }}@endif</option>
                            @endforeach
                        </select>
                    </div>
                    <label class="flex items-center gap-2 text-sm text-slate-600">
                        <input type="checkbox" name="is_active" value="1" x-model="row.is_active" class="rounded border-slate-300">
                        Usuario activo (puede iniciar sesión)
                    </label>
                    <div class="flex justify-end gap-2 pt-3">
                        <button type="button" @click="open=false" class="bmos-btn bmos-btn-ghost">Cancelar</button>
                        <button type="submit" class="bmos-btn bmos-btn-primary">Guardar cambios</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        function usersCrud() {
            return {
                open: false,
                row: { id: '', name: '', email: '', role: 'staff', is_active: true, employee_id: '', employee_name: '' },
                // Pistas de cada rol (fuente: RoleCatalog), para mostrar el alcance al editar.
                roleHints: @js(collect($roles)->mapWithKeys(fn ($r) => [$r => RoleCatalog::hint($r)])),
                roleHint(role) { return this.roleHints[role] ?? ''; },
                get editUrl() { return '{{ url('panel/usuarios') }}/' + this.row.id; },
                edit(data) { this.row = { ...data }; this.open = true; },
                init() {
                    @if (old('_form') === 'user_edit')
                        this.row = {
                            id: '{{ old('id') }}', name: @js(old('name')), email: @js(old('email')),
                            role: @js(old('role')), is_active: {{ old('is_active') ? 'true' : 'false' }},
                            employee_id: @js((string) old('employee_id')), employee_name: '',
                        };
                        this.open = true;
                    @endif
                },
            };
        }
    </script>
</x-layouts.admin>
