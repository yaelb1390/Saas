<x-layouts.admin title="Punto de Venta" heading="Punto de Venta" subheading="Arma el ticket, cobra y descuenta stock en tiempo real">
    @php $opt = $posConfig['options']; @endphp

    <div class="mb-4 flex items-center justify-end gap-2 text-sm">
        <span class="text-slate-400">Modo:</span>
        <span class="bmos-badge badge-violet">{{ \App\Modules\POS\Support\PosProfile::label($posConfig['profile']) }}</span>
    </div>

    @if (session('pos_ok'))
        <div class="mb-5 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800">
            <span class="flex items-center gap-2">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-5 w-5"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5"/></svg>
                {{ session('pos_ok') }}
            </span>
            @if (session('pos_receipt_id'))
                <a href="{{ route('panel.sales.receipt', session('pos_receipt_id')) }}?print=1" target="_blank" rel="noopener"
                   class="bmos-btn bmos-btn-primary text-xs">🖨️ Imprimir recibo</a>
            @endif
        </div>
    @endif
    @if (session('pos_error'))
        <div class="mb-5 flex items-center gap-2 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-medium text-rose-800">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-5 w-5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z"/></svg>
            {{ session('pos_error') }}
        </div>
    @endif

    @if (! $openSession)
        {{-- Sin caja: abrir --}}
        <div class="mx-auto max-w-md bmos-card bmos-card-pad text-center">
            <span class="mx-auto mb-3 flex h-12 w-12 items-center justify-center rounded-xl bg-indigo-100 text-indigo-600 text-2xl">🔒</span>
            <p class="text-lg font-semibold text-slate-800">Caja cerrada</p>
            <p class="mb-4 text-sm text-slate-500">Abre una caja con su fondo inicial para empezar a vender.</p>
            <form method="POST" action="{{ route('panel.pos.open') }}" class="flex items-end gap-3">
                @csrf
                <div class="flex-1 text-left">
                    <label class="bmos-field-label">Fondo de apertura</label>
                    <input type="number" name="opening_amount" step="0.01" min="0" value="1000" required class="bmos-input">
                </div>
                <button type="submit" class="bmos-btn bmos-btn-primary">Abrir caja</button>
            </form>
        </div>
    @else
        <div x-data="posTerminal('{{ route('panel.pos.lookup') }}', '{{ route('panel.pos.search') }}')"
             @codigo-escaneado="barcode = $event.detail.codigo; scan()">
            {{-- Barra de sesión --}}
            <div class="mb-5 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-slate-200 bg-white px-4 py-3">
                <div class="flex items-center gap-2 text-sm">
                    <span class="bmos-badge badge-green">Caja abierta</span>
                    <span class="text-slate-500">Fondo: <b>{{ money($openSession->opening_amount) }}</b></span>
                    <span class="text-slate-400">· desde {{ $openSession->opened_at?->format('d/m H:i') }}</span>
                </div>
                <div x-data="{ open: false }" class="relative">
                    <button @click="open = !open" class="bmos-btn bmos-btn-ghost text-sm">Cerrar caja</button>
                    <form x-show="open" x-cloak @click.outside="open=false" method="POST" action="{{ route('panel.pos.close') }}"
                          class="absolute right-0 z-20 mt-2 w-64 rounded-xl border border-slate-200 bg-white p-3 shadow-lg">
                        @csrf
                        <label class="bmos-field-label">Efectivo contado (arqueo)</label>
                        <input type="number" name="counted_amount" step="0.01" min="0" required class="bmos-input mb-2">
                        <button type="submit" class="bmos-btn bmos-btn-primary w-full justify-center text-sm">Confirmar cierre</button>
                    </form>
                </div>
            </div>

            <div class="grid grid-cols-1 gap-5 lg:grid-cols-3">
                {{-- Catálogo --}}
                <div class="lg:col-span-2">
                    {{-- Lector de código de barras.

                         El lector de pistola es un teclado: escribe el código en el campo enfocado
                         y pulsa Enter. Por eso no hace falta driver ni librería, solo un campo con
                         el foco. Tecleado a mano funciona idéntico.

                         Va FUERA del formulario de cobro a propósito: dentro, el Enter del lector
                         enviaría el formulario y cobraría la venta a medio armar. --}}
                    <div class="bmos-card bmos-card-pad mb-4">
                        <label class="bmos-field-label" for="pos-scan">Escanear o teclear código</label>
                        <input id="pos-scan" type="text" x-ref="scanInput" x-model="barcode"
                               @keydown.enter.prevent="scan()"
                               autofocus autocomplete="off"
                               placeholder="Pasa el lector por el código y pulsa Enter"
                               class="bmos-input font-mono">

                        <p x-show="scanError" x-cloak x-text="scanError"
                           class="mt-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-700"></p>

                        {{-- La cámara reutiliza el mismo scan(): para el servidor no hay diferencia
                             entre un código leído con pistola, tecleado o visto por la cámara. --}}
                        <x-panel.camera-scanner />
                    </div>

                    {{-- Búsqueda bajo demanda: el catálogo ya NO se carga entero al abrir la caja.
                         El cajero escribe nombre o SKU y el servidor devuelve solo lo que coincide,
                         así el POS es fluido aunque haya miles de productos. --}}
                    <div class="mb-4">
                        <input type="search" x-model="query" @input.debounce.300ms="searchProducts()"
                               placeholder="Busca un producto por nombre o SKU…" autocomplete="off"
                               class="bmos-input">
                    </div>

                    <div class="grid grid-cols-2 gap-4 sm:grid-cols-3">
                        <template x-for="p in results" :key="p.id">
                            <button type="button"
                                    @click="p.sellable && add(p.id, p.name, p.price)"
                                    class="bmos-card bmos-card-pad text-left transition hover:-translate-y-0.5 hover:shadow-md"
                                    :class="!p.sellable ? 'opacity-50 cursor-not-allowed' : ''"
                                    :disabled="!p.sellable">
                                <p class="font-semibold text-slate-800 leading-tight" x-text="p.name"></p>
                                <p class="text-xs text-slate-400 font-mono" x-text="p.sku"></p>
                                <div class="mt-2 flex items-center justify-between">
                                    <span class="text-lg font-bold text-indigo-600" x-text="rd(p.price)"></span>
                                    <span class="bmos-badge" :class="Number(p.stock) < 5 ? 'badge-amber' : 'badge-blue'"
                                          x-text="p.reason === 'no_stock' ? 'Agotado' : (Math.round(Number(p.stock)) + ' u.')"></span>
                                </div>
                            </button>
                        </template>
                    </div>
                    <p x-show="query.trim().length < 2 && !searching" class="py-8 text-center text-sm text-slate-400">
                        Escribe al menos 2 letras para buscar, o pasa el lector por el código.
                    </p>
                    <p x-show="searching" x-cloak class="py-8 text-center text-sm text-slate-400">Buscando…</p>
                    <p x-show="query.trim().length >= 2 && !searching && results.length === 0" x-cloak class="bmos-empty">
                        Sin coincidencias para «<span x-text="query"></span>».
                    </p>
                </div>

                {{-- Ticket --}}
                <div>
                    <form method="POST" action="{{ route('panel.pos.checkout') }}" x-ref="form"
                          @submit="prepare()"
                          class="bmos-card bmos-card-pad">
                        @csrf
                        <input type="hidden" name="cart" x-ref="cartInput">
                        <input type="hidden" name="tip" :value="tipAmount">
                        <input type="hidden" name="discount_total" :value="globalDiscountAmount">
                        <input type="hidden" name="employee_id" :value="attendantId">

                        <p class="mb-3 font-semibold text-slate-800">Ticket</p>

                        <div class="max-h-72 space-y-2 overflow-y-auto">
                            <template x-for="(item, i) in cart" :key="item.id">
                                <div class="rounded-lg bg-slate-50 p-2">
                                    <div class="flex items-center gap-2">
                                        <div class="min-w-0 flex-1">
                                            <p class="truncate text-sm font-medium text-slate-700" x-text="item.name"></p>
                                            <p class="text-xs text-slate-400"><span x-text="rd(item.price)"></span> c/u</p>
                                        </div>
                                        <div class="flex items-center gap-1">
                                            <button type="button" @click="dec(i)" class="h-6 w-6 rounded bg-white text-slate-600 shadow-sm">−</button>
                                            @if ($opt['decimal_qty'])
                                                <input type="number" step="0.001" min="0" x-model.number="item.qty" class="w-14 rounded border-slate-200 px-1 py-0.5 text-center text-sm">
                                            @else
                                                <span class="w-6 text-center text-sm font-semibold" x-text="item.qty"></span>
                                            @endif
                                            <button type="button" @click="inc(i)" class="h-6 w-6 rounded bg-white text-slate-600 shadow-sm">+</button>
                                        </div>
                                        <span class="w-16 text-right text-sm font-semibold" x-text="rd(lineNet(item))"></span>
                                    </div>

                                    {{-- Campos por línea según el perfil del negocio. --}}
                                    @if ($opt['line_discount'] || $opt['line_note'] || $opt['serial'] || $opt['attendant'])
                                        <div class="mt-2 grid grid-cols-2 gap-1.5">
                                            @if ($opt['line_discount'])
                                                <input type="number" step="0.01" min="0" x-model.number="item.discount" placeholder="Descuento" class="rounded border-slate-200 px-2 py-1 text-xs">
                                            @endif
                                            @if ($opt['serial'])
                                                <input type="text" x-model="item.serial" placeholder="Nº serie / IMEI" class="rounded border-slate-200 px-2 py-1 text-xs">
                                            @endif
                                            @if ($opt['line_note'])
                                                <input type="text" x-model="item.note" placeholder="Nota" class="col-span-2 rounded border-slate-200 px-2 py-1 text-xs">
                                            @endif
                                            @if ($opt['attendant'])
                                                <select x-model="item.employeeId" class="col-span-2 rounded border-slate-200 px-2 py-1 text-xs">
                                                    <option value="">— Empleado (línea) —</option>
                                                    @foreach ($employees as $emp)
                                                        <option value="{{ $emp->id }}">{{ $emp->name }}</option>
                                                    @endforeach
                                                </select>
                                            @endif
                                        </div>
                                    @endif
                                </div>
                            </template>
                            <p x-show="cart.length === 0" class="py-6 text-center text-sm text-slate-400">Toca un producto para agregarlo.</p>
                        </div>

                        <div class="mt-3 border-t border-slate-100 pt-3 text-sm">
                            <div class="flex items-center justify-between text-slate-500">
                                <span>Subtotal</span><span x-text="rd(subtotal)"></span>
                            </div>

                            @if ($opt['global_discount'])
                                <div class="mt-2 flex items-center justify-between gap-2">
                                    <label class="text-slate-500">Descuento del ticket</label>
                                    <input type="number" step="0.01" min="0" x-model.number="globalDiscount" placeholder="0.00" class="w-24 rounded border-slate-200 px-2 py-1 text-right text-sm">
                                </div>
                            @endif
                            @if ($opt['tip'])
                                <div class="mt-2 flex items-center justify-between gap-2">
                                    <label class="text-slate-500">Propina</label>
                                    <input type="number" step="0.01" min="0" x-model.number="tip" placeholder="0.00" class="w-24 rounded border-slate-200 px-2 py-1 text-right text-sm">
                                </div>
                            @endif

                            <div class="mt-2 flex items-center justify-between text-lg font-bold text-slate-800">
                                <span>Total</span><span x-text="rd(total)"></span>
                            </div>

                            @if ($opt['attendant'])
                                <label class="bmos-field-label mt-3">Atiende</label>
                                <select x-model="attendant" class="bmos-input">
                                    <option value="">Sin asignar</option>
                                    @foreach ($employees as $emp)
                                        <option value="{{ $emp->id }}">{{ $emp->name }}</option>
                                    @endforeach
                                </select>
                            @endif

                            {{-- Identificar al cliente es opcional a propósito: la venta de mostrador
                                 debe seguir siendo de un toque. --}}
                            <label class="bmos-field-label mt-3">Cliente (opcional)</label>
                            <select name="customer_id" x-model="customerId" class="bmos-input">
                                <option value="">Sin identificar</option>
                                @foreach ($customers as $customerOption)
                                    <option value="{{ $customerOption->id }}">{{ $customerOption->name }}</option>
                                @endforeach
                            </select>
                            <input type="text" name="customer_name" x-model="customer" x-show="!customerId"
                                   placeholder="Consumidor final" class="bmos-input mt-2">

                            <label class="bmos-field-label mt-3">Pago recibido</label>
                            <input type="number" name="paid" step="0.01" min="0" x-model="paid" placeholder="0.00" class="bmos-input">

                            <div class="mt-2 flex items-center justify-between">
                                <span class="text-slate-500">Cambio</span>
                                <span class="font-semibold text-emerald-600" x-text="rd(change)"></span>
                            </div>

                            <label class="mt-3 flex items-center gap-2 text-slate-600">
                                <input type="checkbox" name="invoice" value="1" x-model="invoice" class="h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500">
                                Emitir factura con NCF
                            </label>

                            <button type="submit" :disabled="!canPay"
                                    class="bmos-btn bmos-btn-primary mt-4 w-full justify-center"
                                    :class="!canPay ? 'opacity-50 cursor-not-allowed' : ''">
                                Cobrar <span x-show="cart.length" x-text="'· ' + rd(total)"></span>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <script>
            function posTerminal(lookupUrl, searchUrl) {
                return {
                    cart: [], paid: '', customer: '', customerId: '', invoice: false,
                    barcode: '', scanError: '', busy: false,
                    globalDiscount: '', tip: '', attendant: '',
                    query: '', results: [], searching: false,

                    // Formatea un importe como pesos dominicanos para mostrarlo en el ticket.
                    rd(n) {
                        return 'RD$ ' + (parseFloat(n) || 0).toLocaleString('es-DO', {
                            minimumFractionDigits: 2, maximumFractionDigits: 2,
                        });
                    },

                    /**
                     * Busca productos por nombre o SKU contra el servidor (panel.pos.search) y pinta
                     * las coincidencias. Reemplaza cargar TODO el catálogo: el POS ya no se ralentiza
                     * cuando hay miles de productos, porque solo trae lo que el cajero busca.
                     */
                    async searchProducts() {
                        const q = this.query.trim();
                        if (q.length < 2) { this.results = []; this.searching = false; return; }

                        this.searching = true;
                        try {
                            const res = await fetch(searchUrl + '?q=' + encodeURIComponent(q), {
                                headers: { Accept: 'application/json' },
                            });
                            if (res.ok) {
                                const data = await res.json();
                                this.results = data.results || [];
                            }
                        } catch {
                            // La búsqueda no es crítica como el escaneo: un fallo puntual se reintenta
                            // al seguir escribiendo, sin romper la caja.
                        } finally {
                            this.searching = false;
                        }
                    },

                    /**
                     * Resuelve el código contra el servidor y mete el producto en el ticket.
                     * Reutiliza add(): el lector y los botones del catálogo acaban en el mismo sitio.
                     */
                    async scan() {
                        const code = this.barcode.trim();
                        if (!code || this.busy) return;

                        this.busy = true;
                        this.scanError = '';

                        try {
                            const res = await fetch(lookupUrl + '?codigo=' + encodeURIComponent(code), {
                                headers: { Accept: 'application/json' },
                            });

                            if (!res.ok) {
                                // 403/419: no es que el código no exista, es que la sesión o el
                                // permiso fallaron. Decirlo tal cual evita que el cajero busque
                                // un producto que sí está en el catálogo.
                                this.scanError = 'No se pudo consultar el código. Recarga la página.';
                                return;
                            }

                            const data = await res.json();

                            if (!data.found) {
                                this.scanError = 'Código no encontrado: ' + code;
                            } else if (!data.product.sellable) {
                                this.scanError = data.product.reason === 'no_stock'
                                    ? 'Sin existencia: ' + data.product.name
                                    : 'Producto inactivo: ' + data.product.name;
                            } else {
                                this.add(data.product.id, data.product.name, data.product.price);
                            }
                        } catch {
                            // Un fallo de red puntual no debe romper la caja: se reintenta escaneando.
                            this.scanError = 'Sin conexión con el servidor. Inténtalo de nuevo.';
                        } finally {
                            // Limpiar y recuperar el foco es la mitad del valor: si el foco se pierde,
                            // el siguiente disparo del lector se escribe en el vacío.
                            this.barcode = '';
                            this.busy = false;
                            this.$refs.scanInput.focus();
                        }
                    },

                    add(id, name, price) {
                        const it = this.cart.find(i => i.id === id);
                        if (it) it.qty = this.round(it.qty + 1);
                        else this.cart.push({ id, name, price: parseFloat(price), qty: 1, discount: 0, note: '', serial: '', employeeId: '' });
                    },
                    inc(i) { this.cart[i].qty = this.round((parseFloat(this.cart[i].qty) || 0) + 1); },
                    dec(i) {
                        const q = this.round((parseFloat(this.cart[i].qty) || 0) - 1);
                        if (q >= 1) this.cart[i].qty = q; else this.cart.splice(i, 1);
                    },
                    round(n) { return Math.round(n * 1000) / 1000; },

                    // Importe neto de una línea: (precio × cantidad) − descuento, nunca negativo.
                    lineNet(item) {
                        const gross = (parseFloat(item.price) || 0) * (parseFloat(item.qty) || 0);
                        return Math.max(0, gross - (parseFloat(item.discount) || 0));
                    },
                    get subtotal() { return this.cart.reduce((s, i) => s + this.lineNet(i), 0); },
                    get globalDiscountAmount() { return Math.min(this.subtotal, Math.max(0, parseFloat(this.globalDiscount) || 0)); },
                    get tipAmount() { return Math.max(0, parseFloat(this.tip) || 0); },
                    get attendantId() { return this.attendant || ''; },
                    // El descuento reduce la base; la propina se suma al final (no se grava).
                    get total() { return Math.max(0, this.subtotal - this.globalDiscountAmount) + this.tipAmount; },
                    get change() { const p = parseFloat(this.paid || 0); return Math.max(0, p - this.total); },
                    get canPay() { return this.cart.length > 0 && this.total > 0 && parseFloat(this.paid || 0) >= this.total; },

                    // Serializa el ticket con todos los campos por línea antes de enviar.
                    prepare() {
                        this.$refs.cartInput.value = JSON.stringify(this.cart.map(i => ({
                            id: i.id,
                            qty: i.qty,
                            discount: parseFloat(i.discount) || 0,
                            note: i.note || '',
                            serial: i.serial || '',
                            employee_id: i.employeeId || '',
                        })));
                    },
                };
            }
        </script>
    @endif
</x-layouts.admin>
