{{--
    Reglas de Social Commerce: palabra clave → producto → precio → plantilla.

    Zernio ejecuta la automatización (igual que en Redes sociales); esta pantalla guarda el
    producto, el precio y las plantillas, y las traduce a lo que la API de Zernio entiende cada
    vez que se guardan (ver RuleSyncService).
--}}
<x-layouts.admin title="Social Commerce" heading="Social Commerce"
                 subheading="Contesta el precio en tus comentarios de Instagram, con tus propios productos">
    <div class="mx-auto max-w-4xl">
        <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
            <div class="flex flex-wrap gap-4">
                <a href="{{ route('panel.social-commerce.dashboard') }}" class="text-sm text-indigo-600 hover:underline">
                    Dashboard →
                </a>
                <a href="{{ route('panel.social-commerce.settings') }}" class="text-sm text-indigo-600 hover:underline">
                    Ajustes de conexión y WhatsApp →
                </a>
                <a href="{{ route('panel.social-commerce.conversations.index') }}" class="text-sm text-indigo-600 hover:underline">
                    Conversaciones →
                </a>
                @can('social_commerce.manage')
                    <a href="{{ route('panel.social-commerce.sandbox') }}" class="text-sm text-indigo-600 hover:underline">
                        Probar una regla →
                    </a>
                @endcan
            </div>
            @can('social_commerce.manage')
                <a href="{{ route('panel.social-commerce.create') }}" class="bmos-btn bmos-btn-primary">
                    + Nueva regla
                </a>
            @endcan
        </div>

        @if ($cuentas === [])
            <div class="bmos-card bmos-card-pad">
                <p class="font-semibold text-slate-800">Primero conecta tu cuenta de Instagram o Facebook</p>
                <p class="mt-2 text-sm text-slate-600">
                    Las reglas necesitan una cuenta conectada.
                    <a href="{{ route('panel.social-commerce.settings') }}" class="text-indigo-600 hover:underline">Ir a Ajustes</a>.
                </p>
            </div>
        @endif

        @forelse ($rules as $rule)
            <div class="bmos-card bmos-card-pad mb-4 {{ $rule->status->value === 'active' ? '' : 'is-apagada' }}">
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-[minmax(0,1fr)_auto]">
                    <div class="min-w-0">
                        <p class="font-semibold text-slate-800">{{ $rule->name }}</p>
                        <p class="mt-1 text-sm text-slate-600">
                            {{ $rule->product?->name ?? 'Producto borrado' }}
                            @if ($rule->product)
                                · {{ $rule->company->currency }} {{ number_format((float) $rule->product->price, 2) }}
                            @endif
                        </p>

                        <div class="mt-1.5 flex flex-wrap items-center gap-1">
                            @foreach (array_slice($rule->keywords, 0, 6) as $palabra)
                                <span class="bmos-clave">{{ $palabra }}</span>
                            @endforeach
                            @if (count($rule->keywords) > 6)
                                <span class="bmos-clave-mas">+{{ count($rule->keywords) - 6 }}</span>
                            @endif
                        </div>

                        <p class="mt-1.5 text-xs text-slate-400">
                            {{ $rule->dmTemplates->count() + $rule->publicTemplates->count() }} plantillas
                            @if ($rule->sync_error)
                                · <span class="text-rose-600">{{ $rule->sync_error }}</span>
                            @elseif ($rule->last_synced_at)
                                · sincronizada {{ $rule->last_synced_at->diffForHumans() }}
                            @endif
                        </p>
                    </div>

                    @can('social_commerce.manage')
                        <div class="flex flex-col items-stretch gap-2 sm:items-end">
                            <span class="bmos-badge {{ $rule->status->value === 'active' ? 'badge-green' : ($rule->status->value === 'error' ? 'badge-red' : 'badge-gray') }}">
                                {{ $rule->status->label() }}
                            </span>
                            <div class="flex gap-2">
                                <a href="{{ route('panel.social-commerce.edit', $rule) }}" class="bmos-btn bmos-btn-ghost">Editar</a>
                                <form method="POST" action="{{ route('panel.social-commerce.toggle', $rule) }}">
                                    @csrf
                                    <input type="hidden" name="is_active" value="{{ $rule->status->value === 'active' ? '0' : '1' }}">
                                    <button type="submit" class="bmos-btn bmos-btn-ghost">
                                        {{ $rule->status->value === 'active' ? 'Pausar' : 'Encender' }}
                                    </button>
                                </form>
                                <form method="POST" action="{{ route('panel.social-commerce.destroy', $rule) }}"
                                      onsubmit="return confirm('¿Borrar esta regla? Deja de contestar en Instagram.');">
                                    @csrf @method('DELETE')
                                    <button type="submit" class="bmos-btn bmos-btn-ghost text-rose-600">Borrar</button>
                                </form>
                            </div>
                        </div>
                    @endcan
                </div>
            </div>
        @empty
            <div class="bmos-card bmos-card-pad text-center">
                <p class="text-slate-600">Todavía no tienes ninguna regla.</p>
                <p class="mt-1 text-sm text-slate-400">
                    Elige un producto, sus palabras clave y qué contesta, y Instagram empieza a responder solo.
                </p>
            </div>
        @endforelse
    </div>
</x-layouts.admin>
