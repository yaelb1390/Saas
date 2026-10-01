{{--
    Reglas de Social Commerce: palabra clave → producto → precio → plantilla.

    Zernio ejecuta la automatización (igual que en Redes sociales); esta pantalla guarda el
    producto, el precio y las plantillas, y las traduce a lo que la API de Zernio entiende cada
    vez que se guardan (ver RuleSyncService).

    Estilo de ajustes, no de panel de control: secciones tituladas con una tarjeta cada una (igual
    que «Mi empresa») y cada regla es una FILA dentro de la sección «Reglas», no una tarjeta suelta
    con su propio color. El color se reserva para lo que de verdad es un estado —activa, con error,
    un aviso de Zernio—, nunca para decorar un precio o un icono.
--}}
<x-layouts.admin title="Social Commerce" heading="Social Commerce"
                 subheading="Contesta el precio en tus comentarios de Instagram, con tus propios productos">
    <div class="mx-auto max-w-4xl">
        <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
            <nav class="flex flex-wrap gap-4 text-sm">
                <a href="{{ route('panel.social-commerce.dashboard') }}" class="text-indigo-600 hover:underline">Dashboard</a>
                <a href="{{ route('panel.social-commerce.settings') }}" class="text-indigo-600 hover:underline">Ajustes de conexión y WhatsApp</a>
                <a href="{{ route('panel.social-commerce.conversations.index') }}" class="text-indigo-600 hover:underline">Conversaciones</a>
                @can('social_commerce.manage')
                    <a href="{{ route('panel.social-commerce.sandbox') }}" class="text-indigo-600 hover:underline">Probar una regla</a>
                @endcan
            </nav>
            @can('social_commerce.manage')
                <a href="{{ route('panel.social-commerce.create') }}" class="bmos-btn bmos-btn-primary">
                    + Nueva regla
                </a>
            @endcan
        </div>

        @if ($cuentas === [] || $diagnostico['aviso'])
            <div class="bmos-card bmos-card-pad mb-4">
                <p class="font-semibold text-slate-800">Conexión</p>
                <p class="mt-1 text-xs text-slate-500">Lo que hace falta para que las reglas contesten de verdad.</p>

                @if ($cuentas === [])
                    <div class="mt-3 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800">
                        Primero conecta tu cuenta de Instagram o Facebook: las reglas necesitan una cuenta conectada.
                        <a href="{{ route('panel.social-commerce.settings') }}" class="font-semibold underline">Ir a Ajustes</a>.
                    </div>
                @endif

                @if ($diagnostico['aviso'])
                    <div class="mt-3 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800">
                        {{ $diagnostico['aviso'] }}
                    </div>
                @endif
            </div>
        @endif

        <div class="bmos-card bmos-card-pad">
            <p class="font-semibold text-slate-800">Reglas</p>
            <p class="mt-1 text-xs text-slate-500">Cada una escucha unas palabras clave y contesta por su cuenta.</p>

            @forelse ($rules as $rule)
                <div class="mt-3 rounded-lg border border-slate-200 p-3 transition hover:bg-slate-50 {{ $rule->status->value === 'active' ? '' : 'opacity-60' }}">
                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-[minmax(0,1fr)_auto]">
                        <div class="min-w-0">
                            <p class="text-sm font-medium text-slate-800">{{ $rule->name }}</p>
                            <p class="mt-0.5 text-sm text-slate-600">
                                @if (! $rule->esManual() && ! $rule->product)
                                    {{-- Una regla sin producto ya no tiene qué vender: es un estado
                                         roto, no un dato más al lado del nombre. --}}
                                    <span class="font-semibold text-rose-600">Producto borrado</span>
                                @else
                                    {{ $rule->esManual() ? $rule->manual_name : $rule->product->name }}
                                    · <span class="font-semibold text-slate-700">{{ $rule->company->currency }} {{ number_format($rule->precioDelArticulo(), 2) }}</span>
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
                                    · <span class="font-medium text-rose-600">{{ $rule->sync_error }}</span>
                                @elseif ($rule->last_synced_at)
                                    · sincronizada {{ $rule->last_synced_at->diffForHumans() }}
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
                                    <p class="mt-2 rounded-lg bg-rose-50 px-2.5 py-1.5 text-xs font-semibold text-rose-700">
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
                                        <p class="mt-1.5 rounded-lg bg-amber-50 px-2.5 py-1.5 text-xs font-semibold text-amber-700">
                                            No coincide: aquí dice «{{ $rule->status->label() }}» y Zernio dice {{ $real['isActive'] ? 'activa' : 'apagada' }}.
                                        </p>
                                    @endunless
                                @endif

                                @if (($cuentaReal['necesita_reconectar'] ?? false) === true)
                                    <p class="mt-1.5 rounded-lg bg-rose-50 px-2.5 py-1.5 text-xs font-semibold text-rose-700">
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
                <p class="mt-4 text-center text-sm text-slate-500">
                    Todavía no tienes ninguna regla. Elige un producto, sus palabras clave y qué
                    contesta, y Instagram empieza a responder solo.
                </p>
            @endforelse
        </div>
    </div>
</x-layouts.admin>
