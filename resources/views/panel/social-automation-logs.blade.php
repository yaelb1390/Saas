{{--
    Los últimos disparos de una respuesta automática.

    Es la respuesta a «lo monté y no hace nada»: sin esto, la única salida del cliente es escribir
    preguntando. Se enseña quién escribió, qué escribió y si el mensaje llegó.
--}}
<x-layouts.admin title="Registro" heading="Últimos disparos"
                 subheading="A quién le ha contestado esta respuesta automática">
    <div class="mx-auto max-w-3xl">
        <a href="{{ route('panel.social.automations') }}" class="mb-4 inline-block text-sm text-indigo-600 hover:underline">
            ← Volver a las respuestas automáticas
        </a>

        <div class="bmos-card overflow-hidden">
            @forelse ($logs as $log)
                <div class="border-b border-slate-50 p-4 last:border-0">
                    <div class="flex flex-wrap items-start justify-between gap-2">
                        <p class="font-medium text-slate-800">
                            {{ $log['contactName'] ?? $log['username'] ?? 'Alguien' }}
                        </p>
                        @php
                            $estado = (string) ($log['status'] ?? $log['result'] ?? '');
                            $ok = in_array($estado, ['sent', 'delivered', 'success'], true);
                        @endphp
                        <span class="bmos-badge {{ $ok ? 'badge-green' : ($estado === '' ? 'badge-gray' : 'badge-amber') }}">
                            {{ $ok ? 'Enviado' : ($estado ?: '—') }}
                        </span>
                    </div>
                    @if (filled($log['commentText'] ?? $log['text'] ?? null))
                        <p class="mt-1 text-sm text-slate-600">«{{ $log['commentText'] ?? $log['text'] }}»</p>
                    @endif
                    @if (filled($log['error'] ?? null))
                        <p class="mt-1 text-xs text-rose-600">{{ $log['error'] }}</p>
                    @endif
                    @if (filled($log['createdAt'] ?? null))
                        <p class="mt-1 text-xs text-slate-400">{{ $log['createdAt'] }}</p>
                    @endif
                </div>
            @empty
                {{-- Vacío no significa roto: puede que nadie haya comentado todavía. Se dice, porque
                     una pantalla en blanco se lee como un fallo. --}}
                <p class="bmos-empty">
                    Todavía no se ha disparado. Si acabas de crearla, es lo normal: espera a que alguien
                    comente una de tus palabras.
                </p>
            @endforelse
        </div>
    </div>
</x-layouts.admin>
