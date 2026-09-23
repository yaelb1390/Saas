{{--
    El detalle de UN incidente.

    Misma idea que `monitoring-error.blade.php`: la pestaña «Incidentes» dice «esto está pasando (o
    pasó)»; esta pantalla dice «esto en concreto, desde cuándo, a quién y qué se hizo».
--}}
@php
    $tono = match (true) {
        ! $incidente->estaActivo() => 'ok',
        $incidente->severity === 'critical' => 'grave',
        default => 'aviso',
    };

    $titulo = match ($incidente->status) {
        'investigating' => 'En investigación',
        'resolved' => 'Resuelto',
        'ignored' => 'Ignorado',
        default => 'Abierto: sigue pidiendo atención',
    };

    $severidades = ['critical' => 'Crítica', 'high' => 'Alta', 'medium' => 'Media', 'low' => 'Baja'];
    $duracion = $incidente->duracion();
@endphp

<x-layouts.admin title="{{ $incidente->code }}" heading="{{ $incidente->code }}"
                 subheading="{{ Str::limit($incidente->title, 140) }}">
    <div class="mb-4">
        <a href="{{ route('platform.monitoring', ['pestana' => 'incidentes']) }}" class="text-xs font-medium text-slate-400 hover:text-slate-600">
            ‹ Volver a Incidentes
        </a>
    </div>

    <div class="space-y-4">
        <x-panel.estado :tono="$tono" :titulo="$titulo"
            :nota="'Empezó '.$incidente->started_at?->format('d/m/Y H:i').' · detectado por última vez '.$incidente->last_detected_at?->diffForHumans()
                .($duracion !== null ? ' · lleva '.$duracion->forHumans() : '')">
            <form method="POST" action="{{ route('platform.monitoring.incidents.status', $incidente) }}" class="flex shrink-0 gap-1.5">
                @csrf
                @if ($incidente->status !== 'investigating')
                    <button type="submit" name="accion" value="investigate" class="bmos-btn bmos-btn-ghost text-xs">Investigar</button>
                @endif
                @if ($incidente->status !== 'resolved')
                    <button type="submit" name="accion" value="resolve" class="bmos-btn bmos-btn-ghost text-xs">Resolver</button>
                @endif
                @if ($incidente->status !== 'ignored')
                    <button type="submit" name="accion" value="ignore" class="bmos-btn bmos-btn-ghost text-xs">Ignorar</button>
                @endif
                @if ($incidente->status !== 'open')
                    <button type="submit" name="accion" value="reopen" class="bmos-btn bmos-btn-ghost text-xs">Reabrir</button>
                @endif
            </form>
        </x-panel.estado>

        <x-panel.metricas :items="[
            ['valor' => $incidente->occurrences, 'etiqueta' => 'ocurrencias', 'tono' => 'indigo', 'icono' => 'pulse'],
            ['valor' => $incidente->companies_count, 'etiqueta' => 'empresas afectadas', 'tono' => 'violeta', 'icono' => 'building'],
            ['valor' => $severidades[$incidente->severity] ?? $incidente->severity, 'etiqueta' => 'severidad', 'tono' => 'rojo', 'icono' => 'alert'],
        ]" />

        <div class="bmos-card bmos-card-pad space-y-2">
            <p class="text-sm text-slate-700">{{ $incidente->title }}</p>
            @if ($incidente->description)
                <p class="text-sm text-slate-500">{{ $incidente->description }}</p>
            @endif
            <p class="flex flex-wrap gap-2 text-xs text-slate-400">
                <span class="bmos-badge badge-gray">{{ $incidente->source === 'auto' ? 'Detectado automáticamente' : 'Abierto a mano' }}</span>
                @if ($incidente->service)
                    <span class="bmos-badge badge-blue">{{ $servicios[$incidente->service] ?? $incidente->service }}</span>
                @endif
            </p>
            @if ($incidente->cause)
                <p class="text-xs text-slate-400"><b>Causa:</b> {{ $incidente->cause }}</p>
            @endif
            @if ($incidente->resolution)
                <p class="text-xs text-slate-400"><b>Resolución:</b> {{ $incidente->resolution }}</p>
            @endif
        </div>

        <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
            <div class="bmos-card overflow-hidden">
                <p class="border-b border-slate-100 px-4 py-2.5 text-xs font-semibold uppercase tracking-wide text-slate-400">
                    Por empresa
                </p>
                @forelse ($empresas as $fila)
                    <div class="flex items-center justify-between gap-3 border-b border-slate-50 px-4 py-2 text-sm last:border-0">
                        <span class="text-slate-700">{{ $fila->company?->name ?? 'Empresa #'.$fila->company_id }}</span>
                        <span class="bmos-mono text-slate-400">{{ number_format($fila->hits) }}</span>
                    </div>
                @empty
                    <p class="p-4 text-sm text-slate-400">Sin empresas registradas para este incidente.</p>
                @endforelse
            </div>

            <div class="bmos-card overflow-hidden">
                <p class="border-b border-slate-100 px-4 py-2.5 text-xs font-semibold uppercase tracking-wide text-slate-400">
                    De dónde salió
                </p>
                @forelse ($incidente->links as $enlace)
                    <div class="flex items-center justify-between gap-3 border-b border-slate-50 px-4 py-2 text-sm last:border-0">
                        <span class="text-slate-700">
                            {{ match ($enlace->source_type) {
                                'error_event' => 'Grupo de error', 'system_event' => 'Suceso del sistema', default => 'Comprobación de salud',
                            } }}
                        </span>
                        @if ($enlace->source_type === 'error_event')
                            <a href="{{ route('platform.monitoring.error', $enlace->source_id) }}" class="bmos-mono text-xs text-slate-400 underline">
                                #{{ $enlace->source_id }}
                            </a>
                        @else
                            <span class="bmos-mono text-slate-400">#{{ $enlace->source_id }}</span>
                        @endif
                    </div>
                @empty
                    <p class="p-4 text-sm text-slate-400">Sin origen enlazado.</p>
                @endforelse
            </div>
        </div>
    </div>
</x-layouts.admin>
