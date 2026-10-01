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
            <nav class="sc-subnav">
                <a href="{{ route('panel.social-commerce.dashboard') }}" class="sc-subnav-link">
                    <x-icono name="pulse" class="h-4 w-4" />
                    Dashboard
                </a>
                <a href="{{ route('panel.social-commerce.settings') }}" class="sc-subnav-link">
                    <x-icono name="wrench" class="h-4 w-4" />
                    Ajustes de conexión y WhatsApp
                </a>
                <a href="{{ route('panel.social-commerce.conversations.index') }}" class="sc-subnav-link">
                    <x-icono name="chat" class="h-4 w-4" />
                    Conversaciones
                </a>
                @can('social_commerce.manage')
                    <a href="{{ route('panel.social-commerce.sandbox') }}" class="sc-subnav-link">
                        <x-icono name="spark" class="h-4 w-4" />
                        Probar una regla
                    </a>
                @endcan
            </nav>
            @can('social_commerce.manage')
                <a href="{{ route('panel.social-commerce.create') }}" class="bmos-btn bmos-btn-primary">
                    + Nueva regla
                </a>
            @endcan
        </div>

        @if ($cuentas === [])
            <div class="bmos-card bmos-card-pad mb-4 flex items-start gap-3">
                <x-icono name="alert" class="mt-0.5 h-5 w-5 shrink-0 text-amber-500" />
                <div>
                    <p class="font-semibold text-slate-800">Primero conecta tu cuenta de Instagram o Facebook</p>
                    <p class="mt-1 text-sm text-slate-600">
                        Las reglas necesitan una cuenta conectada.
                        <a href="{{ route('panel.social-commerce.settings') }}" class="font-semibold text-indigo-600 hover:underline">Ir a Ajustes</a>.
                    </p>
                </div>
            </div>
        @endif

        @if ($diagnostico['aviso'])
            <div class="bmos-card bmos-card-pad mb-4 flex items-start gap-3 border border-amber-200 bg-amber-50">
                <x-icono name="alert" class="mt-0.5 h-5 w-5 shrink-0 text-amber-500" />
                <p class="text-sm text-amber-800">{{ $diagnostico['aviso'] }}</p>
            </div>
        @endif

        @forelse ($rules as $rule)
            <div class="bmos-card bmos-card-pad mb-4 {{ $rule->status->value === 'active' ? '' : 'is-apagada' }}">
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-[auto_minmax(0,1fr)_auto]">
                    <div class="hidden sm:block">
                        <span class="sc-regla-icono">
                            <x-icono name="chat" class="h-5 w-5" />
                        </span>
                    </div>

                    <div class="min-w-0">
                        <p class="font-semibold text-slate-800">{{ $rule->name }}</p>
                        <p class="mt-1 flex flex-wrap items-center gap-x-1.5 text-sm">
                            @if (! $rule->esManual() && ! $rule->product)
                                {{-- Una regla sin producto ya no tiene qué vender: no es un dato
                                     cualquiera, es la razón por la que puede estar fallando. --}}
                                <span class="font-semibold text-rose-600">Producto borrado</span>
                            @else
                                <span class="text-slate-600">{{ $rule->esManual() ? $rule->manual_name : $rule->product->name }}</span>
                                <span class="inline-flex items-center gap-1 font-semibold text-indigo-600">
                                    <x-icono name="tag" class="h-3.5 w-3.5 text-indigo-400" />
                                    {{ $rule->company->currency }} {{ number_format($rule->precioDelArticulo(), 2) }}
                                </span>
                            @endif
                        </p>

                        <div class="mt-2 flex flex-wrap items-center gap-1">
                            @foreach (array_slice($rule->keywords, 0, 6) as $palabra)
                                <span class="bmos-clave">{{ $palabra }}</span>
                            @endforeach
                            @if (count($rule->keywords) > 6)
                                <span class="bmos-clave-mas">+{{ count($rule->keywords) - 6 }}</span>
                            @endif
                        </div>

                        <p class="mt-2 flex items-center gap-1 text-xs text-slate-400">
                            {{ $rule->dmTemplates->count() + $rule->publicTemplates->count() }} plantillas
                            @if ($rule->sync_error)
                                · <span class="inline-flex items-center gap-1 text-rose-600">
                                    <x-icono name="alert" class="h-3.5 w-3.5" />
                                    {{ $rule->sync_error }}
                                </span>
                            @elseif ($rule->last_synced_at)
                                · <span class="inline-flex items-center gap-1">
                                    <x-icono name="reloj" class="h-3.5 w-3.5" />
                                    sincronizada {{ $rule->last_synced_at->diffForHumans() }}
                                </span>
                            @endif
                        </p>

                        {{-- Lo que BMOS guarda es la intención; esto es lo que Zernio confirma ahora
                             mismo. Las dos pueden separarse sin que nadie se entere —alguien pausó la
                             automatización directo en Zernio, la cuenta perdió el permiso, o la
                             borraron allá—, y es justo lo que esto saca a la luz. --}}
                        @if ($rule->estaSincronizada() && ! $diagnostico['aviso'])
                            @php
                                $real = $diagnostico['automatizaciones'][$rule->zernio_automation_id] ?? null;
                                $cuentaReal = $diagnostico['cuentas'][$rule->zernio_account_id] ?? null;
                                $coincide = $real !== null && $real['isActive'] === ($rule->status->value === 'active');
                            @endphp

                            @if ($real === null)
                                <p class="mt-2 flex items-center gap-1.5 rounded-lg bg-rose-50 px-2.5 py-1.5 text-xs font-semibold text-rose-700">
                                    <x-icono name="alert" class="h-3.5 w-3.5 shrink-0" />
                                    No aparece en Zernio ahora mismo: puede que se haya borrado allá directamente.
                                </p>
                            @else
                                <p class="mt-2 flex flex-wrap items-center gap-x-2 gap-y-1 rounded-lg bg-slate-50 px-2.5 py-1.5 text-xs text-slate-500">
                                    <span>En Zernio ahora:</span>
                                    <span class="font-semibold {{ $real['isActive'] ? 'text-emerald-700' : 'text-rose-700' }}">
                                        {{ $real['isActive'] ? 'Activa' : 'Apagada' }}
                                    </span>
                                    <span>{{ $real['postId'] ? 'en una publicación concreta' : 'en cualquier publicación' }}</span>
                                    <span>· {{ $real['stats']['triggered'] }} {{ $real['stats']['triggered'] === 1 ? 'disparo' : 'disparos' }}</span>
                                </p>

                                @unless ($coincide)
                                    <p class="mt-1.5 flex items-center gap-1.5 rounded-lg bg-amber-50 px-2.5 py-1.5 text-xs font-semibold text-amber-700">
                                        <x-icono name="alert" class="h-3.5 w-3.5 shrink-0" />
                                        No coincide: aquí dice «{{ $rule->status->label() }}» y Zernio dice {{ $real['isActive'] ? 'activa' : 'apagada' }}.
                                    </p>
                                @endunless
                            @endif

                            @if (($cuentaReal['necesita_reconectar'] ?? false) === true)
                                <p class="mt-1.5 flex items-center gap-1.5 rounded-lg bg-rose-50 px-2.5 py-1.5 text-xs font-semibold text-rose-700">
                                    <x-icono name="alert" class="h-3.5 w-3.5 shrink-0" />
                                    La cuenta de esta regla necesita reconectarse: no va a contestar hasta que la reconectes.
                                </p>
                            @endif
                        @endif
                    </div>

                    @can('social_commerce.manage')
                        <div class="flex flex-col items-stretch gap-2 sm:items-end">
                            <span class="bmos-badge {{ $rule->status->value === 'active' ? 'badge-green' : ($rule->status->value === 'error' ? 'badge-red' : 'badge-gray') }}">
                                {{ $rule->status->label() }}
                            </span>
                            <div class="flex items-center gap-0.5">
                                <a href="{{ route('panel.social-commerce.edit', $rule) }}" title="Editar"
                                   class="rounded-lg p-1.5 text-slate-500 hover:bg-indigo-50 hover:text-indigo-600">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" style="width:1.05rem;height:1.05rem"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125"/></svg>
                                </a>

                                <form method="POST" action="{{ route('panel.social-commerce.toggle', $rule) }}">
                                    @csrf
                                    <input type="hidden" name="is_active" value="{{ $rule->status->value === 'active' ? '0' : '1' }}">
                                    <button type="submit" title="{{ $rule->status->value === 'active' ? 'Pausar' : 'Encender' }}"
                                            class="rounded-lg p-1.5 text-slate-500 hover:bg-indigo-50 hover:text-indigo-600">
                                        @if ($rule->status->value === 'active')
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" style="width:1.05rem;height:1.05rem"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 5.25v13.5m-7.5-13.5v13.5"/></svg>
                                        @else
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" style="width:1.05rem;height:1.05rem"><path stroke-linecap="round" stroke-linejoin="round" d="M5.25 5.653c0-1.426 1.529-2.33 2.779-1.643l11.54 6.347c1.295.712 1.295 2.573 0 3.286L8.029 19.99c-1.25.687-2.779-.217-2.779-1.643V5.653Z"/></svg>
                                        @endif
                                    </button>
                                </form>

                                {{-- Borrado real (no lógico): esta regla deja de contestar en Instagram y
                                     se va de Zernio, así que sí merece el componente de confirmación en
                                     vez de la ventana nativa del navegador. --}}
                                <x-panel.confirm-action
                                    :action="route('panel.social-commerce.destroy', $rule)"
                                    title="¿Borrar «{{ $rule->name }}»?"
                                    message="Deja de contestar en Instagram y se quita de Zernio."
                                    irreversible
                                    tooltip="Borrar"
                                    class="rounded-lg p-1.5 text-slate-500 hover:bg-rose-50 hover:text-rose-600">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" style="width:1.05rem;height:1.05rem"><path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0"/></svg>
                                </x-panel.confirm-action>
                            </div>
                        </div>
                    @endcan
                </div>
            </div>
        @empty
            <div class="bmos-card bmos-card-pad text-center">
                <span class="sc-regla-icono mx-auto">
                    <x-icono name="chat" class="h-5 w-5" />
                </span>
                <p class="mt-3 text-slate-600">Todavía no tienes ninguna regla.</p>
                <p class="mt-1 text-sm text-slate-400">
                    Elige un producto, sus palabras clave y qué contesta, y Instagram empieza a responder solo.
                </p>
            </div>
        @endforelse
    </div>
</x-layouts.admin>
