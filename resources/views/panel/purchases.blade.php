@php
    $poBadge = fn ($status) => match ($status->value) {
        'received' => 'badge-green', 'ordered' => 'badge-blue', 'cancelled' => 'badge-red', default => 'badge-gray',
    };
@endphp
<x-layouts.admin title="Compras" heading="Compras" subheading="Órdenes de compra y proveedores">
    {{-- El acuse de éxito/error lo muestra el toast global del layout. --}}
    <div x-data="suppliersCrud()" class="grid grid-cols-1 gap-5 xl:grid-cols-3">
        <div class="xl:col-span-2">
            <div class="bmos-card overflow-hidden">
                <div class="flex items-center justify-between border-b border-slate-100 p-4">
                    <p class="font-semibold text-slate-800">Órdenes de compra</p>
                    {{-- La pantalla se ve con «purchases.view» (la tiene hasta el cajero), pero crear
                         una orden compromete dinero con un proveedor: exige «purchases.manage». --}}
                    @can('purchases.manage')
                        <button type="button" @click="$dispatch('open-order-modal')" class="bmos-btn bmos-btn-primary">
                            Nueva orden
                        </button>
                    @endcan
                </div>
                <div class="overflow-x-auto">
                    <table class="bmos-table">
                        <thead><tr><th>Código</th><th>Proveedor</th><th>Líneas</th><th>Total</th><th>Estado</th><th>Fecha</th><th class="text-right">Acciones</th></tr></thead>
                        <tbody>
                            @forelse ($orders as $order)
                                <tr>
                                    <td class="font-mono text-xs font-semibold text-indigo-600">{{ $order->code }}</td>
                                    <td>{{ $order->supplier?->name ?? '—' }}</td>
                                    <td>{{ $order->items_count }}</td>
                                    <td class="font-semibold">{{ number_format((float) $order->total, 2) }}</td>
                                    <td><span class="bmos-badge {{ $poBadge($order->status) }}">{{ $order->status->label() }}</span></td>
                                    <td class="text-slate-400">{{ $order->created_at?->format('d/m/Y') }}</td>
                                    <td class="text-right">
                                        {{-- Recibir suma existencia al almacén: solo se ofrece si la orden
                                             está en un estado que lo admite. Recibir dos veces lo impide
                                             el propio servicio, no este botón. --}}
                                        @can('purchases.receive')
                                            @if ($order->status->canBeReceived())
                                                <form method="POST" action="{{ route('panel.purchase-orders.receive', $order) }}"
                                                      onsubmit="return confirm('¿Dar por recibida la orden {{ $order->code }}? Sumará la existencia al almacén.')">
                                                    @csrf
                                                    <button type="submit" class="bmos-btn bmos-btn-ghost text-xs">Recibir</button>
                                                </form>
                                            @endif
                                        @endcan
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="7" class="bmos-empty">Sin órdenes de compra.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="p-4">{{ $orders->links() }}</div>
            </div>
        </div>
        <div>
            <div class="bmos-card overflow-hidden">
                <div class="flex items-center justify-between border-b border-slate-100 p-4">
                    <p class="font-semibold text-slate-800">Proveedores</p>
                    @can('suppliers.manage')
                    <x-panel.create-modal title="Nuevo proveedor" label="Nuevo" form="supplier_create" :action="route('panel.suppliers.store')">
                        <x-panel.field name="name" label="Nombre" required placeholder="Nombre del proveedor" />
                        <div class="grid grid-cols-2 gap-3">
                            <x-panel.field name="phone" label="Teléfono" />
                            <x-panel.field name="tax_id" label="RNC / Cédula" />
                        </div>
                        <x-panel.field name="email" label="Correo" type="email" />
                        <x-panel.field name="address" label="Dirección" />
                    </x-panel.create-modal>
                    @endcan
                </div>
                <div class="divide-y divide-slate-100">
                    @forelse ($suppliers as $supplier)
                        <div class="flex items-center justify-between p-4">
                            <div>
                                <p class="font-medium text-slate-800">{{ $supplier->name }}</p>
                                <p class="text-xs text-slate-400">{{ $supplier->email ?? $supplier->tax_id ?? '—' }}</p>
                            </div>
                            <div class="flex items-center gap-2">
                                <span class="bmos-badge {{ $supplier->is_active ? 'badge-green' : 'badge-gray' }}">
                                    {{ $supplier->is_active ? 'Activo' : 'Inactivo' }}
                                </span>
                                @can('suppliers.manage')
                                <button type="button" class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-indigo-600" title="Editar"
                                        @click="edit({ id: {{ $supplier->id }}, name: @js($supplier->name), tax_id: @js($supplier->tax_id), email: @js($supplier->email), phone: @js($supplier->phone), address: @js($supplier->address) })">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" style="width:1.1rem;height:1.1rem"><path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Z"/></svg>
                                </button>
                                <form method="POST" action="{{ route('panel.suppliers.destroy', $supplier) }}" onsubmit="return confirm('¿Eliminar «{{ $supplier->name }}»?')">
                                    @csrf @method('DELETE')
                                    <button type="submit" class="rounded-lg p-1.5 text-slate-400 hover:bg-rose-50 hover:text-rose-600" title="Eliminar">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" style="width:1.1rem;height:1.1rem"><path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166M18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916"/></svg>
                                    </button>
                                </form>
                                @endcan
                            </div>
                        </div>
                    @empty
                        <p class="bmos-empty">Sin proveedores.</p>
                    @endforelse
                </div>
            </div>
        </div>

        {{-- Modal de edición de proveedor --}}
        <div x-show="open" x-cloak class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-900/50 p-4 py-10" @keydown.escape.window="open=false">
            <div @click.outside="open=false" x-transition class="w-full max-w-lg rounded-2xl bg-white p-6 shadow-2xl">
                <div class="mb-4 flex items-center justify-between">
                    <h3 class="text-lg font-semibold text-slate-800">Editar proveedor</h3>
                    <button type="button" @click="open=false" class="text-slate-400 hover:text-slate-600">✕</button>
                </div>
                @if (old('_form') === 'supplier_edit' && $errors->any())
                    <div class="mb-4 rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700">
                        <ul class="list-disc pl-4">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
                    </div>
                @endif
                <form method="POST" :action="editUrl" class="space-y-3">
                    @csrf
                    <input type="hidden" name="_method" value="PUT">
                    <input type="hidden" name="_form" value="supplier_edit">
                    <input type="hidden" name="id" x-model="row.id">
                    <div><label class="bmos-field-label">Nombre</label><input name="name" x-model="row.name" class="bmos-input" required></div>
                    <div class="grid grid-cols-2 gap-3">
                        <div><label class="bmos-field-label">Teléfono</label><input name="phone" x-model="row.phone" class="bmos-input"></div>
                        <div><label class="bmos-field-label">RNC / Cédula</label><input name="tax_id" x-model="row.tax_id" class="bmos-input"></div>
                    </div>
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

    {{-- Nueva orden de compra. Vive fuera del x-data de proveedores: son dos formularios sin nada
         en común y mezclarlos acoplaría su estado. --}}
    @can('purchases.manage')
        <div x-data="purchaseOrderForm(@js($products))" @open-order-modal.window="open = true">
            <div x-show="open" x-cloak class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-900/50 p-4 py-10"
                 @keydown.escape.window="open = false">
                <div @click.outside="open = false" x-transition class="w-full max-w-3xl rounded-2xl bg-white p-6 shadow-2xl">
                    <div class="mb-4 flex items-center justify-between">
                        <h3 class="text-lg font-semibold text-slate-800">Nueva orden de compra</h3>
                        <button type="button" @click="open = false" class="text-slate-400 hover:text-slate-600">✕</button>
                    </div>

                    @if ($errors->any() && old('_form') === 'purchase_order')
                        <div class="mb-4 rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700">
                            <ul class="list-disc pl-4">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
                        </div>
                    @endif

                    <form method="POST" action="{{ route('panel.purchase-orders.store') }}" class="space-y-4">
                        @csrf
                        <input type="hidden" name="_form" value="purchase_order">

                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="bmos-field-label">Proveedor</label>
                                <select name="supplier_id" class="bmos-input" required>
                                    <option value="">— Elige un proveedor —</option>
                                    @foreach ($suppliers as $supplier)
                                        <option value="{{ $supplier->id }}">{{ $supplier->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="bmos-field-label">Almacén de destino</label>
                                <select name="warehouse_id" class="bmos-input" required>
                                    @foreach ($warehouses as $warehouse)
                                        <option value="{{ $warehouse->id }}" @selected($warehouse->is_default)>{{ $warehouse->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>

                        <div>
                            <div class="mb-2 flex items-center justify-between">
                                <label class="bmos-field-label mb-0">Líneas</label>
                                <button type="button" @click="addLine()" class="bmos-btn bmos-btn-ghost text-xs">+ Añadir línea</button>
                            </div>

                            <template x-for="(line, i) in lines" :key="i">
                                <div class="mb-2 grid grid-cols-12 items-end gap-2">
                                    <div class="col-span-6">
                                        <select :name="'lines[' + i + '][product_id]'" x-model="line.product_id"
                                                @change="fillCost(i)" class="bmos-input" required>
                                            <option value="">— Producto —</option>
                                            <template x-for="p in products" :key="p.id">
                                                <option :value="p.id" x-text="p.name + ' (' + p.sku + ')'"></option>
                                            </template>
                                        </select>
                                    </div>
                                    <div class="col-span-2">
                                        <input type="number" step="0.001" min="0.001" :name="'lines[' + i + '][quantity]'"
                                               x-model="line.quantity" placeholder="Cant." class="bmos-input" required>
                                    </div>
                                    <div class="col-span-3">
                                        <input type="number" step="0.01" min="0" :name="'lines[' + i + '][unit_cost]'"
                                               x-model="line.unit_cost" placeholder="Costo" class="bmos-input" required>
                                    </div>
                                    <div class="col-span-1">
                                        <button type="button" @click="removeLine(i)" x-show="lines.length > 1"
                                                class="rounded-lg p-2 text-slate-400 hover:bg-rose-50 hover:text-rose-600" title="Quitar">✕</button>
                                    </div>
                                </div>
                            </template>
                        </div>

                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="bmos-field-label">Impuesto (opcional)</label>
                                <input type="number" step="0.01" min="0" name="tax" x-model="tax" value="0" class="bmos-input">
                            </div>
                            <div>
                                <label class="bmos-field-label">Nota (opcional)</label>
                                <input type="text" name="notes" maxlength="500" class="bmos-input">
                            </div>
                        </div>

                        <div class="flex items-center justify-between border-t border-slate-100 pt-3">
                            <p class="text-sm text-slate-500">
                                Total estimado: <span class="text-lg font-bold text-slate-800" x-text="total.toFixed(2)"></span>
                            </p>
                            <div class="flex gap-2">
                                <button type="button" @click="open = false" class="bmos-btn bmos-btn-ghost">Cancelar</button>
                                <button type="submit" class="bmos-btn bmos-btn-primary">Crear orden</button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <script>
            function purchaseOrderForm(products) {
                return {
                    open: false,
                    products,
                    tax: 0,
                    lines: [{ product_id: '', quantity: 1, unit_cost: '' }],

                    addLine() { this.lines.push({ product_id: '', quantity: 1, unit_cost: '' }); },
                    removeLine(i) { this.lines.splice(i, 1); },

                    /** Al elegir producto se propone su costo conocido; el comprador puede cambiarlo. */
                    fillCost(i) {
                        const p = this.products.find(p => String(p.id) === String(this.lines[i].product_id));
                        if (p && !this.lines[i].unit_cost) this.lines[i].unit_cost = p.cost;
                    },

                    get total() {
                        const lines = this.lines.reduce(
                            (s, l) => s + (parseFloat(l.quantity || 0) * parseFloat(l.unit_cost || 0)), 0
                        );
                        return lines + parseFloat(this.tax || 0);
                    },
                };
            }
        </script>
    @endcan

    <script>
        function suppliersCrud() {
            return {
                open: false,
                row: { id: '', name: '', tax_id: '', email: '', phone: '', address: '' },
                get editUrl() { return '{{ url('panel/compras') }}/' + this.row.id; },
                edit(data) { this.row = { ...data }; this.open = true; },
                init() {
                    @if (old('_form') === 'supplier_edit')
                        this.row = {
                            id: '{{ old('id') }}', name: @js(old('name')), tax_id: @js(old('tax_id')),
                            email: @js(old('email')), phone: @js(old('phone')), address: @js(old('address')),
                        };
                        this.open = true;
                    @endif
                },
            };
        }
    </script>
</x-layouts.admin>
