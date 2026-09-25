{{--
    Las conversaciones de Instagram que el webhook fue guardando. Es el lado CRM de Social
    Commerce: de aquí se enlaza con un cliente y se abre una oportunidad (auditoría, sección 12-13).
--}}
<x-layouts.admin title="Conversaciones" heading="Conversaciones"
                 subheading="Quién te ha escrito por Instagram desde que activaste Social Commerce">
    <div class="mx-auto max-w-4xl">
        <a href="{{ route('panel.social-commerce.index') }}" class="mb-4 inline-block text-sm text-indigo-600 hover:underline">
            ← Volver a Social Commerce
        </a>

        @forelse ($conversations as $conversation)
            @php $identidad = $conversation->contactIdentity; @endphp
            <a href="{{ route('panel.social-commerce.conversations.show', $conversation) }}"
               class="bmos-card bmos-card-pad mb-3 block transition hover:border-indigo-300">
                <div class="grid grid-cols-1 gap-2 sm:grid-cols-[minmax(0,1fr)_auto]">
                    <div class="min-w-0">
                        <p class="font-semibold text-slate-800">
                            {{ $identidad->display_name ?: ('@'.($identidad->external_username ?: 'sin nombre')) }}
                        </p>
                        <p class="mt-0.5 text-xs text-slate-400">
                            {{ $conversation->messages_count }} {{ $conversation->messages_count === 1 ? 'mensaje' : 'mensajes' }}
                            @if ($conversation->rule?->product)
                                · preguntó por {{ $conversation->rule->product->name }}
                            @endif
                            @if ($conversation->last_message_at)
                                · {{ $conversation->last_message_at->diffForHumans() }}
                            @endif
                        </p>
                    </div>
                    <div class="flex items-center gap-2">
                        @if ($identidad->customer)
                            <span class="bmos-badge badge-green">Cliente: {{ $identidad->customer->name }}</span>
                        @else
                            <span class="bmos-badge badge-gray">Sin enlazar</span>
                        @endif
                    </div>
                </div>
            </a>
        @empty
            <div class="bmos-card bmos-card-pad text-center">
                <p class="text-slate-600">Todavía no te ha escrito nadie.</p>
                <p class="mt-1 text-sm text-slate-400">
                    En cuanto alguien comente una palabra clave de tus reglas, la conversación aparece aquí.
                </p>
            </div>
        @endforelse

        <div class="mt-4">{{ $conversations->links() }}</div>
    </div>
</x-layouts.admin>
