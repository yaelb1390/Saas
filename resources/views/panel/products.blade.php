<x-layouts.admin title="Inventario" heading="Inventario" subheading="Catálogo de productos y existencias por almacén">
    <div x-data="productsCrud()">
        {{-- Cuatro cifras de todo el catálogo (no de la página a la vista): ver PanelController::products(). --}}
        <div class="mb-5 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <div class="bmos-stat">
                <div class="bmos-stat-icon tone-indigo"><x-icono name="cube" /></div>
                <p class="bmos-stat-label">Total de productos</p>
                <p class="bmos-stat-value">{{ number_format($resumen['total']) }}</p>
            </div>
            <div class="bmos-stat">
                <div class="bmos-stat-icon tone-sky"><x-icono name="truck" /></div>
                <p class="bmos-stat-label">Stock total</p>
                <p class="bmos-stat-value">{{ number_format((float) $resumen['stockTotal'], 0) }}</p>
            </div>
            <div class="bmos-stat">
                <div class="bmos-stat-icon tone-emerald"><x-icono name="cash" /></div>
                <p class="bmos-stat-label">Valor en inventario</p>
                <p class="bmos-stat-value">{{ number_format((float) $resumen['valorInventario'], 2) }}</p>
            </div>
            <div class="bmos-stat">
                <div class="bmos-stat-icon tone-amber"><x-icono name="alert" /></div>
                <p class="bmos-stat-label">Bajo stock</p>
                <p class="bmos-stat-value">{{ number_format($resumen['bajoStock']) }}</p>
            </div>
        </div>

        @if ($lowStockFilter)
            <div class="mb-4 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                <span class="flex items-center gap-2 font-medium">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-5 w-5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z"/></svg>
                    Mostrando solo productos con <b>stock bajo</b> (existencia por debajo de 5).
                </span>
                <a href="{{ route('panel.products') }}" class="bmos-btn bmos-btn-ghost text-xs">Ver todos</a>
            </div>
        @endif

        <div class="bmos-card overflow-hidden">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 p-4">
                <p class="font-semibold text-slate-800">Productos</p>
                <div class="flex flex-wrap items-center gap-3">
                    <x-panel.search-bar placeholder="Buscar por SKU, nombre o código...">
                        {{-- El botón «Filtros» agrupa categoría, almacén y el «solo bajo stock» que ya
                             existía, en vez de inventar una cuarta dimensión de filtro. Los tres viajan
                             en el MISMO <form> que la búsqueda, así que no se pierden al buscar. --}}
                        <div x-data="{ filtrosAbiertos: false }" class="relative">
                            <button type="button" @click="filtrosAbiertos = !filtrosAbiertos"
                                    class="bmos-btn {{ ($categoryFilter || $warehouseFilter || $lowStockFilter) ? 'bmos-btn-primary' : '' }}">
                                <x-icono name="sliders" class="h-4 w-4" />
                                Filtros
                            </button>
                            <div x-show="filtrosAbiertos" @click.outside="filtrosAbiertos = false" x-cloak x-transition
                                 class="absolute left-0 z-20 mt-2 w-64 space-y-3 rounded-xl border border-slate-200 bg-white p-4 shadow-lg">
                                <div>
                                    <label class="bmos-field-label">Categoría</label>
                                    <select name="category_id" class="bmos-input" onchange="this.form.submit()">
                                        <option value="">Todas</option>
                                        @foreach ($categories as $cat)
                                            <option value="{{ $cat->id }}" @selected($categoryFilter == $cat->id)>{{ $cat->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div>
                                    <label class="bmos-field-label">Almacén</label>
                                    <select name="warehouse_id" class="bmos-input" onchange="this.form.submit()">
                                        <option value="">Todos</option>
                                        @foreach ($warehouses as $w)
                                            <option value="{{ $w->id }}" @selected($warehouseFilter == $w->id)>{{ $w->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <label class="flex items-center gap-2 text-sm text-slate-600">
                                    <input type="checkbox" name="filter" value="low_stock" @checked($lowStockFilter)
                                           onchange="this.form.submit()" class="rounded border-slate-300 text-indigo-600">
                                    Solo stock bajo
                                </label>
                                @if ($categoryFilter || $warehouseFilter || $lowStockFilter)
                                    <a href="{{ route('panel.products') }}" class="block text-center text-xs font-semibold text-indigo-600 hover:text-indigo-700">Quitar filtros</a>
                                @endif
                            </div>
                        </div>
                    </x-panel.search-bar>
                    <x-panel.export-button route="panel.export.products" />
                @can('products.manage')
                <x-panel.create-modal title="Nuevo producto" label="Nuevo producto" form="product_create"
                                       enctype="multipart/form-data" :action="route('panel.products.store')">
                    <x-panel.field name="name" label="Nombre" required placeholder="Nombre del producto" />
                    <div>
                        <label class="bmos-field-label">SKU (opcional)</label>
                        <input type="text" name="sku" value="{{ old('sku') }}" class="bmos-input"
                               placeholder="Se genera solo">
                        <p class="mt-1 text-xs text-slate-400">
                            Déjalo vacío y el sistema asigna el siguiente código. Escríbelo solo si ya usas tu propia codificación.
                        </p>
                    </div>
                    @if ($usaFotos)
                    {{-- La foto se recuadra a vertical 3:4 al guardarla. Se avisa ANTES de subir, y
                         se comprueba la orientación en el navegador para decirlo en el momento en
                         que se elige el archivo, no después de guardar. --}}
                    <div x-data="avisoFotoVertical()">
                        <label class="bmos-field-label">Foto del producto (opcional)</label>
                        <input type="file" name="image" accept="image/*" @change="revisar($event)"
                               class="block w-full text-sm text-slate-600 file:mr-3 file:rounded-lg file:border-0 file:bg-indigo-50 file:px-3 file:py-2 file:text-sm file:font-semibold file:text-indigo-600 hover:file:bg-indigo-100">
                        <p class="mt-1 text-xs text-slate-400">
                            Súbela <b>en vertical</b> (más alta que ancha). Se muestra en el Punto de Venta. Hasta 8&nbsp;MB.
                        </p>
                        <p x-show="preparando" x-cloak class="mt-1 text-xs text-indigo-600">
                            Preparando la foto…
                        </p>
                        <p x-show="apaisada" x-cloak
                           class="mt-1 rounded-lg border border-amber-200 bg-amber-50 px-2 py-1.5 text-xs text-amber-800">
                            Esa foto es <b x-text="forma"></b>. Se guardará igualmente, centrada sobre fondo blanco,
                            pero se verá con franjas arriba y abajo. Una foto vertical llena la ficha entera.
                        </p>
                    </div>
                    @endif
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

                    {{-- EN QUÉ ALMACÉN entra ese stock inicial.
                         Se creaba siempre en el de por omisión, escrito a fuego: dabas de alta cien
                         piezas para la sucursal y aparecían en el principal. Con un solo almacén no se
                         pregunta, que no hay nada que decidir. --}}
                    @if (count($warehouses) > 1)
                        <div>
                            <label class="bmos-field-label" for="crear-almacen">Almacén del stock inicial</label>
                            <select id="crear-almacen" name="warehouse_id" class="bmos-input">
                                @foreach ($warehouses as $w)
                                    <option value="{{ $w->id }}">{{ $w->name }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endif
                    <label class="flex items-center gap-2 text-sm text-slate-600">
                        {{-- Truco Laravel: el hidden envía 0 y el checkbox 1; al marcar, gana el 1. --}}
                        <input type="hidden" name="track_stock" value="0">
                        <input type="checkbox" name="track_stock" value="1" checked class="rounded border-slate-300 text-indigo-600">
                        Controla stock (desmárcalo si es un servicio)
                    </label>

                    {{-- Con número de serie: cada unidad se da de alta y se vende por su serie. Para
                         celulares, electrónica, electrodomésticos. Casi nadie lo marca; ver ProductUnit. --}}
                    <label class="flex items-center gap-2 text-sm text-slate-600">
                        <input type="hidden" name="tracks_serials" value="0">
                        <input type="checkbox" name="tracks_serials" value="1" class="rounded border-slate-300 text-indigo-600">
                        Con número de serie (cada unidad es única)
                    </label>

                    {{-- Los detalles: para todos menos los de comida. Una empanada no tiene marca ni
                         estante; una ferretería sí, y hasta ahora no tenía dónde apuntarlos. --}}
                    @if ($showPartFields)
                    <p class="mt-2 text-xs font-semibold uppercase tracking-wide text-slate-400">Detalles del artículo (opcional)</p>
                    <div class="grid grid-cols-2 gap-3">
                        <x-panel.field name="brand" label="Marca" placeholder="Bosch, Truper, Nike..." />
                        <x-panel.field name="location" label="Almacén / estante" placeholder="Pasillo 3 / Est. B" />
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <x-panel.field name="part_number" label="Nº de parte / referencia" placeholder="90915-YZZE1" />
                        <x-panel.field name="description" label="Descripción" placeholder="Lo que conviene saber al venderlo" />
                    </div>

                    {{-- El vehículo, solo donde se venden piezas: «Marca del vehículo» en una tienda de
                         ropa es un campo que nadie va a rellenar nunca, y cada campo de más en un alta
                         es una razón más para no darla. --}}
                    @if ($showVehicleFields)
                        <div class="grid grid-cols-2 gap-3">
                            <x-panel.field name="vehicle_make" label="Marca del vehículo" placeholder="Toyota" />
                            <x-panel.field name="vehicle_model" label="Modelo" placeholder="Corolla" />
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <x-panel.field name="year_from" label="Año desde" type="number" placeholder="2015" />
                            <x-panel.field name="year_to" label="Año hasta" type="number" placeholder="2020" />
                        </div>
                    @endif
                    @endif
                </x-panel.create-modal>
                @endcan
                </div>
            </div>
            @can('products.manage')
                {{-- Barra de selección. Solo aparece con algo marcado: si estuviera siempre, sería
                     un botón de borrado permanente sobre el inventario. --}}
                <div x-show="marcados.length > 0" x-cloak
                     class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 bg-indigo-50/60 px-4 py-3">
                    <div class="text-sm text-slate-700">
                        <span class="font-semibold" x-text="etiquetaSeleccion()"></span>
                        {{-- Con la página entera marcada se ofrece abarcar TODO lo que coincide con la
                             búsqueda: es lo que hace falta para vaciar un catálogo de cientos sin
                             recorrer veinte páginas. --}}
                        <template x-if="paginaCompleta() && !todos && {{ $products->total() }} > marcados.length">
                            <button type="button" @click="todos = true"
                                    class="ml-2 font-semibold text-indigo-600 underline hover:text-indigo-700">
                                Seleccionar los {{ number_format($products->total()) }} que coinciden
                            </button>
                        </template>
                        <template x-if="todos">
                            <button type="button" @click="todos = false; marcados = []"
                                    class="ml-2 font-semibold text-indigo-600 underline hover:text-indigo-700">
                                Quitar la selección
                            </button>
                        </template>
                    </div>
                    <button type="button" @click="confirmarBorrado()"
                            class="inline-flex items-center gap-2 rounded-xl bg-rose-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-rose-700">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-4 w-4"><path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0"/></svg>
                        Eliminar
                    </button>
                </div>

                <form method="POST" action="{{ route('panel.products.bulk-destroy') }}" id="borrar_productos" class="hidden">
                    @csrf @method('DELETE')
                    <input type="hidden" name="todos" x-bind:value="todos ? 1 : 0">
                    <input type="hidden" name="q" value="{{ request('q') }}">
                    <input type="hidden" name="filter" value="{{ request('filter') }}">
                    <input type="hidden" name="category_id" value="{{ $categoryFilter }}">
                    <input type="hidden" name="warehouse_id" value="{{ $warehouseFilter }}">
                    <template x-for="id in marcados" :key="id">
                        <input type="hidden" name="ids[]" :value="id">
                    </template>
                </form>
            @endcan

            <div class="overflow-x-auto">
                <table class="bmos-table bmos-tabla-tarjetas">
                    <thead>
                        <tr>
                            @can('products.manage')
                                <th class="w-10">
                                    <input type="checkbox" @change="alternarPagina($event.target.checked)"
                                           :checked="paginaCompleta()" aria-label="Seleccionar todos"
                                           class="rounded border-slate-300 text-indigo-600">
                                </th>
                            @endcan
                            <th><span class="inline-flex items-center gap-1.5"><x-icono name="tag" class="h-3.5 w-3.5 text-slate-400" />SKU</span></th>
                            <th><span class="inline-flex items-center gap-1.5"><x-icono name="cube" class="h-3.5 w-3.5 text-slate-400" />Producto</span></th>
                            <th><span class="inline-flex items-center gap-1.5"><x-icono name="doc" class="h-3.5 w-3.5 text-slate-400" />Código</span></th>
                            <th><span class="inline-flex items-center gap-1.5"><x-icono name="building" class="h-3.5 w-3.5 text-slate-400" />Categoría</span></th>
                            <th><span class="inline-flex items-center gap-1.5"><x-icono name="sliders" class="h-3.5 w-3.5 text-slate-400" />Unidad</span></th>
                            <th><span class="inline-flex items-center gap-1.5"><x-icono name="cash" class="h-3.5 w-3.5 text-slate-400" />Costo</span></th>
                            <th><span class="inline-flex items-center gap-1.5"><x-icono name="chart" class="h-3.5 w-3.5 text-slate-400" />Precio</span></th>
                            <th><span class="inline-flex items-center gap-1.5"><x-icono name="truck" class="h-3.5 w-3.5 text-slate-400" />Stock</span></th>
                            <th><span class="inline-flex items-center gap-1.5"><x-icono name="shield" class="h-3.5 w-3.5 text-slate-400" />Estado</span></th>
                            <th class="text-right">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($products as $product)
                            @php $stock = (float) $product->stock->sum('quantity'); @endphp
                            <tr :class="marcados.includes({{ $product->id }}) ? 'bg-indigo-50/50' : ''">
                                @can('products.manage')
                                    <td>
                                        <input type="checkbox" value="{{ $product->id }}" x-model.number="marcados"
                                               aria-label="Seleccionar {{ $product->name }}"
                                               class="rounded border-slate-300 text-indigo-600">
                                    </td>
                                @endcan
                                <td data-rotulo="SKU" class="font-mono text-xs text-slate-500">{{ $product->sku }}</td>
                                <td data-rotulo="Producto" class="font-medium text-slate-800">
                                    <div class="flex items-center gap-2.5">
                                        {{-- Sin fotos, el hueco gris de cada fila sobra: no dice nada y estrecha el nombre. --}}
                                        @if ($usaFotos)
                                        <span class="grid h-9 w-9 shrink-0 place-items-center overflow-hidden rounded-md bg-slate-100">
                                            @if ($product->hasImage())
                                                <img src="{{ $product->imageUrl() }}" alt="" loading="lazy" class="h-full w-full object-cover">
                                            @else
                                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.3" class="h-5 w-5 text-slate-300"><path stroke-linecap="round" stroke-linejoin="round" d="m2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909M3.75 19.5h16.5a1.5 1.5 0 0 0 1.5-1.5V6a1.5 1.5 0 0 0-1.5-1.5H3.75A1.5 1.5 0 0 0 2.25 6v12a1.5 1.5 0 0 0 1.5 1.5Z"/></svg>
                                            @endif
                                        </span>
                                        @endif
                                        <div class="min-w-0">
                                            {{ $product->name }}
                                            @php $fit = $product->vehicleFit(); @endphp
                                            @if ($product->part_number || $product->brand || $fit || $product->location)
                                                <span class="mt-0.5 block text-xs font-normal text-slate-400">
                                                    {{ collect([$product->part_number, $product->brand, $fit, $product->location ? '📍 '.$product->location : null])->filter()->implode(' · ') }}
                                                </span>
                                            @endif
                                        </div>
                                    </div>
                                </td>
                                <td data-rotulo="Código" class="font-mono text-xs text-slate-500">{{ $product->barcode ?? '—' }}</td>
                                <td data-rotulo="Categoría">{{ $product->category?->name ?? '—' }}</td>
                                <td data-rotulo="Unidad">{{ $product->unit }}</td>
                                <td data-rotulo="Costo">{{ number_format((float) $product->cost, 2) }}</td>
                                <td data-rotulo="Precio" class="font-semibold">{{ number_format((float) $product->price, 2) }}</td>
                                {{-- Un producto SIN control de existencias no tiene existencia, y enseñarle
                                     un «0» en ámbar es mentirle: se lee como «se acabó» cuando significa «esto
                                     no se cuenta». Pasó de verdad —una batida sin control aparecía como agotada
                                     y no había forma de entender por qué no se podía reponer—. --}}
                                <td data-rotulo="Stock">
                                    @if (! $product->track_stock)
                                        <span class="bmos-badge badge-gray" title="Este producto no lleva control de existencias.">—</span>
                                    @else
                                        <span class="bmos-badge is-punto {{ $stock < 5 ? 'badge-amber' : 'badge-blue' }}">{{ number_format($stock, 0) }}</span>
                                    @endif
                                </td>
                                <td data-rotulo="Estado">
                                    @can('products.manage')
                                        {{-- Con permiso de gestión SÍ es un interruptor de verdad: activar o
                                             retirar un producto del catálogo (ver ProductStatusController). --}}
                                        <form method="POST" action="{{ route('panel.products.status', $product) }}">
                                            @csrf
                                            <input type="hidden" name="is_active" value="{{ $product->is_active ? '0' : '1' }}">
                                            <button type="submit" class="flex items-center gap-2" title="{{ $product->is_active ? 'Activo — clic para retirar del catálogo' : 'Inactivo — clic para activar' }}">
                                                <span class="inline-flex h-5 w-9 shrink-0 items-center rounded-full {{ $product->is_active ? 'bg-emerald-500' : 'bg-slate-300' }}">
                                                    <span class="h-4 w-4 rounded-full bg-white shadow transition-transform {{ $product->is_active ? 'translate-x-4' : 'translate-x-0.5' }}"></span>
                                                </span>
                                                <span class="text-xs font-medium {{ $product->is_active ? 'text-emerald-600' : 'text-slate-500' }}">{{ $product->is_active ? 'Activo' : 'Inactivo' }}</span>
                                            </button>
                                        </form>
                                    @else
                                        {{-- Solo lectura: se enseña de un vistazo, pero no es clicable. --}}
                                        <div class="flex items-center gap-2" title="{{ $product->is_active ? 'Activo' : 'Inactivo' }}">
                                            <span class="inline-flex h-5 w-9 shrink-0 items-center rounded-full {{ $product->is_active ? 'bg-emerald-500' : 'bg-slate-300' }}">
                                                <span class="h-4 w-4 rounded-full bg-white shadow transition-transform {{ $product->is_active ? 'translate-x-4' : 'translate-x-0.5' }}"></span>
                                            </span>
                                            <span class="text-xs font-medium {{ $product->is_active ? 'text-emerald-600' : 'text-slate-500' }}">{{ $product->is_active ? 'Activo' : 'Inactivo' }}</span>
                                        </div>
                                    @endcan
                                    {{-- «Se acabó» no es lo mismo que «inactivo»: lo primero cambia
                                         dos veces al día y lo segundo es retirarlo del catálogo. Se
                                         enseña aparte para que no se confundan de un vistazo. --}}
                                    @if ($product->is_active && ! $product->is_available)
                                        <span class="bmos-badge badge-amber mt-1 block">Se acabó</span>
                                    @endif
                                </td>
                                <td>
                                    <div class="flex items-center justify-end gap-1">
                                        {{-- Ficha de solo lectura. Con `products.view`: quien solo puede
                                             consultar el catálogo también puede mirar el detalle de un
                                             producto, aunque no pueda tocarlo. --}}
                                        @can('products.view')
                                            <button type="button" class="rounded-lg p-1.5 text-slate-500 hover:bg-slate-100 hover:text-indigo-600" title="Ver"
                                                    @click="ver({ sku: @js($product->sku), name: @js($product->name), barcode: @js($product->barcode), category: @js($product->category?->name), unit: @js($product->unit), cost: '{{ number_format((float) $product->cost, 2) }}', price: '{{ number_format((float) $product->price, 2) }}', track_stock: {{ $product->track_stock ? 'true' : 'false' }}, is_active: {{ $product->is_active ? 'true' : 'false' }}, stock: @js($product->stock->map(fn ($s) => ['almacen' => $s->warehouse?->name ?? '—', 'cantidad' => number_format((float) $s->quantity, 0)])->all()) })">
                                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" style="width:1.15rem;height:1.15rem">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.964-7.178Z"/>
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/>
                                                </svg>
                                            </button>
                                        @endcan
                                        {{-- «Hoy no hay». Con `products.view`, que es lo que ya tiene
                                             quien opera el terminal: que se acabó el guineo lo sabe
                                             el cajero, no el dueño desde su casa. --}}
                                        @can('products.view')
                                            <form method="POST" action="{{ route('panel.products.availability', $product) }}">
                                                @csrf
                                                <input type="hidden" name="is_available" value="{{ $product->is_available ? '0' : '1' }}">
                                                <button type="submit"
                                                        class="rounded-lg p-1.5 {{ $product->is_available ? 'text-slate-500 hover:bg-amber-50 hover:text-amber-600' : 'text-amber-600 hover:bg-emerald-50 hover:text-emerald-600' }}"
                                                        title="{{ $product->is_available ? 'Marcar que se acabó' : 'Volver a tenerlo' }}">
                                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" style="width:1.15rem;height:1.15rem">
                                                        @if ($product->is_available)
                                                            <path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 0 0 1.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.451 10.451 0 0 1 12 4.5c4.756 0 8.773 3.162 10.065 7.498a10.522 10.522 0 0 1-4.293 5.774M6.228 6.228 3 3m3.228 3.228 3.65 3.65m7.894 7.894L21 21m-3.228-3.228-3.65-3.65m0 0a3 3 0 1 0-4.243-4.243"/>
                                                        @else
                                                            <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.964-7.178Z"/>
                                                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/>
                                                        @endif
                                                    </svg>
                                                </button>
                                            </form>
                                        @endcan
                                        @can('stock.adjust')
                                            @if ($product->track_stock)
                                                {{-- Contar desde aquí. Antes había que ir a «Entrada de mercancía» y
                                                     buscar el producto otra vez, y esa pantalla es para COMPRAS: no
                                                     sirve para corregir hacia abajo cuando lo contado es menos de lo
                                                     que decía el sistema. --}}
                                                <button type="button" title="Contar existencia"
                                                        @click="contar({ id: {{ $product->id }}, nombre: @js($product->name), actual: '{{ number_format($stock, 0, '.', '') }}' })"
                                                        class="rounded-lg p-1.5 text-slate-500 hover:bg-slate-100 hover:text-indigo-600">
                                                    <x-icono name="cube" class="h-4 w-4" />
                                                </button>
                                            @endif
                                        @endcan
                                        @can('products.manage')
                                        <button type="button" class="rounded-lg p-1.5 text-slate-500 hover:bg-slate-100 hover:text-indigo-600" title="Editar"
                                                @click="edit({ id: {{ $product->id }}, sku: @js($product->sku), name: @js($product->name), barcode: @js($product->barcode), category_id: '{{ $product->category_id }}', unit: @js($product->unit), cost: '{{ $product->cost }}', price: '{{ $product->price }}', part_number: @js($product->part_number), brand: @js($product->brand), vehicle_make: @js($product->vehicle_make), vehicle_model: @js($product->vehicle_model), year_from: '{{ $product->year_from }}', year_to: '{{ $product->year_to }}', location: @js($product->location), description: @js($product->description), track_stock: {{ $product->track_stock ? 'true' : 'false' }}, tracks_serials: {{ $product->tracks_serials ? 'true' : 'false' }}, image: @js($product->imageUrl()) })">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" class="h-4.5 w-4.5" style="width:1.15rem;height:1.15rem"><path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Z"/></svg>
                                        </button>
                                        {{-- Copia los datos de catálogo a un producto nuevo, con SKU
                                             propio y existencia en cero (ver ProductController::duplicate).
                                             Sin confirmación: duplicar no es destructivo, no hace falta
                                             el mismo aviso que borrar. --}}
                                        <form method="POST" action="{{ route('panel.products.duplicate', $product) }}">
                                            @csrf
                                            <button type="submit" class="rounded-lg p-1.5 text-slate-500 hover:bg-slate-100 hover:text-indigo-600" title="Duplicar">
                                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" style="width:1.15rem;height:1.15rem"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 17.25v3.375c0 .621-.504 1.125-1.125 1.125h-9.75a1.125 1.125 0 0 1-1.125-1.125V7.875c0-.621.504-1.125 1.125-1.125H6.75a9.06 9.06 0 0 1 1.5.124m7.5 10.376h3.375c.621 0 1.125-.504 1.125-1.125V11.25c0-4.46-3.243-8.161-7.5-8.876a9.06 9.06 0 0 0-1.5-.124H9.375c-.621 0-1.125.504-1.125 1.125v3.5m7.5 10.375H9.375a1.125 1.125 0 0 1-1.125-1.125v-9.25"/></svg>
                                            </button>
                                        </form>
                                        <x-panel.confirm-action
                                            :action="route('panel.products.destroy', $product)"
                                            title="¿Eliminar «{{ $product->name }}»?"
                                            message="Dejará de aparecer en el inventario y en el punto de venta."
                                            note="Las ventas ya registradas no cambian: los recibos y los informes siguen mostrando lo que se vendió."
                                            tooltip="Eliminar"
                                            class="rounded-lg p-1.5 text-slate-500 hover:bg-rose-50 hover:text-rose-600">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" style="width:1.15rem;height:1.15rem"><path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0"/></svg>
                                        </x-panel.confirm-action>
                                        @endcan
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="{{ auth()->user()?->can('products.manage') ? 11 : 10 }}" class="bmos-empty">Aún no hay productos.</td></tr>
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
                <form method="POST" :action="editUrl" class="space-y-3" enctype="multipart/form-data">
                    @csrf
                    <input type="hidden" name="_method" value="PUT">
                    <input type="hidden" name="_form" value="product_edit">
                    <input type="hidden" name="id" x-model="row.id">
                    <div><label class="bmos-field-label">SKU</label><input name="sku" x-model="row.sku" class="bmos-input" required></div>
                    <div><label class="bmos-field-label">Nombre</label><input name="name" x-model="row.name" class="bmos-input" required></div>
                    @if ($usaFotos)
                    <div x-data="avisoFotoVertical()">
                        <label class="bmos-field-label">Foto del producto</label>
                        <div class="flex items-center gap-3">
                            <span class="grid h-14 w-14 shrink-0 place-items-center overflow-hidden rounded-lg bg-slate-100">
                                <template x-if="row.image">
                                    <img :src="row.image" alt="" class="h-full w-full object-cover">
                                </template>
                                <template x-if="!row.image">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.3" class="h-6 w-6 text-slate-300"><path stroke-linecap="round" stroke-linejoin="round" d="m2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909M3.75 19.5h16.5a1.5 1.5 0 0 0 1.5-1.5V6a1.5 1.5 0 0 0-1.5-1.5H3.75A1.5 1.5 0 0 0 2.25 6v12a1.5 1.5 0 0 0 1.5 1.5Z"/></svg>
                                </template>
                            </span>
                            <input type="file" name="image" accept="image/*" @change="revisar($event)"
                                   class="block w-full text-sm text-slate-600 file:mr-3 file:rounded-lg file:border-0 file:bg-indigo-50 file:px-3 file:py-2 file:text-sm file:font-semibold file:text-indigo-600 hover:file:bg-indigo-100">
                        </div>
                        <p class="mt-1 text-xs text-slate-400">
                            Sube una nueva para reemplazarla. Mejor <b>en vertical</b> (más alta que ancha).
                        </p>
                        <p x-show="preparando" x-cloak class="mt-1 text-xs text-indigo-600">
                            Preparando la foto…
                        </p>
                        <p x-show="apaisada" x-cloak
                           class="mt-1 rounded-lg border border-amber-200 bg-amber-50 px-2 py-1.5 text-xs text-amber-800">
                            Esa foto es <b x-text="forma"></b>. Se guardará centrada sobre fondo blanco,
                            pero se verá con franjas arriba y abajo.
                        </p>
                    </div>
                    @endif
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

                    <label class="flex items-center gap-2 text-sm text-slate-600">
                        <input type="hidden" name="tracks_serials" value="0">
                        <input type="checkbox" name="tracks_serials" value="1" x-model="row.tracks_serials" class="rounded border-slate-300 text-indigo-600">
                        Con número de serie (cada unidad es única)
                    </label>

                    {{-- Los mismos criterios que en el alta: si un campo se puede escribir al crear y
                         no al editar, el dato entra una vez y ya no hay forma de corregirlo. --}}
                    @if ($showPartFields)
                    <p class="pt-1 text-xs font-semibold uppercase tracking-wide text-slate-400">Detalles del artículo (opcional)</p>
                    <div class="grid grid-cols-2 gap-3">
                        <div><label class="bmos-field-label">Marca</label><input name="brand" x-model="row.brand" class="bmos-input"></div>
                        <div><label class="bmos-field-label">Almacén / estante</label><input name="location" x-model="row.location" class="bmos-input"></div>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div><label class="bmos-field-label">Nº de parte / referencia</label><input name="part_number" x-model="row.part_number" class="bmos-input"></div>
                        <div><label class="bmos-field-label">Descripción</label><input name="description" x-model="row.description" class="bmos-input"></div>
                    </div>

                    @if ($showVehicleFields)
                        <div class="grid grid-cols-2 gap-3">
                            <div><label class="bmos-field-label">Marca del vehículo</label><input name="vehicle_make" x-model="row.vehicle_make" class="bmos-input"></div>
                            <div><label class="bmos-field-label">Modelo</label><input name="vehicle_model" x-model="row.vehicle_model" class="bmos-input"></div>
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <div><label class="bmos-field-label">Año desde</label><input name="year_from" type="number" x-model="row.year_from" class="bmos-input"></div>
                            <div><label class="bmos-field-label">Año hasta</label><input name="year_to" type="number" x-model="row.year_to" class="bmos-input"></div>
                        </div>
                    @endif
                    @endif
                    <div class="flex justify-end gap-2 pt-3">
                        <button type="button" @click="open=false" class="bmos-btn bmos-btn-ghost">Cancelar</button>
                        <button type="submit" class="bmos-btn bmos-btn-primary">Guardar cambios</button>
                    </div>
                </form>
            </div>
        </div>
    
    {{-- Ficha de solo lectura. A propósito NO es un formulario: mostrar sin poder tocar nada evita
         que quien solo tiene `products.view` se encuentre con campos que no puede guardar. --}}
    <div x-show="verAbierto" x-cloak @keydown.escape.window="verAbierto = false"
         class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-900/50 p-4 py-10">
        <div @click.outside="verAbierto = false" x-transition class="w-full max-w-lg rounded-2xl bg-white p-6 shadow-2xl">
            <div class="mb-4 flex items-center justify-between">
                <h3 class="text-lg font-semibold text-slate-800" x-text="viendo.name"></h3>
                <button type="button" @click="verAbierto = false" class="text-slate-400 hover:text-slate-600">✕</button>
            </div>
            <dl class="space-y-3 text-sm">
                <div class="grid grid-cols-2 gap-3">
                    <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-400">SKU</dt><dd class="font-mono text-slate-700" x-text="viendo.sku"></dd></div>
                    <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-400">Código de barras</dt><dd class="font-mono text-slate-700" x-text="viendo.barcode || '—'"></dd></div>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-400">Categoría</dt><dd class="text-slate-700" x-text="viendo.category || '—'"></dd></div>
                    <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-400">Unidad</dt><dd class="text-slate-700" x-text="viendo.unit"></dd></div>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-400">Costo</dt><dd class="text-slate-700" x-text="viendo.cost"></dd></div>
                    <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-400">Precio</dt><dd class="font-semibold text-slate-800" x-text="viendo.price"></dd></div>
                </div>
                <div>
                    <dt class="text-xs font-semibold uppercase tracking-wide text-slate-400">Estado</dt>
                    <dd class="mt-1">
                        <span class="bmos-badge" :class="viendo.is_active ? 'badge-green' : 'badge-gray'" x-text="viendo.is_active ? 'Activo' : 'Inactivo'"></span>
                    </dd>
                </div>
                <div x-show="viendo.track_stock">
                    <dt class="text-xs font-semibold uppercase tracking-wide text-slate-400">Existencia por almacén</dt>
                    <dd class="mt-1.5 space-y-1">
                        <template x-if="viendo.stock && viendo.stock.length === 0">
                            <p class="text-slate-400">Sin existencia registrada todavía.</p>
                        </template>
                        <template x-for="fila in viendo.stock" :key="fila.almacen">
                            <div class="flex items-center justify-between rounded-lg bg-slate-50 px-3 py-1.5">
                                <span class="text-slate-600" x-text="fila.almacen"></span>
                                <span class="font-semibold text-slate-800" x-text="fila.cantidad"></span>
                            </div>
                        </template>
                    </dd>
                </div>
            </dl>
            <div class="mt-5 flex justify-end">
                <button type="button" @click="verAbierto = false" class="bmos-btn">Cerrar</button>
            </div>
        </div>
    </div>

    {{-- Contar existencia.

         El usuario escribe LO QUE HAY, no la diferencia: nadie cuenta «tengo tres de más», cuenta
         «tengo veinticuatro». El sistema traduce eso a un movimiento de ajuste con la diferencia,
         que es lo que hace que el kardex siga contando la verdad. --}}
    <div x-show="conteo.abierto" x-cloak @keydown.escape.window="conteo.abierto = false"
         class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4">
        <div @click.outside="conteo.abierto = false" class="w-full max-w-sm rounded-2xl bg-white p-5 shadow-xl">
            <div class="mb-3 flex items-start justify-between gap-3">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Contar existencia</p>
                    <p class="font-semibold text-slate-800" x-text="conteo.nombre"></p>
                </div>
                <button type="button" @click="conteo.abierto = false" class="text-slate-400 hover:text-slate-600">✕</button>
            </div>

            <form method="POST" :action="'{{ url('panel/inventario') }}/' + conteo.id + '/existencia'">
                @csrf

                <p class="mb-3 rounded-lg bg-slate-50 px-3 py-2 text-sm text-slate-600">
                    El sistema dice <b x-text="conteo.actual"></b>.
                </p>

                <label class="bmos-field-label">¿Cuántos hay de verdad?</label>
                <input type="number" name="counted" step="1" min="0" x-model="conteo.contado"
                       class="bmos-input" required autofocus>

                <label class="bmos-field-label mt-3">¿Por qué no cuadraba? <span class="font-normal text-slate-400">— opcional</span></label>
                <input type="text" name="note" x-model="conteo.nota" maxlength="255"
                       class="bmos-input" placeholder="Se rompieron dos, se regaló uno…">

                {{-- Se dice el salto ANTES de guardar. «De 21 a 24» se entiende; «+3» hay que
                     calcularlo, y es justo el momento de darse cuenta de que uno se equivocó. --}}
                <p x-show="Number(conteo.contado) !== Number(conteo.actual)" x-cloak
                   class="mt-2 text-xs font-medium"
                   :class="Number(conteo.contado) > Number(conteo.actual) ? 'text-emerald-600' : 'text-amber-600'">
                    Queda registrado como
                    <span x-text="(Number(conteo.contado) > Number(conteo.actual) ? '+' : '') + (Number(conteo.contado) - Number(conteo.actual))"></span>,
                    de <span x-text="conteo.actual"></span> a <span x-text="conteo.contado"></span>.
                </p>

                <div class="mt-4 flex justify-end gap-2">
                    <button type="button" @click="conteo.abierto = false" class="bmos-btn">Cancelar</button>
                    <button type="submit" class="bmos-btn bmos-btn-primary"
                            :disabled="Number(conteo.contado) === Number(conteo.actual)">Guardar el conteo</button>
                </div>
            </form>
        </div>
    </div>

</div>

    <script>
        /**
         * Avisa si la foto elegida no es vertical.
         *
         * Se comprueba en el navegador y no en el servidor a propósito: el cajero se entera al
         * elegir el archivo, no después de guardar y ver el resultado raro en el punto de venta.
         * NO bloquea la subida —la foto se guarda igual, recuadrada— porque a veces la única foto
         * disponible del producto es la que hay.
         */
        function avisoFotoVertical() {
            return {
                apaisada: false,
                forma: '',
                preparando: false,

                revisar(event) {
                    const input = event.target;
                    const file = input.files?.[0];
                    this.apaisada = false;

                    if (!file || !file.type.startsWith('image/')) return;

                    this.preparando = true;
                    this.retenerEnvio(input.form);

                    const url = URL.createObjectURL(file);
                    const img = new Image();

                    img.onload = () => {
                        // Vertical = más alta que ancha. Una foto cuadrada también deja franjas,
                        // así que también se avisa.
                        this.apaisada = img.height <= img.width;
                        this.forma = img.height === img.width ? 'cuadrada' : 'apaisada';

                        this.recuadrar(img, input, file, () => URL.revokeObjectURL(url));
                    };

                    img.onerror = () => {
                        URL.revokeObjectURL(url);
                        this.preparando = false;
                    };

                    img.src = url;
                },

                /**
                 * Retiene el envío mientras se recuadra.
                 *
                 * El recuadrado tarda unas décimas de segundo. Sin esta espera, quien pulse Guardar
                 * enseguida subiría la foto original sin recuadrar —justo el problema que se venía
                 * a resolver— y encima de forma intermitente, que es lo peor de diagnosticar.
                 */
                retenerEnvio(form) {
                    if (!form || form.dataset.esperaFoto === 'si') return;

                    form.dataset.esperaFoto = 'si';

                    form.addEventListener('submit', (event) => {
                        if (!this.preparando) return;

                        event.preventDefault();

                        const esperar = setInterval(() => {
                            if (this.preparando) return;

                            clearInterval(esperar);
                            form.requestSubmit();
                        }, 50);
                    });
                },

                /**
                 * Recuadra la foto AQUÍ, en el navegador, antes de subirla.
                 *
                 * El servidor lo hace igual cuando puede, pero en producción no puede: el entorno
                 * sin servidor no trae la extensión de imágenes de PHP, así que allí la foto se
                 * guardaba tal cual llegaba. Una foto de móvil son 2-3 MB, y el punto de venta
                 * muestra decenas a la vez: la rejilla se volvía lentísima.
                 *
                 * La geometría es la misma que la del servidor (ver ProductImageStore::resize),
                 * para que una foto quede idéntica venga por donde venga.
                 *
                 * Si algo falla, se sube el archivo original: nunca se impide guardar por esto.
                 */
                recuadrar(img, input, original, limpiar) {
                    const MAX = 800, RW = 3, RH = 4;

                    try {
                        const w = img.naturalWidth || img.width;
                        const h = img.naturalHeight || img.height;

                        const alto = Math.min(MAX, Math.max(h, Math.ceil(w * RH / RW)));
                        const ancho = Math.max(1, Math.round(alto * RW / RH));

                        // Nunca amplía: una foto pequeña se centra en vez de estirarse y pixelarse.
                        const escala = Math.min(1, ancho / w, alto / h);
                        const nw = Math.max(1, Math.round(w * escala));
                        const nh = Math.max(1, Math.round(h * escala));

                        const lienzo = document.createElement('canvas');
                        lienzo.width = ancho;
                        lienzo.height = alto;

                        const ctx = lienzo.getContext('2d');
                        // Fondo blanco: aplana transparencias al pasar a JPEG.
                        ctx.fillStyle = '#ffffff';
                        ctx.fillRect(0, 0, ancho, alto);
                        ctx.drawImage(img, Math.round((ancho - nw) / 2), Math.round((alto - nh) / 2), nw, nh);

                        lienzo.toBlob((blob) => {
                            // Solo se sustituye si de verdad sale más pequeña. Con una foto ya
                            // pequeña, reprocesarla solo le quitaría calidad.
                            if (blob && blob.size < original.size) {
                                const nombre = original.name.replace(/\.[^.]+$/, '') + '.jpg';
                                const datos = new DataTransfer();
                                datos.items.add(new File([blob], nombre, { type: 'image/jpeg' }));
                                input.files = datos.files;
                            }

                            this.preparando = false;
                            limpiar();
                        }, 'image/jpeg', 0.82);
                    } catch (e) {
                        this.preparando = false;
                        limpiar();
                    }
                },
            };
        }

        function productsCrud() {
            return {
                open: false,
                /*
                 * El conteo de existencia, aparte del formulario del producto.
                 *
                 * Son dos operaciones distintas y con permisos distintos: editar el producto exige
                 * `products.manage` y mover existencias exige `stock.adjust`. Mezclarlas en un solo
                 * formulario dejaría a quien solo tiene uno de los dos permisos con un botón que
                 * falla a medias.
                 */
                conteo: { abierto: false, id: '', nombre: '', actual: '0', contado: '', nota: '' },

                contar(producto) {
                    this.conteo = {
                        abierto: true,
                        id: producto.id,
                        nombre: producto.nombre,
                        actual: producto.actual,
                        // Se precarga con lo que dice el sistema: casi siempre se cuenta para
                        // confirmar, y así solo hay que teclear cuando NO cuadra.
                        contado: producto.actual,
                        nota: '',
                    };
                },

                row: { id: '', sku: '', name: '', barcode: '', category_id: '', unit: '', cost: '', price: '', track_stock: true, tracks_serials: false,
                       part_number: '', brand: '', vehicle_make: '', vehicle_model: '', year_from: '', year_to: '', location: '' },
                get editUrl() { return '{{ url('panel/inventario') }}/' + this.row.id; },
                edit(data) { this.row = { ...data }; this.open = true; },

                /* ---- Ficha de solo lectura ---- */
                verAbierto: false,
                viendo: { sku: '', name: '', barcode: '', category: '', unit: '', cost: '', price: '', track_stock: true, is_active: true, stock: [] },
                ver(data) { this.viendo = { ...data }; this.verAbierto = true; },

                /* ---- Selección múltiple para borrar en lote ---- */

                marcados: [],
                // «todos» abarca lo que coincide con la búsqueda, no solo la página a la vista.
                todos: false,
                enPagina: @js($products->pluck('id')->all()),
                totalCoincidencias: {{ $products->total() }},

                paginaCompleta() {
                    return this.enPagina.length > 0 && this.enPagina.every((id) => this.marcados.includes(id));
                },

                alternarPagina(marcar) {
                    this.marcados = marcar ? [...this.enPagina] : [];
                    if (!marcar) this.todos = false;
                },

                cuantos() {
                    return this.todos ? this.totalCoincidencias : this.marcados.length;
                },

                etiquetaSeleccion() {
                    const n = this.cuantos();
                    return n === 1 ? '1 producto seleccionado' : `${n} productos seleccionados`;
                },

                async confirmarBorrado() {
                    // Al abarcar TODO lo que coincide se pide teclear la cifra. Marcar unos pocos es
                    // un acto deliberado; «seleccionar los 500» es un clic, y conviene que quien lo
                    // pulsa haya leído cuántos se lleva por delante.
                    await window.confirmarBorrarProductos({
                        cantidad: this.cuantos(),
                        exigirCifra: this.todos,
                        formulario: 'borrar_productos',
                    });
                },
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
                            tracks_serials: {{ old('tracks_serials', 0) ? 'true' : 'false' }},
                        };
                        this.open = true;
                    @endif
                },
            };
        }
    </script>
</x-layouts.admin>
