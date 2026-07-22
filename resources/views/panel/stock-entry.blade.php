{{--
    Entrada de mercancía.

    El flujo está pensado para hacerse con una mano y sin ratón: el foco vive en el campo del
    código, el lector dispara, aparece el producto, se confirma la cantidad y el foco vuelve al
    código listo para el siguiente bulto. Teclear el código a mano funciona igual: el lector no es
    más que un teclado muy rápido.
--}}
<x-layouts.admin title="Entrada de mercancía" heading="Entrada de mercancía"
                 subheading="Escanea o teclea el código para sumar existencia al almacén">

    <div x-data="stockEntry('{{ route('panel.products.lookup') }}')"
         @codigo-escaneado="barcode = $event.detail.codigo; scan()"
         class="grid grid-cols-1 gap-5 lg:grid-cols-3">

        <div class="lg:col-span-2">
            {{-- El acuse de éxito/error lo muestra el toast global del layout. --}}
            <div class="bmos-card bmos-card-pad">
                <label class="bmos-field-label" for="entry-scan">Código del producto</label>
                <input id="entry-scan" type="text" x-ref="scanInput" x-model="barcode"
                       @keydown.enter.prevent="scan()"
                       autofocus autocomplete="off"
                       placeholder="Pasa el lector por el código y pulsa Enter"
                       class="bmos-input font-mono">

                {{-- Código desconocido: se ofrece darlo de alta sin salir de aquí, con el código ya
                     puesto. Solo a quien pueda gestionar productos. --}}
                <div x-show="notFound" x-cloak
                     class="mt-3 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800">
                    <p>Código no encontrado: <span class="font-mono font-semibold" x-text="unknownCode"></span></p>
                    @can('products.manage')
                        <button type="button" @click="openCreate()" class="bmos-btn bmos-btn-primary mt-2">
                            Crear producto con este código
                        </button>
                    @endcan
                </div>

                <p x-show="scanError" x-cloak x-text="scanError"
                   class="mt-3 rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700"></p>

                {{-- Inventariar a pie de estantería es justo donde la cámara sí compensa: sin
                     mostrador, sin cola, y con tiempo para enfocar. --}}
                <x-panel.camera-scanner />

                {{-- Producto resuelto: se confirma cantidad y almacén. --}}
                <form method="POST" action="{{ route('panel.stock.store') }}" x-show="product" x-cloak class="mt-4 space-y-3">
                    @csrf
                    <input type="hidden" name="product_id" :value="product?.id">

                    <div class="rounded-lg border border-slate-200 bg-slate-50 p-3">
                        <p class="font-semibold text-slate-800" x-text="product?.name"></p>
                        <p class="text-xs text-slate-500">
                            <span x-text="product?.sku"></span>
                            · Existencia actual: <span class="font-semibold" x-text="product?.stock"></span>
                        </p>
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="bmos-field-label">Cantidad a ingresar</label>
                            <input type="number" name="quantity" step="0.001" min="0.001" x-model="quantity"
                                   class="bmos-input" required>
                        </div>
                        <div>
                            <label class="bmos-field-label">Almacén</label>
                            <select name="warehouse_id" class="bmos-input" required>
                                @foreach ($warehouses as $warehouse)
                                    <option value="{{ $warehouse->id }}" @selected($warehouse->is_default)>
                                        {{ $warehouse->name }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    <div>
                        <label class="bmos-field-label">Nota (opcional)</label>
                        <input type="text" name="notes" class="bmos-input" maxlength="255"
                               placeholder="Factura del proveedor, referencia...">
                    </div>

                    <div class="flex justify-end gap-2">
                        <button type="button" @click="reset()" class="bmos-btn bmos-btn-ghost">Cancelar</button>
                        <button type="submit" class="bmos-btn bmos-btn-primary">Registrar entrada</button>
                    </div>
                </form>
            </div>
        </div>

        {{-- Acuse de recibo: lo último que entró, con su saldo. --}}
        <div>
            <div class="bmos-card overflow-hidden">
                <div class="border-b border-slate-100 p-4">
                    <p class="font-semibold text-slate-800">Movimientos recientes</p>
                </div>
                <div class="divide-y divide-slate-100">
                    @forelse ($movements as $movement)
                        <div class="flex items-baseline justify-between gap-3 px-4 py-3">
                            <div class="min-w-0">
                                <p class="truncate text-sm font-medium text-slate-800">
                                    {{ $movement->product?->name ?? '—' }}
                                </p>
                                <p class="text-xs text-slate-500">
                                    {{ $movement->type->label() }} · {{ $movement->created_at?->format('d/m H:i') }}
                                </p>
                            </div>
                            <span class="shrink-0 text-sm font-semibold {{ str_starts_with((string) $movement->quantity, '-') ? 'text-rose-600' : 'text-emerald-600' }}">
                                {{ number_format((float) $movement->quantity, 0) }}
                            </span>
                        </div>
                    @empty
                        <p class="bmos-empty px-4 py-6 text-sm">Todavía no hay movimientos.</p>
                    @endforelse
                </div>
            </div>
        </div>

        {{-- Alta rápida del producto desconocido. Apunta al alta que ya existe: cero backend nuevo.
             El stock inicial se registra en la misma transacción y el kardex lo anota como
             «Inventario inicial», que es exactamente lo que es. --}}
        @can('products.manage')
            <div x-show="creating" x-cloak class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-900/50 p-4 py-10"
                 @keydown.escape.window="creating = false">
                <div @click.outside="creating = false" x-transition class="w-full max-w-lg rounded-2xl bg-white p-6 shadow-2xl">
                    <div class="mb-4 flex items-center justify-between">
                        <h3 class="text-lg font-semibold text-slate-800">Nuevo producto</h3>
                        <button type="button" @click="creating = false" class="text-slate-400 hover:text-slate-600">✕</button>
                    </div>

                    <form method="POST" action="{{ route('panel.products.store') }}" class="space-y-3">
                        @csrf
                        <input type="hidden" name="barcode" :value="unknownCode">
                        <input type="hidden" name="initial_stock" :value="quantity">

                        <div class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-600">
                            Código: <span class="font-mono font-semibold" x-text="unknownCode"></span>
                            · Entrará con <span class="font-semibold" x-text="quantity"></span> de existencia inicial.
                        </div>

                        <div><label class="bmos-field-label">SKU</label><input name="sku" class="bmos-input" required></div>
                        <div><label class="bmos-field-label">Nombre</label><input name="name" class="bmos-input" required></div>
                        <div>
                            <label class="bmos-field-label">Categoría</label>
                            <select name="category_id" class="bmos-input">
                                <option value="">— Sin categoría —</option>
                                @foreach ($categories as $cat)
                                    <option value="{{ $cat->id }}">{{ $cat->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <div><label class="bmos-field-label">Costo</label><input name="cost" type="number" step="0.01" value="0" class="bmos-input"></div>
                            <div><label class="bmos-field-label">Precio</label><input name="price" type="number" step="0.01" value="0" class="bmos-input"></div>
                        </div>

                        <div class="flex justify-end gap-2 pt-3">
                            <button type="button" @click="creating = false" class="bmos-btn bmos-btn-ghost">Cancelar</button>
                            <button type="submit" class="bmos-btn bmos-btn-primary">Crear y dar entrada</button>
                        </div>
                    </form>
                </div>
            </div>
        @endcan
    </div>

    <script>
        function stockEntry(lookupUrl) {
            return {
                barcode: '', quantity: 1, product: null,
                scanError: '', notFound: false, unknownCode: '', creating: false, busy: false,

                async scan() {
                    const code = this.barcode.trim();
                    if (!code || this.busy) return;

                    this.busy = true;
                    this.scanError = '';
                    this.notFound = false;

                    try {
                        const res = await fetch(lookupUrl + '?codigo=' + encodeURIComponent(code), {
                            headers: { Accept: 'application/json' },
                        });

                        if (!res.ok) {
                            this.scanError = 'No se pudo consultar el código. Recarga la página.';
                            return;
                        }

                        const data = await res.json();

                        if (data.found) {
                            // Aquí no se mira «sellable»: un producto inactivo o agotado puede (y
                            // suele) recibir mercancía; es justo lo que lo devuelve a la venta.
                            this.product = data.product;
                        } else {
                            this.product = null;
                            this.notFound = true;
                            this.unknownCode = code;
                        }
                    } catch {
                        this.scanError = 'Sin conexión con el servidor. Inténtalo de nuevo.';
                    } finally {
                        this.barcode = '';
                        this.busy = false;
                        this.$refs.scanInput.focus();
                    }
                },

                openCreate() { this.creating = true; },

                reset() {
                    this.product = null;
                    this.notFound = false;
                    this.quantity = 1;
                    this.$refs.scanInput.focus();
                },
            };
        }
    </script>
</x-layouts.admin>
