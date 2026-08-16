{{--
    Portal del empleado: su propia ficha y sus asistencias.

    El caso «sin ficha» no es raro, es el NORMAL: el dueño de un colmado no suele estar en su propia
    plantilla. Antes esta pantalla le decía «Tu usuario no está vinculado a un empleado» y ahí
    terminaba, sin decir qué significa eso ni qué hacer. Un aviso que no ofrece salida deja a quien lo
    lee pensando que algo se rompió.
--}}
<x-layouts.app title="Mi perfil">
    <x-slot:header>
        <div class="flex items-center justify-between gap-3">
            <h1 class="text-xl font-semibold text-gray-900">Portal del empleado</h1>
            <a href="{{ route('dashboard') }}" class="text-sm font-medium text-indigo-600 hover:text-indigo-500">
                ← Volver al panel
            </a>
        </div>
    </x-slot:header>

    @if ($employee === null)
        <div class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
            <p class="font-semibold text-slate-800">Esta cuenta no tiene ficha de empleado.</p>
            <p class="mt-2 text-sm leading-relaxed text-slate-600">
                Aquí se ven la ficha y las asistencias de quien está en la plantilla. Tu cuenta entra al
                sistema, pero no está asociada a ningún empleado, así que no hay nada que enseñar.
                @can('users.manage')
                    Si debería estarlo, se arregla en un momento.
                @else
                    Si crees que debería estarlo, pídeselo a tu encargado.
                @endcan
            </p>

            {{-- El enlace solo a quien puede resolverlo. A los demás, mandarlos a una pantalla que les
                 dará un 403 sería cambiar un callejón sin salida por otro. --}}
            @can('users.manage')
                <div class="mt-4 rounded-lg bg-slate-50 p-4 text-sm text-slate-600">
                    <p>En <b>Usuarios</b>, edita la cuenta y elige la persona en «¿Es uno de tus empleados?».
                       Si todavía no tiene ficha, créala antes en <b>Equipo</b>.</p>
                    <div class="mt-3 flex flex-wrap gap-2">
                        <a href="{{ route('panel.users') }}" class="bmos-btn bmos-btn-primary text-sm">Ir a Usuarios</a>
                        @can('hr.manage')
                            <a href="{{ route('panel.employees') }}" class="bmos-btn bmos-btn-ghost text-sm">Ir a Equipo</a>
                        @endcan
                    </div>
                </div>
            @endcan
        </div>
    @else
        <div class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm" data-testid="employee-card">
            <p class="text-sm text-slate-500">Empleado</p>
            <p class="mt-1 text-lg font-semibold text-slate-800">{{ $employee->name }}</p>
            <p class="text-sm text-slate-500">{{ $employee->position ?? 'Sin cargo' }}</p>

            @can('delivery.own')
                <a href="{{ route('portal.deliveries') }}" class="bmos-btn bmos-btn-primary mt-4 text-sm">
                    Ver mis entregas
                </a>
            @endcan
        </div>

        <div class="mt-6 rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
            <h2 class="mb-3 font-semibold text-slate-800">Asistencias recientes</h2>
            @forelse ($employee->attendances as $attendance)
                <div class="flex justify-between border-b border-slate-100 py-2 text-sm">
                    <span class="text-slate-700">{{ $attendance->clock_in->format('d/m/Y H:i') }}</span>
                    <span class="text-slate-500">
                        {{ $attendance->clock_out?->format('H:i') ?? 'En curso' }}
                    </span>
                </div>
            @empty
                <p class="text-sm text-slate-500">Sin registros de asistencia todavía.</p>
            @endforelse
        </div>
    @endif
</x-layouts.app>
