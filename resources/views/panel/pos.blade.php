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
        <div x-data="posTerminal('{{ route('panel.pos.lookup') }}', '{{ route('panel.pos.search') }}', '{{ route('panel.pos.catalogo') }}', @js($negocio), @js($openSession?->id))"
             x-init="arrancarSinLinea()"
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
                                    @click="p.sellable && add(p.id, p.name, p.price, p.image)"
                                    class="bmos-card bmos-card-pad text-left transition hover:-translate-y-0.5 hover:shadow-md"
                                    :class="!p.sellable ? 'opacity-50 cursor-not-allowed' : ''"
                                    :disabled="!p.sellable">
                                <div class="mb-2 flex aspect-square items-center justify-center overflow-hidden rounded-lg bg-slate-100">
                                    <template x-if="p.image">
                                        <img :src="p.image" :alt="p.name" loading="lazy" class="h-full w-full object-cover">
                                    </template>
                                    <template x-if="!p.image">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.3" class="h-8 w-8 text-slate-300"><path stroke-linecap="round" stroke-linejoin="round" d="m2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909m-18 3.75h16.5a1.5 1.5 0 0 0 1.5-1.5V6a1.5 1.5 0 0 0-1.5-1.5H3.75A1.5 1.5 0 0 0 2.25 6v12a1.5 1.5 0 0 0 1.5 1.5Zm10.5-11.25h.008v.008h-.008V8.25Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Z"/></svg>
                                    </template>
                                </div>
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

                {{-- Ticket. `data-asis-evitar`: el asistente flotante se aparta de esta columna en
                     vez de taparla. Aquí están el total y el botón de cobrar. --}}
                <div data-asis-evitar>
                    {{-- Sin conexión el formulario NO se manda: se cobra en el propio equipo y se
                         encola. Se decide aquí, en el submit, y no escondiendo el botón, porque el
                         cajero tiene que poder cobrar exactamente igual que siempre. --}}
                    <form method="POST" action="{{ route('panel.pos.checkout') }}" x-ref="form"
                          @submit="prepare(); if (sinLinea.disponible && !navigator.onLine) { $event.preventDefault(); cobrarSinLinea(); }"
                          class="bmos-card bmos-card-pad">
                        @csrf
                        <input type="hidden" name="cart" x-ref="cartInput">
                        <input type="hidden" name="client_uuid" x-ref="uuidInput">
                        <input type="hidden" name="tip" :value="tipAmount">
                        <input type="hidden" name="discount_total" :value="globalDiscountAmount">
                        <input type="hidden" name="employee_id" :value="attendantId">

                        <x-panel.estado-conexion class="mb-3" />

                        <p class="mb-3 font-semibold text-slate-800">Ticket</p>

                        <div class="max-h-72 space-y-2 overflow-y-auto">
                            <template x-for="(item, i) in cart" :key="item.id">
                                <div class="rounded-lg bg-slate-50 p-2">
                                    <div class="flex items-center gap-2">
                                        <template x-if="item.image">
                                            <img :src="item.image" :alt="item.name" class="h-9 w-9 shrink-0 rounded-md object-cover">
                                        </template>
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

                            {{-- Cierre del ticket: mismos controles que la venta rápida. Pago, cambio
                                 y cobrar comparten altura y escala para leerse como un solo bloque. --}}
                            <div class="bmos-pos-cierre mt-3">
                                {{-- Forma de cobro. Solo el efectivo suma al arqueo de la caja; el
                                     servidor decide eso a partir de este valor, no el navegador. --}}
                                <label class="bmos-field-label">Forma de pago</label>
                                <input type="hidden" name="payment_method" :value="method">
                                <div class="grid grid-cols-3 gap-1.5">
                                    @foreach (\App\Modules\Sales\Enums\PaymentMethod::counterOptions() as $option)
                                        <button type="button" @click="method = '{{ $option->value }}'"
                                                :class="method === '{{ $option->value }}' ? 'border-indigo-500 bg-indigo-50 text-indigo-700' : 'border-slate-200 text-slate-500'"
                                                class="min-h-[44px] rounded-lg border text-xs font-semibold transition">
                                            {{ $option->label() }}
                                        </button>
                                    @endforeach
                                </div>

                                <label class="bmos-field-label mt-3"
                                       x-text="method === 'cash' ? 'Pago recibido' : 'Importe cobrado'"></label>
                                <input type="number" name="paid" step="0.01" min="0" inputmode="decimal"
                                       x-model="paid" placeholder="0.00" class="bmos-pos-input-pago">

                                {{-- El cambio solo tiene sentido en efectivo: con tarjeta se cobra el
                                     importe exacto. --}}
                                <div x-show="method === 'cash' && change > 0" x-cloak class="bmos-pos-change">
                                    <span class="bmos-pos-change-label">Cambio</span>
                                    <span class="bmos-pos-change-value" x-text="rd(change)"></span>
                                </div>
                                <button type="button" x-show="method !== 'cash'" @click="paid = total.toFixed(2)"
                                        class="mt-1 text-xs font-semibold text-indigo-600">Poner el importe exacto</button>

                                <label class="mt-3 flex items-center gap-2 text-slate-600">
                                    <input type="checkbox" name="invoice" value="1" x-model="invoice" class="h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500">
                                    Emitir factura con NCF
                                </label>

                                <button type="submit" :disabled="!canPay" class="bmos-pos-cobrar mt-3">
                                    <span>Cobrar</span>
                                    <span class="bmos-pos-cobrar-total" x-text="rd(total)"></span>
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <script>
            function posTerminal(lookupUrl, searchUrl, catalogoUrl, negocio, sesionCaja) {
                return {
                    negocio, sesionCaja,
                    cart: [], paid: '', customer: '', customerId: '', invoice: false, method: 'cash',
                    barcode: '', scanError: '', busy: false,

                    /*
                     * El modo sin internet. Lo pinta el componente panel.estado-conexion —escrito
                     * así, sin las etiquetas: Blade compila una etiqueta de componente aunque esté
                     * dentro de un comentario de JavaScript, y se la traga como un componente sin
                     * cerrar. La pantalla entera deja de compilar por un comentario—.
                     *
                     * `disponible` solo se enciende si el navegador puede guardar de verdad: si no
                     * puede, este terminal no ofrece cobrar a oscuras, porque prometer que la venta
                     * se guarda y perderla es peor que decirlo de entrada.
                     */
                    sinLinea: {
                        disponible: false,
                        conexion: navigator.onLine ? 'en-linea' : 'sin-conexion',
                        pendientes: 0, apartadas: 0, subiendo: false, pideLogin: false,
                        catalogoDe: null,
                    },

                    /** Copia local del catálogo, para buscar y escanear sin línea. */
                    catalogoLocal: [],
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
                            // Sin línea se busca en la copia local: es la diferencia entre poder
                            // cobrar y no poder. Un fallo puntual con conexión se reintenta solo al
                            // seguir escribiendo.
                            this.results = this.buscarEnLocal(q);
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
                                this.add(data.product.id, data.product.name, data.product.price, data.product.image);
                            }
                        } catch {
                            /*
                             * Sin línea, el código se resuelve contra la copia local del catálogo.
                             *
                             * Lo que NO se puede comprobar aquí es la existencia: el stock cambia con
                             * cada venta y la copia es de cuando se descargó. Se añade igual y el
                             * servidor lo marcará al subir la venta, porque negarse a vender lo que
                             * está en el estante es peor que un inventario que hay que revisar.
                             */
                            const local = this.catalogoLocal.find(
                                (prod) => prod.barcode === code || prod.sku === code,
                            );

                            if (local) {
                                this.add(local.id, local.name, local.price, local.image);
                            } else {
                                this.scanError = 'Sin conexión y ese código no está en la copia guardada: ' + code;
                            }
                        } finally {
                            // Limpiar y recuperar el foco es la mitad del valor: si el foco se pierde,
                            // el siguiente disparo del lector se escribe en el vacío.
                            this.barcode = '';
                            this.busy = false;
                            this.$refs.scanInput.focus();
                        }
                    },

                    add(id, name, price, image = null) {
                        const it = this.cart.find(i => i.id === id);
                        if (it) it.qty = this.round(it.qty + 1);
                        else this.cart.push({ id, name, price: parseFloat(price), image, qty: 1, discount: 0, note: '', serial: '', employeeId: '' });
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

                        // La llave de ESTE cobro. Viaja también con conexión: si la respuesta se
                        // pierde por el camino, la venta se puede reintentar sin cobrar dos veces.
                        this.$refs.uuidInput.value = crypto.randomUUID();
                    },

                    // ── Sin internet ──────────────────────────────────────────────────────────

                    async arrancarSinLinea() {
                        const offline = await window.cargarOffline();
                        if (!offline) return;

                        this.sinLinea.disponible = true;
                        offline.cola.alCambiar((estado) => { this.sinLinea = { ...this.sinLinea, ...estado }; });
                        offline.cola.vigilar();

                        await this.cargarCatalogoLocal(offline);
                    },

                    /**
                     * Una copia del catálogo en el equipo, para el día que no haya línea.
                     *
                     * Se pide una vez al abrir la pantalla —que es una vez por turno— y no en cada
                     * venta. Primero se pinta la copia guardada, y luego se refresca si hay red: así
                     * un terminal que arranca ya sin conexión tiene precios desde el primer segundo.
                     */
                    async cargarCatalogoLocal(offline) {
                        const guardado = await offline.almacen.leer(offline.almacen.CATALOGO, 'actual').catch(() => null);

                        if (guardado) {
                            this.catalogoLocal = guardado.results ?? [];
                            this.sinLinea.catalogoDe = guardado.guardado_en ?? null;
                        }

                        if (!navigator.onLine) return;

                        try {
                            const res = await fetch(catalogoUrl, { headers: { Accept: 'application/json' } });
                            if (!res.ok) return;

                            const data = await res.json();
                            this.catalogoLocal = data.results ?? [];
                            this.sinLinea.catalogoDe = Date.now();

                            await offline.almacen.guardar(
                                offline.almacen.CATALOGO,
                                { results: data.results, guardado_en: Date.now() },
                                'actual',
                            );
                        } catch {
                            // Se queda la copia guardada, que para esto es exactamente igual de útil.
                        }
                    },

                    buscarEnLocal(termino) {
                        const t = termino.toLowerCase();

                        return this.catalogoLocal
                            .filter((prod) => (prod.name ?? '').toLowerCase().includes(t)
                                || (prod.sku ?? '').toLowerCase().includes(t))
                            .slice(0, 24);
                    },

                    /** Cuántas horas tiene el catálogo con el que se está cobrando. */
                    get catalogoAntiguedad() {
                        if (!this.sinLinea.catalogoDe) return '';

                        const horas = Math.floor((Date.now() - this.sinLinea.catalogoDe) / 3_600_000);
                        if (horas < 1) return 'precios de hace menos de una hora';
                        if (horas < 24) return `precios de hace ${horas} h`;

                        return `precios de hace ${Math.floor(horas / 24)} día(s)`;
                    },

                    /**
                     * Cobra sin línea en vez de mandar el formulario.
                     *
                     * Se GUARDA primero y solo entonces se limpia el ticket: al revés, un fallo al
                     * guardar borraría la venta de los dos sitios a la vez y no quedaría rastro de lo
                     * que se acaba de cobrar.
                     */
                    async cobrarSinLinea() {
                        const offline = await window.cargarOffline();

                        if (!offline) {
                            this.scanError = 'Este navegador no puede guardar la venta. No la des por cobrada.';
                            return;
                        }

                        const uuid = crypto.randomUUID();

                        const detalle = this.cart.map((i) => ({
                            nombre: i.name,
                            cantidad: i.qty,
                            precio: Number(i.price),
                            importe: Number(i.price) * i.qty - (parseFloat(i.discount) || 0),
                        }));

                        const venta = {
                            uuid,
                            cash_session_id: this.sesionCaja,
                            payment_method: this.method,
                            paid: this.paid || String(this.total),
                            tip: String(this.tipAmount || 0),
                            discount_total: String(this.globalDiscountAmount || 0),
                            customer_name: this.customer || null,
                            customer_id: this.customerId || null,
                            employee_id: this.attendantId || null,
                            lines: this.cart.map((i) => ({
                                product_id: i.id,
                                quantity: String(i.qty),
                                // El precio que se cobra AHORA: es el que va en el recibo del cliente.
                                unit_price: String(i.price),
                                discount: String(parseFloat(i.discount) || 0),
                                note: i.note || null,
                                serial: i.serial || null,
                                employee_id: i.employeeId || null,
                            })),
                        };

                        let guardada;

                        try {
                            guardada = await offline.cola.encolar(venta);
                        } catch {
                            this.scanError = 'No se pudo guardar la venta en este equipo. No la des por cobrada.';
                            return;
                        }

                        // Se imprime lo guardado, no lo que se iba a guardar: el papel y la cola
                        // llevan la misma hora y el mismo contenido.
                        offline.recibo.imprimir(guardada, this.negocio, detalle);

                        this.cart = [];
                        this.paid = '';
                        this.customer = '';
                        this.customerId = '';
                        this.globalDiscount = '';
                        this.tip = '';
                        this.scanError = '';
                    },
                };
            }
        </script>
    @endif
</x-layouts.admin>
