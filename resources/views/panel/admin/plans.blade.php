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
            <div>
                <x-panel.field name="polar_product_id" label="Producto en Polar (opcional)"
                               placeholder="a1f622d8-48d1-4de2-aa25-84e49102045b" />
                <p class="mt-1 text-xs text-slate-400">
                    Id del producto en Polar. Sin él, el plan no se puede contratar en línea y se sigue asignando a mano.
                </p>
            </div>
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

    @php $masContratado = $plans->max('subscriptions_count'); @endphp

    <div class="grid grid-cols-1 gap-5 md:grid-cols-2 xl:grid-cols-3">
        @forelse ($plans as $plan)
            @php
                $form = 'plan_edit_'.$plan->id;
                $reopened = old('_form') === $form;
                $editModules = $reopened ? (array) old('modules') : $plan->moduleKeys();
                $editCycle = $reopened ? old('billing_cycle') : $plan->billing_cycle->value;
                $editActive = $reopened ? (bool) old('is_active') : $plan->is_active;

                // El acento sube con el escalón del plan (van ordenados por precio), así la lista se
                // lee de un vistazo como una escalera y no como tres tarjetas iguales.
                $nivel = min($loop->index + 1, 3);

                // Se destaca el plan con más empresas suscritas, y solo si alguna lo tiene: es un
                // dato real, no una etiqueta de marketing puesta a dedo.
                $destacado = $plan->subscriptions_count > 0 && $plan->subscriptions_count === $masContratado;
            @endphp
            <div class="bmos-plan {{ $plan->is_active ? '' : 'bmos-plan--inactivo' }} bmos-plan--nivel{{ $nivel }}">
                <span class="bmos-plan-acento"></span>

                {{-- Cabecera --}}
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="text-xl font-bold tracking-tight text-slate-800">{{ $plan->name }}</p>
                        <p class="mt-0.5 text-sm leading-snug text-slate-500">{{ $plan->description ?? 'Sin descripción' }}</p>
                    </div>
                    @if (! $plan->is_active)
                        <span class="bmos-badge badge-gray shrink-0">Inactivo</span>
                    @elseif ($destacado)
                        {{-- «Más contratado» sale del dato real, no de una etiqueta puesta a dedo. --}}
                        <span class="bmos-plan-estrella shrink-0">Más contratado</span>
                    @endif
                </div>

                {{-- Precio --}}
                <div class="mt-5 flex items-baseline gap-1.5">
                    <span class="text-sm font-semibold text-slate-400">RD$</span>
                    <span class="bmos-plan-precio">{{ number_format((float) $plan->price, 0) }}</span>
                    <span class="text-sm font-medium text-slate-400">/ {{ mb_strtolower($plan->billing_cycle->label()) }}</span>
                </div>
                @if ($plan->trial_days > 0)
                    <p class="mt-1 text-xs font-medium text-emerald-600">{{ $plan->trial_days }} días de prueba gratis</p>
                @endif

                {{-- Módulos incluidos. Se listan en vez de contarlos: saber CUÁLES es justo lo que
                     hoy obliga a abrir el modal de edición para averiguar. --}}
                <div class="mt-5 border-t border-slate-100 pt-4">
                    <p class="text-[11px] font-semibold uppercase tracking-wide text-slate-400">Incluye</p>
                    @if ($plan->modules === null)
                        <p class="mt-2 flex items-center gap-1.5 text-sm font-semibold text-indigo-600">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" class="h-4 w-4"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5"/></svg>
                            Todos los módulos, presentes y futuros
                        </p>
                    @else
                        <div class="mt-2 flex flex-wrap gap-1.5">
                            @foreach (array_slice($plan->moduleKeys(), 0, 6) as $clave)
                                <span class="bmos-plan-modulo">{{ $modules[$clave] ?? $clave }}</span>
                            @endforeach
                            @if (count($plan->moduleKeys()) > 6)
                                <span class="bmos-plan-modulo bmos-plan-modulo--resto">+{{ count($plan->moduleKeys()) - 6 }} más</span>
                            @endif
                        </div>
                    @endif
                </div>

                {{-- Estado comercial. Un plan sin enlazar con la pasarela no tiene botón de pago:
                     conviene verlo desde la lista y no descubrirlo cuando un cliente no puede pagar. --}}
                <div class="mt-4 space-y-1.5 text-sm">
                    <p class="flex items-center gap-1.5 {{ $plan->isPurchasable() ? 'text-slate-600' : 'text-amber-600' }}">
                        @if ($plan->isPurchasable())
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-4 w-4 text-emerald-500"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5"/></svg>
                            Se puede contratar en línea
                        @else
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-4 w-4"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z"/></svg>
                            Solo asignación manual
                        @endif
                    </p>
                    <p class="flex items-center gap-1.5 text-slate-500">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-4 w-4 text-slate-300"><path stroke-linecap="round" stroke-linejoin="round" d="M18 18.72a9.094 9.094 0 0 0 3.741-.479 3 3 0 0 0-4.682-2.72m.94 3.198.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0 1 12 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 0 1 6 18.719m12 0a5.971 5.971 0 0 0-.941-3.197m0 0A5.995 5.995 0 0 0 12 12.75a5.995 5.995 0 0 0-5.058 2.772m0 0a3 3 0 0 0-4.681 2.72 8.986 8.986 0 0 0 3.74.477m.94-3.197a5.971 5.971 0 0 0-.94 3.197M15 6.75a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/></svg>
                        {{ $plan->subscriptions_count }} {{ $plan->subscriptions_count === 1 ? 'empresa suscrita' : 'empresas suscritas' }}
                    </p>
                </div>

                <div class="mt-5 flex items-center justify-end gap-2 border-t border-slate-100 pt-4">
                    <x-panel.edit-modal title="Editar plan" trigger="Editar" :form="$form"
                                        :action="route('platform.plans.update', $plan)">
                        <div class="grid grid-cols-2 gap-3">
                            <x-panel.field name="name" label="Nombre" required :value="$plan->name" />
                            <x-panel.field name="slug" label="Identificador" required :value="$plan->slug" />
                        </div>
                        <x-panel.field name="description" label="Descripción (opcional)" :value="$plan->description" />
                        <div>
                            <x-panel.field name="polar_product_id" label="Producto en Polar (opcional)"
                                           :value="$plan->polar_product_id"
                                           placeholder="a1f622d8-48d1-4de2-aa25-84e49102045b" />
                            <p class="mt-1 text-xs text-slate-400">
                                Id del producto en Polar. Sin él, el plan no se puede contratar en línea.
                            </p>
                        </div>
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
