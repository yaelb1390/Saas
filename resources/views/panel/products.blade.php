<x-layouts.admin title="Inventario" heading="Inventario" subheading="Catálogo de productos y existencias por almacén">
    <div x-data="productsCrud()">
        <div class="bmos-card overflow-hidden">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 p-4">
                <p class="font-semibold text-slate-800">Productos</p>
                <div class="flex flex-wrap items-center gap-3">
                    <x-panel.search-bar placeholder="Buscar por SKU, nombre o código..." />
                    <x-panel.export-button route="panel.export.products" />
                @can('products.manage')
                <x-panel.create-modal title="Nuevo producto" label="Nuevo producto" form="product_create" :action="route('panel.products.store')">
                    <x-panel.field name="sku" label="SKU" required placeholder="PROD-0002" />
                    <x-panel.field name="name" label="Nombre" required placeholder="Nombre del producto" />
                    {{-- Opcional: no todo artículo trae código impreso. Tres formas de ponerlo:
                         teclearlo, pasar un lector de pistola (escribe en el campo enfocado), o la
                         cámara del móvil. El evento «codigo-escaneado» de la cámara llena el campo. --}}
                    <div x-data="{ barcode: @js(old('barcode', '')) }" @codigo-escaneado="barcode = $event.detail.codigo">
                        <label class="bmos-field-label">Código de barras (opcional)</label>
                        <input type="text" name="barcode" x-model="barcode"
                               placeholder="Escanea o teclea el código" class="bmos-input">
                        <x-panel.camera-scanner />
                    </div>
                    <div>
                        <label class="bmos-field-label">Categoría</label>
                        <select name="category_id" class="bmos-input">
                            <option value="">— Sin categoría —</option>
                            @foreach ($categories as $cat)
                                <option value="{{ $cat->id }}" @selected(old('category_id') == $cat->id)>{{ $cat->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <x-panel.field name="cost" label="Costo" type="number" step="0.01" value="0" />
                        <x-panel.field name="price" label="Precio" type="number" step="0.01" value="0" />
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <x-panel.field name="unit" label="Unidad" value="unidad" />
                        <x-panel.field name="initial_stock" label="Stock inicial" type="number" step="1" value="0" />
                    </div>
                    <label class="flex items-center gap-2 text-sm text-slate-600">
                        {{-- Truco Laravel: el hidden envía 0 y el checkbox 1; al marcar, gana el 1. --}}
                        <input type="hidden" name="track_stock" value="0">
                        <input type="checkbox" name="track_stock" value="1" checked class="rounded border-slate-300 text-indigo-600">
                        Controla stock (desmárcalo si es un servicio)
                    </label>

                    @if ($showPartFields)
                    <p class="mt-2 text-xs font-semibold uppercase tracking-wide text-slate-400">Datos de la pieza (opcional)</p>
                    <div class="grid grid-cols-2 gap-3">
                        <x-panel.field name="part_number" label="Nº de parte / OEM" placeholder="90915-YZZE1" />
                        <x-panel.field name="brand" label="Marca" placeholder="Bosch, NGK..." />
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <x-panel.field name="vehicle_make" label="Marca del vehículo" placeholder="Toyota" />
                        <x-panel.field name="vehicle_model" label="Modelo" placeholder="Corolla" />
                    </div>
                    <div class="grid grid-cols-3 gap-3">
                        <x-panel.field name="year_from" label="Año desde" type="number" placeholder="2015" />
                        <x-panel.field name="year_to" label="Año hasta" type="number" placeholder="2020" />
                        <x-panel.field name="location" label="Ubicación" placeholder="Pasillo 3 / Est. B" />
                    </div>
                    @endif
                </x-panel.create-modal>
                @endcan
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="bmos-table">
                    <thead>
                        <tr>
                            <th>SKU</th><th>Producto</th><th>Código</th><th>Categoría</th><th>Unidad</th>
                            <th>Costo</th><th>Precio</th><th>Stock</th><th>Estado</th><th class="text-right">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($products as $product)
                            @php $stock = (float) $product->stock->sum('quantity'); @endphp
                            <tr>
                                <td class="font-mono text-xs text-slate-500">{{ $product->sku }}</td>
                                <td class="font-medium text-slate-800">
                                    {{ $product->name }}
                                    @php $fit = $product->vehicleFit(); @endphp
                                    @if ($product->part_number || $product->brand || $fit || $product->location)
                                        <span class="mt-0.5 block text-xs font-normal text-slate-400">
                                            {{ collect([$product->part_number, $product->brand, $fit, $product->location ? '📍 '.$product->location : null])->filter()->implode(' · ') }}
                                        </span>
                                    @endif
                                </td>
                                <td class="font-mono text-xs text-slate-500">{{ $product->barcode ?? '—' }}</td>
                                <td>{{ $product->category?->name ?? '—' }}</td>
                                <td>{{ $product->unit }}</td>
                                <td>{{ number_format((float) $product->cost, 2) }}</td>
                                <td class="font-semibold">{{ number_format((float) $product->price, 2) }}</td>
                                <td><span class="bmos-badge {{ $stock < 5 ? 'badge-amber' : 'badge-blue' }}">{{ number_format($stock, 0) }}</span></td>
                                <td><span class="bmos-badge {{ $product->is_active ? 'badge-green' : 'badge-gray' }}">{{ $product->is_active ? 'Activo' : 'Inactivo' }}</span></td>
                                <td>
                                    <div class="flex items-center justify-end gap-1">
                                        @can('products.manage')
                                        <button type="button" class="rounded-lg p-1.5 text-slate-500 hover:bg-slate-100 hover:text-indigo-600" title="Editar"
                                                @click="edit({ id: {{ $product->id }}, sku: @js($product->sku), name: @js($product->name), barcode: @js($product->barcode), category_id: '{{ $product->category_id }}', unit: @js($product->unit), cost: '{{ $product->cost }}', price: '{{ $product->price }}', part_number: @js($product->part_number), brand: @js($product->brand), vehicle_make: @js($product->vehicle_make), vehicle_model: @js($product->vehicle_model), year_from: '{{ $product->year_from }}', year_to: '{{ $product->year_to }}', location: @js($product->location), track_stock: {{ $product->track_stock ? 'true' : 'false' }} })">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" class="h-4.5 w-4.5" style="width:1.15rem;height:1.15rem"><path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Z"/></svg>
                                        </button>
                                        <form method="POST" action="{{ route('panel.products.destroy', $product) }}" onsubmit="return confirm('¿Eliminar «{{ $product->name }}»?')">
                                            @csrf @method('DELETE')
                                            <button type="submit" class="rounded-lg p-1.5 text-slate-500 hover:bg-rose-50 hover:text-rose-600" title="Eliminar">
                                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" style="width:1.15rem;height:1.15rem"><path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0"/></svg>
                                            </button>
                                        </form>
                                        @endcan
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="10" class="bmos-empty">Aún no hay productos.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        <div class="mt-4">{{ $products->links() }}</div>

        {{-- Modal de edición --}}
        <div x-show="open" x-cloak class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-900/50 p-4 py-10" @keydown.escape.window="open=false">
            <div @click.outside="open=false" x-transition class="w-full max-w-lg rounded-2xl bg-white p-6 shadow-2xl">
                <div class="mb-4 flex items-center justify-between">
                    <h3 class="text-lg font-semibold text-slate-800">Editar producto</h3>
                    <button type="button" @click="open=false" class="text-slate-400 hover:text-slate-600">✕</button>
                </div>
                @if (old('_form') === 'product_edit' && $errors->any())
                    <div class="mb-4 rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700">
                        <ul class="list-disc pl-4">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
                    </div>
                @endif
                <form method="POST" :action="editUrl" class="space-y-3">
                    @csrf
                    <input type="hidden" name="_method" value="PUT">
                    <input type="hidden" name="_form" value="product_edit">
                    <input type="hidden" name="id" x-model="row.id">
                    <div><label class="bmos-field-label">SKU</label><input name="sku" x-model="row.sku" class="bmos-input" required></div>
                    <div><label class="bmos-field-label">Nombre</label><input name="name" x-model="row.name" class="bmos-input" required></div>
                    <div><label class="bmos-field-label">Código de barras (opcional)</label><input name="barcode" x-model="row.barcode" class="bmos-input" placeholder="Escanea o teclea el código"></div>
                    <div>
                        <label class="bmos-field-label">Categoría</label>
                        <select name="category_id" x-model="row.category_id" class="bmos-input">
                            <option value="">— Sin categoría —</option>
                            @foreach ($categories as $cat)
                                <option value="{{ $cat->id }}">{{ $cat->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div><label class="bmos-field-label">Costo</label><input name="cost" type="number" step="0.01" x-model="row.cost" class="bmos-input"></div>
                        <div><label class="bmos-field-label">Precio</label><input name="price" type="number" step="0.01" x-model="row.price" class="bmos-input"></div>
                    </div>
                    <div><label class="bmos-field-label">Unidad</label><input name="unit" x-model="row.unit" class="bmos-input"></div>
                    <label class="flex items-center gap-2 text-sm text-slate-600">
                        <input type="hidden" name="track_stock" value="0">
                        <input type="checkbox" name="track_stock" value="1" x-model="row.track_stock" class="rounded border-slate-300 text-indigo-600">
                        Controla stock (desmárcalo si es un servicio)
                    </label>

                    @if ($showPartFields)
                    <p class="pt-1 text-xs font-semibold uppercase tracking-wide text-slate-400">Datos de la pieza (opcional)</p>
                    <div class="grid grid-cols-2 gap-3">
                        <div><label class="bmos-field-label">Nº de parte / OEM</label><input name="part_number" x-model="row.part_number" class="bmos-input"></div>
                        <div><label class="bmos-field-label">Marca</label><input name="brand" x-model="row.brand" class="bmos-input"></div>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div><label class="bmos-field-label">Marca del vehículo</label><input name="vehicle_make" x-model="row.vehicle_make" class="bmos-input"></div>
                        <div><label class="bmos-field-label">Modelo</label><input name="vehicle_model" x-model="row.vehicle_model" class="bmos-input"></div>
                    </div>
                    <div class="grid grid-cols-3 gap-3">
                        <div><label class="bmos-field-label">Año desde</label><input name="year_from" type="number" x-model="row.year_from" class="bmos-input"></div>
                        <div><label class="bmos-field-label">Año hasta</label><input name="year_to" type="number" x-model="row.year_to" class="bmos-input"></div>
                        <div><label class="bmos-field-label">Ubicación</label><input name="location" x-model="row.location" class="bmos-input"></div>
                    </div>
                    @endif
                    <div class="flex justify-end gap-2 pt-3">
                        <button type="button" @click="open=false" class="bmos-btn bmos-btn-ghost">Cancelar</button>
                        <button type="submit" class="bmos-btn bmos-btn-primary">Guardar cambios</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        function productsCrud() {
            return {
                open: false,
                row: { id: '', sku: '', name: '', barcode: '', category_id: '', unit: '', cost: '', price: '', track_stock: true,
                       part_number: '', brand: '', vehicle_make: '', vehicle_model: '', year_from: '', year_to: '', location: '' },
                get editUrl() { return '{{ url('panel/inventario') }}/' + this.row.id; },
                edit(data) { this.row = { ...data }; this.open = true; },
                init() {
                    @if (old('_form') === 'product_edit')
                        this.row = {
                            id: '{{ old('id') }}', sku: @js(old('sku')), name: @js(old('name')),
                            barcode: @js(old('barcode')),
                            category_id: '{{ old('category_id') }}', unit: @js(old('unit')),
                            cost: @js(old('cost')), price: @js(old('price')),
                            part_number: @js(old('part_number')), brand: @js(old('brand')),
                            vehicle_make: @js(old('vehicle_make')), vehicle_model: @js(old('vehicle_model')),
                            year_from: '{{ old('year_from') }}', year_to: '{{ old('year_to') }}', location: @js(old('location')),
                            track_stock: {{ old('track_stock', 1) ? 'true' : 'false' }},
                        };
                        this.open = true;
                    @endif
                },
            };
        }
    </script>
</x-layouts.admin>
