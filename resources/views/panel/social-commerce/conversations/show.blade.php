@php $identidad = $conversation->contactIdentity; @endphp

<x-layouts.admin title="Conversación" :heading="$identidad->display_name ?: '@'.$identidad->external_username"
                 subheading="Conversación de Instagram">
    <div class="mx-auto max-w-3xl">
        <a href="{{ route('panel.social-commerce.conversations.index') }}" class="mb-4 inline-block text-sm text-indigo-600 hover:underline">
            ← Volver a Conversaciones
        </a>

        <div class="grid grid-cols-1 gap-4 lg:grid-cols-[minmax(0,1fr)_18rem]">
            {{-- Línea del tiempo --}}
            <div class="bmos-card bmos-card-pad">
                <p class="mb-3 text-sm font-semibold text-slate-700">Mensajes</p>
                <div class="space-y-2">
                    @foreach ($conversation->messages as $mensaje)
                        <div class="flex {{ $mensaje->direction->value === 'outgoing' ? 'justify-end' : 'justify-start' }}">
                            <div class="max-w-[80%] rounded-2xl px-3 py-2 text-sm
                                        {{ $mensaje->direction->value === 'outgoing' ? 'bg-indigo-600 text-white' : 'bg-slate-100 text-slate-800' }}">
                                <p>{{ $mensaje->body }}</p>
                                <p class="mt-1 text-[10px] {{ $mensaje->direction->value === 'outgoing' ? 'text-indigo-200' : 'text-slate-400' }}">
                                    {{ $mensaje->sent_at->format('d/m H:i') }}
                                </p>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>

            {{-- Lateral: quién es y el puente al CRM --}}
            <div class="space-y-4">
                <div class="bmos-card bmos-card-pad">
                    <p class="text-sm font-semibold text-slate-700">Quién es</p>
                    <p class="mt-1 text-sm text-slate-600">
                        {{ $identidad->display_name ?: 'Sin nombre' }}
                        @if ($identidad->external_username)
                            <span class="block text-xs text-slate-400">@{{ $identidad->external_username }}</span>
                        @endif
                    </p>
                    @if ($conversation->rule?->product)
                        <p class="mt-2 text-xs text-slate-400">Preguntó por: {{ $conversation->rule->product->name }}</p>
                    @endif
                </div>

                <div class="bmos-card bmos-card-pad">
                    <p class="text-sm font-semibold text-slate-700">Cliente del CRM</p>
                    @if ($identidad->customer)
                        <p class="mt-1 text-sm text-emerald-700">✓ {{ $identidad->customer->name }}</p>
                        <a href="{{ route('panel.customers.show', $identidad->customer) }}" class="mt-1 inline-block text-xs text-indigo-600 hover:underline">Ver en CRM</a>
                    @can('social_commerce.manage')
                        <form method="POST" action="{{ route('panel.social-commerce.conversations.link-customer', $conversation) }}" class="mt-3">
                            @csrf
                            <input type="hidden" name="customer_id" value="">
                            <p class="mb-1 text-xs text-slate-500">Cambiar por otro:</p>
                            <select name="customer_id" class="bmos-input text-sm">
                                <option value="">— elegir —</option>
                                @foreach ($clientes as $c)
                                    <option value="{{ $c->id }}">{{ $c->name }}</option>
                                @endforeach
                            </select>
                            <button type="submit" class="bmos-btn bmos-btn-ghost mt-2 w-full text-xs">Cambiar</button>
                        </form>
                    @endcan
                    @else
                        @can('social_commerce.manage')
                            <form method="POST" action="{{ route('panel.social-commerce.conversations.link-customer', $conversation) }}" class="mt-2 space-y-2">
                                @csrf
                                <select name="customer_id" class="bmos-input text-sm">
                                    <option value="">— crear cliente nuevo —</option>
                                    @foreach ($clientes as $c)
                                        <option value="{{ $c->id }}">{{ $c->name }}</option>
                                    @endforeach
                                </select>
                                <input type="text" name="nombre" class="bmos-input text-sm" placeholder="Nombre (si es nuevo)"
                                       value="{{ $identidad->display_name }}">
                                <button type="submit" class="bmos-btn bmos-btn-primary w-full text-xs">Enlazar</button>
                            </form>
                        @else
                            <p class="mt-1 text-sm text-slate-400">Sin enlazar todavía.</p>
                        @endcan
                    @endif
                </div>

                <div class="bmos-card bmos-card-pad">
                    <p class="text-sm font-semibold text-slate-700">Oportunidad</p>
                    @if ($opportunityLink)
                        <p class="mt-1 text-sm text-emerald-700">✓ {{ $opportunityLink->opportunity->title }}</p>
                        <p class="text-xs text-slate-400">{{ $opportunityLink->opportunity->status->value }}</p>
                    @elseif ($identidad->customer === null)
                        <p class="mt-1 text-xs text-slate-400">Enlaza primero un cliente para poder crear la oportunidad.</p>
                    @else
                        @can('social_commerce.manage')
                            <form method="POST" action="{{ route('panel.social-commerce.conversations.opportunity', $conversation) }}" class="mt-2">
                                @csrf
                                <button type="submit" class="bmos-btn bmos-btn-primary w-full text-xs">Crear oportunidad</button>
                            </form>
                        @endcan
                    @endif
                </div>
            </div>
        </div>
    </div>
</x-layouts.admin>
