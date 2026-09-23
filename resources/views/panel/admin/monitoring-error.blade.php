{{--
    El detalle de UN grupo de errores.

    La pestaña «Errores» responde «esto se está rompiendo»; esta pantalla responde «esto en concreto,
    ¿a quién le pasó y ya se atendió?». Va aparte porque son dos preguntas distintas, no una pantalla
    más larga.
--}}
@php
    $tono = match (true) {
        $conDesglose && $error->status === 'resolved' => 'ok',
        $conDesglose && $error->status === 'ignored' => 'ok',
        $error->hits > 10 => 'grave',
        default => 'aviso',
    };

    $titulo = match (true) {
        $conDesglose && $error->status === 'resolved' => 'Resuelto',
        $conDesglose && $error->status === 'ignored' => 'Ignorado',
        default => 'Activo: sigue pidiendo atención',
    };
@endphp

<x-layouts.admin title="{{ class_basename($error->class) }}"
                 heading="{{ class_basename($error->class) }}"
                 subheading="{{ Str::limit($error->message, 140) }}">
    <div class="mb-4">
        <a href="{{ route('platform.monitoring', ['pestana' => 'errores']) }}" class="text-xs font-medium text-slate-400 hover:text-slate-600">
            ‹ Volver a Errores
        </a>
    </div>

    <div class="space-y-4">
        <x-panel.estado :tono="$tono" :titulo="$titulo"
            :nota="'Visto por primera vez '.$error->first_seen_at?->format('d/m/Y H:i').' · la última vez '.$error->last_seen_at?->diffForHumans()">
            @if ($conDesglose)
                <form method="POST" action="{{ route('platform.monitoring.error.status', $error) }}" class="flex shrink-0 gap-1.5">
                    @csrf
                    @if ($error->status !== 'resolved')
                        <button type="submit" name="accion" value="resolve" class="bmos-btn bmos-btn-ghost text-xs">Resolver</button>
                    @endif
                    @if ($error->status !== 'ignored')
                        <button type="submit" name="accion" value="ignore" class="bmos-btn bmos-btn-ghost text-xs">Ignorar</button>
                    @endif
                    @if ($error->status !== 'active')
                        <button type="submit" name="accion" value="reopen" class="bmos-btn bmos-btn-ghost text-xs">Reabrir</button>
                    @endif
                </form>
            @endif
        </x-panel.estado>

        <x-panel.metricas :items="[
            ['valor' => $error->hits, 'etiqueta' => 'ocurrencias', 'tono' => 'indigo', 'icono' => 'pulse'],
            ['valor' => $conDesglose ? $error->companies_count : ($error->company ? 1 : 0), 'etiqueta' => 'empresas afectadas', 'tono' => 'violeta', 'icono' => 'building'],
            ['valor' => $conDesglose ? $error->users_count : ($error->user_id ? 1 : 0), 'etiqueta' => 'usuarios afectados', 'tono' => 'azul', 'icono' => 'users'],
        ]" />

        <div class="bmos-card bmos-card-pad space-y-2">
            <p class="text-sm text-slate-700">{{ $error->message }}</p>
            <p class="bmos-mono text-xs text-slate-400">{{ $error->origin }}</p>
            <p class="flex flex-wrap gap-2 text-xs text-slate-400">
                <span class="bmos-badge badge-gray">{{ $error->class }}</span>
                @if ($error->service)
                    <span class="bmos-badge badge-blue">{{ $servicios[$error->service] ?? $error->service }}</span>
                @endif
                @if ($error->route_name)
                    <span class="bmos-badge badge-gray">{{ $error->route_name }}</span>
                @endif
                @if ($error->esHistorico())
                    <span class="bmos-badge badge-gray" title="Agrupado antes de que existiera el desglose por empresa: su reparto es aproximado.">
                        histórico v1
                    </span>
                @endif
            </p>
            @if ($error->url)
                <p class="bmos-mono text-xs text-slate-400">{{ $error->url }}</p>
            @endif
        </div>

        @if ($conDesglose)
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
                        <p class="p-4 text-sm text-slate-400">Sin empresas registradas para este error.</p>
                    @endforelse
                    @if ($hitsSinEmpresa > 0)
                        <div class="flex items-center justify-between gap-3 px-4 py-2 text-sm">
                            <span class="text-slate-400">Sin empresa (plataforma, consola, sin sesión)</span>
                            <span class="bmos-mono text-slate-400">{{ number_format($hitsSinEmpresa) }}</span>
                        </div>
                    @endif
                </div>

                <div class="bmos-card overflow-hidden">
                    <p class="border-b border-slate-100 px-4 py-2.5 text-xs font-semibold uppercase tracking-wide text-slate-400">
                        Usuarios afectados
                    </p>
                    @forelse ($usuarios as $fila)
                        <div class="flex items-center justify-between gap-3 border-b border-slate-50 px-4 py-2 text-sm last:border-0">
                            <span class="text-slate-700">{{ $fila->user?->name ?? 'Usuario #'.$fila->user_id }}</span>
                            <span class="bmos-mono text-slate-400">{{ number_format($fila->hits) }}</span>
                        </div>
                    @empty
                        <p class="p-4 text-sm text-slate-400">Sin usuarios identificados para este error.</p>
                    @endforelse
                    @if ($usuariosTotal > $usuarios->count())
                        <p class="px-4 py-2 text-xs text-slate-400">…y {{ $usuariosTotal - $usuarios->count() }} más.</p>
                    @endif
                </div>
            </div>
        @else
            <p class="bmos-empty">
                Este grupo se agrupó antes de la migración de errores multiempresa: no tiene desglose por
                empresa ni usuario, solo el último conocido.
            </p>
        @endif
    </div>
</x-layouts.admin>
