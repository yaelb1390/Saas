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
            <div class="bmos-card bmos-card-pad" x-data="{ open: false }">
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
