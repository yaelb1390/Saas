<x-layouts.admin title="Empresas" heading="Empresas de la plataforma" subheading="Administra las empresas y qué módulos incluye su plan">
    <div class="mb-5 flex justify-end">
        <x-panel.create-modal title="Nueva empresa" label="Nueva empresa" form="company_create" :action="route('platform.companies.store')">
            <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Empresa</p>
            <x-panel.field name="name" label="Nombre comercial" required placeholder="Comercial La Nueva" />
            <div class="grid grid-cols-2 gap-3">
                <x-panel.field name="tax_id" label="RNC (opcional)" placeholder="131000001" />
                <x-panel.field name="email" label="Correo (opcional)" type="email" />
            </div>

            <p class="mt-4 text-xs font-semibold uppercase tracking-wide text-slate-400">Usuario propietario</p>
            <x-panel.field name="owner_name" label="Nombre" required placeholder="Nombre del dueño" />
            <x-panel.field name="owner_email" label="Correo (acceso)" type="email" required placeholder="dueno@empresa.com" />
            <div class="grid grid-cols-2 gap-3">
                <x-panel.field name="owner_password" label="Contraseña" type="password" required />
                <x-panel.field name="owner_password_confirmation" label="Repetir" type="password" required />
            </div>

            <p class="mt-4 text-xs font-semibold uppercase tracking-wide text-slate-400">Plan (módulos incluidos)</p>
            <p class="-mt-1 text-xs text-slate-400">Si no marcas ninguno, la empresa arranca con el plan completo.</p>
            <div class="grid grid-cols-2 gap-2 sm:grid-cols-3">
                @foreach ($modules as $key => $label)
                    <label class="flex cursor-pointer items-center gap-2 rounded-lg border border-slate-200 px-2.5 py-2 text-sm text-slate-700 has-[:checked]:border-indigo-400 has-[:checked]:bg-indigo-50">
                        <input type="checkbox" name="modules[]" value="{{ $key }}" @checked(in_array($key, (array) old('modules'), true)) class="rounded border-slate-300 text-indigo-600">
                        {{ $label }}
                    </label>
                @endforeach
            </div>
        </x-panel.create-modal>
    </div>

    <div class="space-y-5">
        @foreach ($companies as $company)
            @php $active = $company->activeModules(); @endphp
            <div class="bmos-card bmos-card-pad" x-data="{ open: false, borrar: false, nombreEscrito: '' }">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div class="flex items-center gap-3">
                        <span class="flex h-11 w-11 items-center justify-center rounded-xl bg-indigo-100 text-indigo-600">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" class="h-6 w-6"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 21h16.5M4.5 3h15M5.25 3v18m13.5-18v18M9 6.75h1.5m-1.5 3h1.5m-1.5 3h1.5m3-6H15m-1.5 3H15m-1.5 3H15M9 21v-3.375c0-.621.504-1.125 1.125-1.125h3.75c.621 0 1.125.504 1.125 1.125V21"/></svg>
                        </span>
                        <div>
                            <div class="flex items-center gap-2">
                                <p class="font-semibold text-slate-800">{{ $company->name }}</p>
                                <span class="bmos-badge {{ $company->is_active ? 'badge-green' : 'badge-red' }}">
                                    {{ $company->is_active ? 'Activa' : 'Suspendida' }}
                                </span>
                                @php $sub = $company->subscription; @endphp
                                @if ($sub)
                                    <span class="bmos-badge {{ $sub->status->badge() }}">{{ $sub->plan?->name ?? 'Plan' }} · {{ $sub->status->label() }}</span>
                                @else
                                    <span class="bmos-badge {{ $company->modules === null ? 'badge-violet' : 'badge-gray' }}">
                                        {{ $company->modules === null ? 'Plan completo (sin suscripción)' : count($active).' módulos (manual)' }}
                                    </span>
                                @endif
                            </div>
                            <p class="text-xs text-slate-400">
                                {{ $company->users_count }} {{ $company->users_count === 1 ? 'usuario' : 'usuarios' }}
                                @if ($company->tax_id) · RNC {{ $company->tax_id }} @endif
                                @if ($sub?->renewsAt()) · {{ $sub->status->value === 'trialing' ? 'prueba hasta' : 'renueva' }} {{ $sub->renewsAt()->format('d/m/Y') }} @endif
                            </p>
                        </div>
                    </div>

                    <div class="flex items-center gap-2">
                        <button type="button" @click="open = !open" class="bmos-btn bmos-btn-ghost" x-text="open ? 'Cerrar' : 'Editar plan'"></button>
                        <form method="POST" action="{{ route('platform.companies.toggle', $company) }}"
                              onsubmit="return confirm('{{ $company->is_active ? '¿Suspender' : '¿Reactivar' }} «{{ $company->name }}»?')">
                            @csrf
                            <button type="submit" class="bmos-btn {{ $company->is_active ? 'bmos-btn-ghost' : 'bmos-btn-primary' }}">
                                {{ $company->is_active ? 'Suspender' : 'Reactivar' }}
                            </button>
                        </form>
                        {{-- Eliminar va aparte y sin color de botón: se busca que no se pulse por
                             inercia al ir a «Suspender», que está justo al lado. --}}
                        <button type="button" @click="borrar = true"
                                class="rounded-lg px-3 py-2 text-sm font-semibold text-rose-600 transition hover:bg-rose-50">
                            Eliminar
                        </button>
                    </div>
                </div>

                {{-- Borrado definitivo. Dos confirmaciones porque protegen de cosas distintas: la
                     contraseña dice «eres tú» y el nombre dice «es esta». Un «¿estás seguro?» no
                     sirve para lo segundo: a eso se contesta que sí sin leerlo. --}}
                <div x-show="borrar" x-cloak class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-900/60 p-4 py-10"
                     @keydown.escape.window="borrar = false">
                    <div @click.outside="borrar = false" x-transition class="w-full max-w-md rounded-2xl bg-white p-6 shadow-2xl">
                        <div class="flex items-start gap-3">
                            <span class="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-rose-50 text-rose-600">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-5 w-5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z"/></svg>
                            </span>
                            <div>
                                <h3 class="text-lg font-semibold text-slate-800">Eliminar «{{ $company->name }}»</h3>
                                <p class="mt-1 text-sm text-slate-500">Esto no se puede deshacer.</p>
                            </div>
                        </div>

                        <div class="mt-4 rounded-xl border border-rose-200 bg-rose-50 p-3 text-sm text-rose-800">
                            <p class="font-semibold">Se borrará definitivamente:</p>
                            <ul class="mt-1 list-disc space-y-0.5 pl-4">
                                <li>Todas sus ventas, facturas, productos, clientes y préstamos</li>
                                <li>Sus {{ $company->users_count ?? '' }} usuarios y sus accesos</li>
                                <li>Sus sucursales, almacenes, cajas y su suscripción</li>
                            </ul>
                            <p class="mt-2">No hay copia de seguridad automática. No se puede recuperar.</p>
                        </div>

                        <form method="POST" action="{{ route('platform.companies.destroy', $company) }}" class="mt-4 space-y-3">
                            @csrf @method('DELETE')
                            <div>
                                <label class="bmos-field-label">Escribe el nombre de la empresa</label>
                                <input name="confirm_name" x-model="nombreEscrito" autocomplete="off"
                                       placeholder="{{ $company->name }}" class="bmos-input">
                                <p class="mt-1 text-xs text-slate-400">
                                    Para asegurar que es esta y no otra de nombre parecido.
                                </p>
                            </div>
                            <div>
                                <label class="bmos-field-label">Tu contraseña</label>
                                <input type="password" name="password" autocomplete="current-password" class="bmos-input">
                            </div>
                            <div class="flex justify-end gap-2 pt-1">
                                <button type="button" @click="borrar = false" class="bmos-btn bmos-btn-ghost">Cancelar</button>
                                {{-- Deshabilitado hasta que el nombre coincida. El servidor lo vuelve
                                     a comprobar: esto es una ayuda, no la defensa. --}}
                                <button type="submit"
                                        :disabled="nombreEscrito.trim().toLowerCase() !== @js(mb_strtolower($company->name))"
                                        class="rounded-xl bg-rose-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-rose-700 disabled:cursor-not-allowed disabled:bg-slate-300">
                                    Eliminar definitivamente
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                {{-- Suscripción + módulos. --}}
                <div x-show="open" x-cloak x-transition class="mt-5 space-y-5 border-t border-slate-100 pt-5">
                    {{-- Suscripción (cobro manual). --}}
                    <div>
                        <p class="mb-3 text-sm font-semibold text-slate-600">Suscripción</p>
                        <div class="flex flex-wrap items-end gap-2">
                            <form method="POST" action="{{ route('platform.companies.subscribe', $company) }}" class="flex flex-wrap items-end gap-2">
                                @csrf
                                <div>
                                    <label class="bmos-field-label">Plan</label>
                                    <select name="plan_id" class="bmos-input" required>
                                        @forelse ($plans as $plan)
                                            <option value="{{ $plan->id }}" @selected($sub?->plan_id === $plan->id)>
                                                {{ $plan->name }} — {{ number_format((float) $plan->price, 0) }}/{{ $plan->billing_cycle->label() }}
                                            </option>
                                        @empty
                                            <option value="" disabled>No hay planes. Crea uno primero.</option>
                                        @endforelse
                                    </select>
                                </div>
                                <label class="flex items-center gap-2 pb-2.5 text-sm text-slate-600" title="Inicia (o reinicia) el período de prueba del plan. Requiere que el plan tenga días de prueba.">
                                    <input type="checkbox" name="with_trial" value="1" class="rounded border-slate-300"> iniciar prueba
                                </label>
                                <button type="submit" class="bmos-btn bmos-btn-primary">{{ $sub ? 'Cambiar plan' : 'Suscribir' }}</button>
                            </form>

                            @if ($sub)
                                <form method="POST" action="{{ route('platform.companies.payment', $company) }}">
                                    @csrf
                                    <button type="submit" class="bmos-btn bmos-btn-ghost">Registrar pago</button>
                                </form>
                                @if ($sub->isUsable())
                                    <form method="POST" action="{{ route('platform.companies.suspend', $company) }}"
                                          onsubmit="return confirm('¿Suspender la suscripción de «{{ $company->name }}»?')">
                                        @csrf
                                        <button type="submit" class="bmos-btn bmos-btn-ghost">Suspender</button>
                                    </form>
                                @endif
                            @endif
                        </div>
                    </div>

                    {{-- Módulos: editables siempre. Con plan, la columna actúa como ajuste manual
                         (override) sobre los del plan; sin plan, define directamente el acceso. --}}
                    @php $override = $sub && $company->modules !== null; @endphp
                    <div class="border-t border-slate-100 pt-5">
                        <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                            <div class="flex items-center gap-2">
                                <p class="text-sm font-semibold text-slate-600">Módulos habilitados</p>
                                @if ($sub)
                                    <span class="bmos-badge {{ $override ? 'badge-amber' : 'badge-gray' }}">
                                        {{ $override ? 'Ajuste manual' : 'Según el plan' }}
                                    </span>
                                @endif
                            </div>
                            @if ($override)
                                <form method="POST" action="{{ route('platform.companies.modules', $company) }}">
                                    @csrf @method('PUT')
                                    <input type="hidden" name="follow_plan" value="1">
                                    <button type="submit" class="bmos-btn bmos-btn-ghost text-xs">Volver a los del plan</button>
                                </form>
                            @endif
                        </div>
                        @if ($sub)
                            <p class="-mt-1 mb-3 text-xs text-slate-400">
                                Por defecto la empresa hereda los módulos de su plan. Marca o desmarca para darle un acceso
                                distinto solo a esta empresa; usa «Volver a los del plan» para deshacer el ajuste.
                            </p>
                        @endif
                        <form method="POST" action="{{ route('platform.companies.modules', $company) }}">
                            @csrf @method('PUT')
                            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
                                @foreach ($modules as $key => $label)
                                    <label class="flex cursor-pointer items-center gap-2 rounded-xl border border-slate-200 px-3 py-2.5 text-sm text-slate-700 transition hover:border-indigo-300 hover:bg-indigo-50/40 has-[:checked]:border-indigo-400 has-[:checked]:bg-indigo-50">
                                        <input type="checkbox" name="modules[]" value="{{ $key }}"
                                               @checked(in_array($key, $active, true)) class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500">
                                        {{ $label }}
                                    </label>
                                @endforeach
                            </div>
                            <div class="mt-4 flex justify-end">
                                <button type="submit" class="bmos-btn bmos-btn-primary">Guardar módulos</button>
                            </div>
                        </form>
                    </div>

                    {{-- Tipo de negocio del POS: adapta el terminal de esta empresa. Lo define el
                         operador de la plataforma (no la empresa). --}}
                    @php $posCfg = \App\Modules\POS\Support\PosProfile::for($company); @endphp
                    <div class="border-t border-slate-100 pt-5"
                         x-data="posProfileForm(@js($posCfg['profile']), @js($posCfg['options']), @js($posPresets))">
                        <div class="mb-3 flex items-center gap-2">
                            <p class="text-sm font-semibold text-slate-600">Tipo de negocio (POS)</p>
                            <span class="bmos-badge badge-violet" x-text="typeLabel"></span>
                        </div>
                        <p class="-mt-1 mb-3 text-xs text-slate-400">Al elegir un tipo se activan sus opciones recomendadas; luego puedes afinarlas.</p>
                        <form method="POST" action="{{ route('platform.companies.pos', $company) }}">
                            @csrf @method('PUT')
                            <input type="hidden" name="profile" :value="profile">
                            <div class="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-5">
                                @foreach ($posTypes as $key => $meta)
                                    <button type="button" @click="choose('{{ $key }}')"
                                            class="rounded-lg border p-2 text-left transition"
                                            :class="profile === '{{ $key }}' ? 'border-indigo-400 bg-indigo-50 ring-1 ring-indigo-300' : 'border-slate-200 hover:border-indigo-300'">
                                        <span class="block text-sm font-medium text-slate-800">{{ $meta['label'] }}</span>
                                        <span class="block text-xs text-slate-400">{{ $meta['hint'] }}</span>
                                    </button>
                                @endforeach
                            </div>
                            <div class="mt-3 grid grid-cols-2 gap-x-4 gap-y-1.5 sm:grid-cols-3 lg:grid-cols-4">
                                @foreach ($posOptionLabels as $okey => $olabel)
                                    <label class="flex items-center gap-2 text-sm text-slate-600">
                                        <input type="checkbox" name="options[{{ $okey }}]" value="1" x-model="options['{{ $okey }}']" class="rounded border-slate-300 text-indigo-600">
                                        {{ $olabel }}
                                    </label>
                                @endforeach
                            </div>
                            <div class="mt-4 flex justify-end">
                                <button type="submit" class="bmos-btn bmos-btn-primary">Guardar tipo de negocio</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    <script>
        function posProfileForm(profile, options, presets) {
            const labels = @js(collect($posTypes)->map(fn ($m) => $m['label']));
            return {
                profile, options, presets,
                get typeLabel() { return labels[this.profile] || this.profile; },
                choose(type) {
                    this.profile = type;
                    if (this.presets[type]) this.options = { ...this.presets[type] };
                },
            };
        }
    </script>
</x-layouts.admin>
