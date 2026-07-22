<x-layouts.app title="Mi perfil">
    <x-slot:header>
        <div class="flex items-center justify-between">
            <h1 class="text-xl font-semibold text-gray-900">Portal del empleado</h1>
            <a href="{{ route('dashboard') }}" class="text-sm font-medium text-indigo-600 hover:text-indigo-500">
                ← Volver al dashboard
            </a>
        </div>
    </x-slot:header>

    @if ($employee === null)
        <div class="rounded-xl bg-white p-6 shadow">
            <p class="text-gray-500">Tu usuario no está vinculado a un empleado.</p>
        </div>
    @else
        <div class="rounded-xl bg-white p-6 shadow" data-testid="employee-card">
            <p class="text-sm text-gray-500">Empleado</p>
            <p class="mt-1 text-lg font-semibold">{{ $employee->name }}</p>
            <p class="text-sm text-gray-500">{{ $employee->position ?? 'Sin cargo' }}</p>
        </div>

        <div class="mt-6 rounded-xl bg-white p-6 shadow">
            <h2 class="mb-3 font-semibold text-gray-900">Asistencias recientes</h2>
            @forelse ($employee->attendances as $attendance)
                <div class="flex justify-between border-b border-gray-100 py-2 text-sm">
                    <span>{{ $attendance->clock_in->format('d/m/Y H:i') }}</span>
                    <span class="text-gray-500">
                        {{ $attendance->clock_out?->format('H:i') ?? 'En curso' }}
                    </span>
                </div>
            @empty
                <p class="text-sm text-gray-500">Sin registros de asistencia.</p>
            @endforelse
        </div>
    @endif
</x-layouts.app>
