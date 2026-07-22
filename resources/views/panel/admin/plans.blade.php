@php
    use App\Modules\Core\Enums\BillingCycle;
@endphp
<x-layouts.admin title="Planes" heading="Planes de suscripción" subheading="Define los planes que ofreces: precio, ciclo y módulos incluidos">
    <div class="mb-5 flex justify-end">
        <x-panel.create-modal title="Nuevo plan" label="Nuevo plan" form="plan_create" :action="route('platform.plans.store')">
            <div class="grid grid-cols-2 gap-3">
                <x-panel.field name="name" label="Nombre" required placeholder="Pro" />
                <x-panel.field name="slug" label="Identificador" required placeholder="pro" />
            </div>
            <x-panel.field name="description" label="Descripción (opcional)" placeholder="Para negocios en crecimiento" />
            <div class="grid grid-cols-3 gap-3">
                <x-panel.field name="price" label="Precio" type="number" step="0.01" value="0" required />
                <div>
                    <label class="bmos-field-label">Ciclo</label>
                    <select name="billing_cycle" class="bmos-input" required>
                        @foreach (BillingCycle::cases() as $cycle)
                            <option value="{{ $cycle->value }}">{{ $cycle->label() }}</option>
                        @endforeach
                    </select>
                </div>
                <x-panel.field name="trial_days" label="Días de prueba" type="number" value="0" required />
            </div>
            <div class="grid grid-cols-2 gap-3">
                <x-panel.field name="max_users" label="Máx. usuarios (opcional)" type="number" />
                <x-panel.field name="max_branches" label="Máx. sucursales (opcional)" type="number" />
            </div>
            <div>
                <label class="bmos-field-label">Módulos incluidos</label>
                <p class="-mt-1 mb-2 text-xs text-slate-400">Si los marcas todos, el plan incluye cualquier módulo futuro.</p>
                <div class="grid grid-cols-2 gap-2 sm:grid-cols-3">
                    @foreach ($modules as $key => $label)
                        <label class="flex cursor-pointer items-center gap-2 rounded-lg border border-slate-200 px-2.5 py-2 text-sm has-[:checked]:border-indigo-400 has-[:checked]:bg-indigo-50">
                            <input type="checkbox" name="modules[]" value="{{ $key }}" @checked(in_array($key, (array) old('modules'), true)) class="rounded border-slate-300 text-indigo-600">
                            {{ $label }}
                        </label>
                    @endforeach
                </div>
            </div>
            <label class="flex items-center gap-2 text-sm text-slate-600">
                <input type="checkbox" name="is_active" value="1" checked class="rounded border-slate-300"> Plan disponible para asignar
            </label>
        </x-panel.create-modal>
    </div>

    <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
        @forelse ($plans as $plan)
            @php
                $form = 'plan_edit_'.$plan->id;
                $reopened = old('_form') === $form;
                $editModules = $reopened ? (array) old('modules') : $plan->moduleKeys();
                $editCycle = $reopened ? old('billing_cycle') : $plan->billing_cycle->value;
                $editActive = $reopened ? (bool) old('is_active') : $plan->is_active;
            @endphp
            <div class="bmos-card bmos-card-pad">
                <div class="flex items-start justify-between gap-2">
                    <div>
                        <p class="text-lg font-semibold text-slate-800">{{ $plan->name }}</p>
                        <p class="text-sm text-slate-400">{{ $plan->description ?? '—' }}</p>
                    </div>
                    <span class="bmos-badge {{ $plan->is_active ? 'badge-green' : 'badge-gray' }}">{{ $plan->is_active ? 'Activo' : 'Inactivo' }}</span>
                </div>
                <p class="mt-3 text-2xl font-bold text-slate-800">
                    {{ number_format((float) $plan->price, 2) }}
                    <span class="text-sm font-normal text-slate-400">/ {{ $plan->billing_cycle->label() }}</span>
                </p>
                <div class="mt-2 flex flex-wrap gap-1.5 text-xs text-slate-500">
                    <span class="bmos-badge badge-blue">{{ count($plan->moduleKeys()) }} módulos</span>
                    @if ($plan->trial_days > 0)<span class="bmos-badge badge-gray">{{ $plan->trial_days }} días de prueba</span>@endif
                    <span class="bmos-badge badge-gray">{{ $plan->subscriptions_count }} suscripciones</span>
                </div>

                <div class="mt-4 flex items-center justify-end gap-2 border-t border-slate-100 pt-4">
                    <x-panel.edit-modal title="Editar plan" trigger="Editar" :form="$form"
                                        :action="route('platform.plans.update', $plan)">
                        <div class="grid grid-cols-2 gap-3">
                            <x-panel.field name="name" label="Nombre" required :value="$plan->name" />
                            <x-panel.field name="slug" label="Identificador" required :value="$plan->slug" />
                        </div>
                        <x-panel.field name="description" label="Descripción (opcional)" :value="$plan->description" />
                        <div class="grid grid-cols-3 gap-3">
                            <x-panel.field name="price" label="Precio" type="number" step="0.01" required :value="$plan->price" />
                            <div>
                                <label class="bmos-field-label">Ciclo</label>
                                <select name="billing_cycle" class="bmos-input" required>
                                    @foreach (BillingCycle::cases() as $cycle)
                                        <option value="{{ $cycle->value }}" @selected($editCycle === $cycle->value)>{{ $cycle->label() }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <x-panel.field name="trial_days" label="Días de prueba" type="number" required :value="$plan->trial_days" />
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <x-panel.field name="max_users" label="Máx. usuarios (opcional)" type="number" :value="$plan->max_users" />
                            <x-panel.field name="max_branches" label="Máx. sucursales (opcional)" type="number" :value="$plan->max_branches" />
                        </div>
                        <div>
                            <label class="bmos-field-label">Módulos incluidos</label>
                            <p class="-mt-1 mb-2 text-xs text-slate-400">Si los marcas todos, el plan incluye cualquier módulo futuro.</p>
                            <div class="grid grid-cols-2 gap-2 sm:grid-cols-3">
                                @foreach ($modules as $key => $label)
                                    <label class="flex cursor-pointer items-center gap-2 rounded-lg border border-slate-200 px-2.5 py-2 text-sm has-[:checked]:border-indigo-400 has-[:checked]:bg-indigo-50">
                                        <input type="checkbox" name="modules[]" value="{{ $key }}" @checked(in_array($key, $editModules, true)) class="rounded border-slate-300 text-indigo-600">
                                        {{ $label }}
                                    </label>
                                @endforeach
                            </div>
                        </div>
                        <label class="flex items-center gap-2 text-sm text-slate-600">
                            <input type="checkbox" name="is_active" value="1" @checked($editActive) class="rounded border-slate-300"> Plan disponible para asignar
                        </label>
                    </x-panel.edit-modal>

                    <form method="POST" action="{{ route('platform.plans.destroy', $plan) }}"
                          onsubmit="return confirm('¿Eliminar el plan «{{ $plan->name }}»?')">
                        @csrf @method('DELETE')
                        <button type="submit" class="bmos-btn bmos-btn-ghost text-rose-600">Eliminar</button>
                    </form>
                </div>
            </div>
        @empty
            <div class="bmos-card bmos-card-pad md:col-span-2 xl:col-span-3">
                <p class="bmos-empty">Aún no hay planes. Crea el primero para poder suscribir empresas.</p>
            </div>
        @endforelse
    </div>
</x-layouts.admin>
