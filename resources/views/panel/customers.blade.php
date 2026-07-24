@php
    $oppBadge = fn ($status) => match ($status->value) {
        'won' => 'badge-green', 'lost' => 'badge-red', default => 'badge-blue',
    };
@endphp
<x-layouts.admin title="CRM" heading="CRM" subheading="Clientes y oportunidades del embudo de ventas">
    <div x-data="customersCrud()" class="grid grid-cols-1 gap-5 xl:grid-cols-3">
        <div class="xl:col-span-2">
            <div class="bmos-card overflow-hidden">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 p-4">
                    <p class="font-semibold text-slate-800">Clientes</p>
                    <div class="flex flex-wrap items-center gap-3">
                        <x-panel.search-bar placeholder="Buscar cliente..." />
                        <x-panel.export-button route="panel.export.customers" />
                    @can('customers.manage')
                    <x-panel.create-modal title="Nuevo cliente" label="Nuevo cliente" form="customer_create" :action="route('panel.customers.store')">
                        <x-panel.field name="name" label="Nombre" required placeholder="Nombre del cliente" />
                        <div class="grid grid-cols-2 gap-3">
                            <x-panel.field name="cedula" label="Cédula" placeholder="001-0000000-0" />
                            <x-panel.field name="phone" label="Teléfono" placeholder="18095550000" />
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <x-panel.field name="tax_id" label="RNC (opcional)" />
                            <x-panel.field name="email" label="Correo" type="email" placeholder="cliente@correo.com" />
                        </div>
                        <x-panel.field name="address" label="Dirección" />
                    </x-panel.create-modal>
                    @endcan
                    </div>
                </div>
                <div class="overflow-x-auto">
                    <table class="bmos-table">
                        <thead><tr><th>Cliente</th><th>Teléfono</th><th>Correo</th><th>Oport.</th><th class="text-right">Acciones</th></tr></thead>
                        <tbody>
                            @forelse ($customers as $customer)
                                <tr>
                                    <td class="font-medium">
                                        <a href="{{ route('panel.customers.show', $customer) }}" class="text-indigo-600 hover:underline">{{ $customer->name }}</a>
                                        @if ($customer->cedula)<span class="block text-xs font-normal text-slate-400">Cédula: {{ $customer->cedula }}</span>@endif
                                    </td>
                                    <td>{{ $customer->phone ?? '—' }}</td>
                                    <td class="text-slate-500">{{ $customer->email ?? '—' }}</td>
                                    <td><span class="bmos-badge badge-violet">{{ $customer->opportunities_count }}</span></td>
                                    <td>
                                        <div class="flex items-center justify-end gap-1">
                                            @can('customers.manage')
                                            {{-- Enviar al cliente el enlace de su portal. Solo si la empresa
                                                 tiene WhatsApp (el canal de entrega) y el cliente, teléfono.
                                                 La ruta lo verifica igual: esto solo evita ofrecer un botón
                                                 que fallaría. --}}
                                            @if ($portalEnabled && $customer->phone)
                                            <form method="POST" action="{{ route('panel.customers.portal', $customer) }}"
                                                  onsubmit="return confirm('¿Enviar a «{{ $customer->name }}» el enlace de su portal por WhatsApp?')">
                                                @csrf
                                                <button type="submit" class="rounded-lg p-1.5 text-slate-500 hover:bg-emerald-50 hover:text-emerald-600" title="Enviar enlace del portal por WhatsApp">
                                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" style="width:1.15rem;height:1.15rem"><path stroke-linecap="round" stroke-linejoin="round" d="M13.19 8.688a4.5 4.5 0 0 1 1.242 7.244l-4.5 4.5a4.5 4.5 0 0 1-6.364-6.364l1.757-1.757m13.35-.622 1.757-1.757a4.5 4.5 0 0 0-6.364-6.364l-4.5 4.5a4.5 4.5 0 0 0 1.242 7.244"/></svg>
                                                </button>
                                            </form>
                                            @endif
                                            <button type="button" class="rounded-lg p-1.5 text-slate-500 hover:bg-slate-100 hover:text-indigo-600" title="Editar"
                                                    @click="edit({ id: {{ $customer->id }}, name: @js($customer->name), cedula: @js($customer->cedula), phone: @js($customer->phone), tax_id: @js($customer->tax_id), email: @js($customer->email), address: @js($customer->address) })">
                                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" style="width:1.15rem;height:1.15rem"><path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Z"/></svg>
                                            </button>
                                            <form method="POST" action="{{ route('panel.customers.destroy', $customer) }}" onsubmit="return confirm('¿Eliminar «{{ $customer->name }}»?')">
                                                @csrf @method('DELETE')
                                                <button type="submit" class="rounded-lg p-1.5 text-slate-500 hover:bg-rose-50 hover:text-rose-600" title="Eliminar">
                                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" style="width:1.15rem;height:1.15rem"><path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166M18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916"/></svg>
                                                </button>
                                            </form>
                                            @endcan
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="bmos-empty">Sin clientes.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="p-4">{{ $customers->links() }}</div>
            </div>
        </div>

        <div>
            <div class="bmos-card overflow-hidden">
                <div class="border-b border-slate-100 p-4"><p class="font-semibold text-slate-800">Oportunidades recientes</p></div>
                <div class="divide-y divide-slate-100">
                    @forelse ($opportunities as $opp)
                        <div class="p-4">
                            <div class="flex items-center justify-between">
                                <p class="font-medium text-slate-800">{{ $opp->title }}</p>
                                <span class="bmos-badge {{ $oppBadge($opp->status) }}">{{ $opp->status->label() }}</span>
                            </div>
                            <p class="mt-1 text-xs text-slate-400">
                                {{ $opp->customer?->name ?? 'Sin cliente' }} · {{ $opp->stage?->name }} ·
                                <span class="font-semibold text-slate-600">{{ number_format((float) $opp->amount, 2) }}</span>
                            </p>
                        </div>
                    @empty
                        <p class="bmos-empty">Sin oportunidades.</p>
                    @endforelse
                </div>
            </div>
        </div>

        {{-- Modal de edición --}}
        <div x-show="open" x-cloak class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-900/50 p-4 py-10" @keydown.escape.window="open=false">
            <div @click.outside="open=false" x-transition class="w-full max-w-lg rounded-2xl bg-white p-6 shadow-2xl">
                <div class="mb-4 flex items-center justify-between">
                    <h3 class="text-lg font-semibold text-slate-800">Editar cliente</h3>
                    <button type="button" @click="open=false" class="text-slate-400 hover:text-slate-600">✕</button>
                </div>
                @if (old('_form') === 'customer_edit' && $errors->any())
                    <div class="mb-4 rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700">
                        <ul class="list-disc pl-4">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
                    </div>
                @endif
                <form method="POST" :action="editUrl" class="space-y-3">
                    @csrf
                    <input type="hidden" name="_method" value="PUT">
                    <input type="hidden" name="_form" value="customer_edit">
                    <input type="hidden" name="id" x-model="row.id">
                    <div><label class="bmos-field-label">Nombre</label><input name="name" x-model="row.name" class="bmos-input" required></div>
                    <div class="grid grid-cols-2 gap-3">
                        <div><label class="bmos-field-label">Cédula</label><input name="cedula" x-model="row.cedula" class="bmos-input"></div>
                        <div><label class="bmos-field-label">Teléfono</label><input name="phone" x-model="row.phone" class="bmos-input"></div>
                    </div>
                    <div><label class="bmos-field-label">RNC (opcional)</label><input name="tax_id" x-model="row.tax_id" class="bmos-input"></div>
                    <div><label class="bmos-field-label">Correo</label><input name="email" type="email" x-model="row.email" class="bmos-input"></div>
                    <div><label class="bmos-field-label">Dirección</label><input name="address" x-model="row.address" class="bmos-input"></div>
                    <div class="flex justify-end gap-2 pt-3">
                        <button type="button" @click="open=false" class="bmos-btn bmos-btn-ghost">Cancelar</button>
                        <button type="submit" class="bmos-btn bmos-btn-primary">Guardar cambios</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        function customersCrud() {
            return {
                open: false,
                row: { id: '', name: '', cedula: '', phone: '', tax_id: '', email: '', address: '' },
                get editUrl() { return '{{ url('panel/crm') }}/' + this.row.id; },
                edit(data) { this.row = { ...data }; this.open = true; },
                init() {
                    @if (old('_form') === 'customer_edit')
                        this.row = {
                            id: '{{ old('id') }}', name: @js(old('name')), cedula: @js(old('cedula')), phone: @js(old('phone')),
                            tax_id: @js(old('tax_id')), email: @js(old('email')), address: @js(old('address')),
                        };
                        this.open = true;
                    @endif
                },
            };
        }
    </script>
</x-layouts.admin>
