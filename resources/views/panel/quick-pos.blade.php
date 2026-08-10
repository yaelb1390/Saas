{{-- El cajero recibe el layout de kiosco (solo el terminal); el dueño, el panel completo. --}}
@php($layout = $kiosk ? 'layouts.kiosk' : 'layouts.admin')

{{-- `wide`: el terminal aprovecha todo el ancho de la pantalla en vez de quedarse en los 1400px de
     lectura del resto del panel. Cada píxel de más es una ficha de producto más sin desplazar. --}}
<x-dynamic-component :component="$layout" title="Venta rápida" heading="Venta rápida"
                     subheading="Toca los productos para armar el pedido" :wide="true">

    @if (session('pos_ok'))
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800">
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
        <div class="mb-4 flex items-center gap-2 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-medium text-rose-800">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-5 w-5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z"/></svg>
            {{ session('pos_error') }}
        </div>
    @endif

    @if (! $openSession)
        {{-- Sin caja abierta no se vende: mismo criterio y mismo endpoint que el POS de mostrador. --}}
        <div class="mx-auto max-w-md bmos-card bmos-card-pad text-center">
            <span class="mx-auto mb-3 flex h-12 w-12 items-center justify-center rounded-xl bg-indigo-100 text-2xl text-indigo-600">🔒</span>
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
        {{-- Dos columnas a partir de 1024px: catálogo elástico y ticket de ancho fijo, para que
             añadir líneas al pedido no estreche la rejilla de fotos. El `minmax(0,1fr)` es
             obligatorio: con `1fr` a secas, una miniatura ancha puede empujar la columna y
             desbordar la página (misma lección que ya está documentada en `.bmos-stat`). --}}
        <div x-data="quickPos('{{ route('panel.quick-pos.catalog') }}', @js($categories))" x-init="arrancar()"
             class="grid grid-cols-1 gap-4 lg:grid-cols-[10rem_minmax(0,1fr)_21rem] xl:grid-cols-[11.5rem_minmax(0,1fr)_23rem]">

            {{-- ── Categorías ───────────────────────────────────────────────────────────── --}}
            {{-- Columna lateral en pantalla grande; en móvil se convierte en una fila que se
                 desplaza, porque una columna vertical robaría el ancho que necesita la rejilla. --}}
            {{-- Se pinta desde el estado de Alpine, sembrado con los datos del servidor: así aparece
                 al instante en la primera carga y luego se actualiza sola cuando el inventario
                 cambia, sin recargar la página. --}}
            <nav class="bmos-pos-cats" aria-label="Categorías">
                <button type="button" @click="pick(null)" :aria-pressed="category === null"
                        :class="category === null && 'is-active'" class="bmos-pos-cat">
                    <span class="bmos-pos-cat-icon">🗂️</span>
                    <span class="bmos-pos-cat-name">Todo</span>
                </button>

                <template x-for="c in cats" :key="c.id">
                    <button type="button" @click="pick(c.id)" :aria-pressed="category === c.id"
                            :class="category === c.id && 'is-active'" class="bmos-pos-cat">
                        <span class="bmos-pos-cat-icon" x-text="c.icon"></span>
                        <span class="bmos-pos-cat-name" x-text="c.name"></span>
                    </button>
                </template>
            </nav>

            {{-- ── Catálogo ─────────────────────────────────────────────────────────────── --}}
            <div class="bmos-card flex min-w-0 flex-col overflow-hidden">
                <div class="border-b border-slate-100 p-3">
                    <input type="search" x-model="query" placeholder="Buscar en el catálogo..."
                           class="bmos-input" aria-label="Buscar producto">
                </div>

                <div class="min-h-[18rem] flex-1 overflow-y-auto p-3">
                    <template x-if="loading">
                        <p class="py-10 text-center text-sm text-slate-400">Cargando catálogo...</p>
                    </template>

                    <template x-if="!loading && visible.length === 0">
                        <p class="py-10 text-center text-sm text-slate-400">
                            No hay productos que mostrar aquí.
                        </p>
                    </template>

                    <div class="bmos-pos-grid">
                        <template x-for="p in visible" :key="p.id">
                            <button type="button" @click="add(p)" :disabled="!p.sellable" class="bmos-pos-tile">
                                <span class="bmos-pos-tile-img">
                                    <template x-if="p.image">
                                        <img :src="p.image" :alt="p.name" loading="lazy">
                                    </template>
                                    <template x-if="!p.image">
                                        <span class="grid h-full w-full place-items-center text-slate-300">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2" class="h-9 w-9"><path stroke-linecap="round" stroke-linejoin="round" d="m2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909M3.75 19.5h16.5a1.5 1.5 0 0 0 1.5-1.5V6a1.5 1.5 0 0 0-1.5-1.5H3.75A1.5 1.5 0 0 0 2.25 6v12a1.5 1.5 0 0 0 1.5 1.5Z"/></svg>
                                        </span>
                                    </template>
                                    <span x-show="!p.sellable" class="bmos-pos-tile-flag">Agotado</span>
                                </span>
                                <span class="bmos-pos-tile-body">
                                    <span class="bmos-pos-tile-name" x-text="p.name"></span>
                                    <span class="flex items-center justify-between gap-1">
                                        <span class="bmos-pos-tile-price" x-text="rd(p.price)"></span>
                                        {{-- Avisa de que al tocarlo se preguntará tamaño o sabor. --}}
                                        <span x-show="p.has_options" class="text-[11px] font-semibold text-slate-400">elegir</span>
                                    </span>
                                </span>
                            </button>
                        </template>
                    </div>

                    <p x-show="hasMore && !query" class="pt-4 text-center text-xs text-slate-400">
                        Se muestran los primeros 60 productos de esta categoría. Usa el buscador o los chips para acotar.
                    </p>
                </div>
            </div>

            {{-- ── Ticket ───────────────────────────────────────────────────────────────── --}}
            <div class="bmos-card flex min-w-0 flex-col overflow-hidden lg:sticky lg:top-20 lg:max-h-[calc(100vh-6rem)]">
                <div class="flex items-center justify-between gap-2 border-b border-slate-100 p-3">
                    <p class="font-semibold text-slate-800">Pedido</p>
                    <button type="button" @click="clear()" x-show="cart.length > 0"
                            class="bmos-btn bmos-btn-ghost min-h-[44px] text-xs">Vaciar</button>
                </div>

                <div class="min-h-[8rem] flex-1 overflow-y-auto p-3">
                    <p x-show="cart.length === 0" class="py-8 text-center text-sm text-slate-400">
                        Toca un producto para empezar.
                    </p>

                    <template x-for="(item, i) in cart" :key="item.id">
                        <div class="mb-2 rounded-lg bg-slate-50 p-2">
                            <div class="flex items-start gap-2">
                                {{-- Misma regla que en la rejilla: la miniatura muestra el producto
                                     entero, no un recorte centrado. --}}
                                <span class="h-10 w-10 shrink-0 overflow-hidden rounded bg-white p-0.5">
                                    <template x-if="item.image">
                                        <img :src="item.image" alt="" class="h-full w-full object-contain">
                                    </template>
                                </span>
                                <div class="min-w-0 flex-1">
                                    <p class="truncate text-sm font-medium text-slate-700" x-text="item.name"></p>
                                    {{-- Tamaño y sabores elegidos: el cajero tiene que poder
                                         comprobar de un vistazo qué está cobrando. --}}
                                    <p x-show="item.opciones?.length" class="truncate text-xs text-indigo-500"
                                       x-text="item.opciones?.map((o) => o.name).join(' · ')"></p>
                                    <p class="text-xs text-slate-400" x-text="rd(item.price) + ' c/u'"></p>
                                </div>
                                <button type="button" @click="remove(i)" aria-label="Quitar del pedido"
                                        class="grid h-11 w-11 shrink-0 place-items-center rounded text-slate-300 transition hover:bg-rose-50 hover:text-rose-500">
                                    ✕
                                </button>
                            </div>
                            <div class="mt-2 flex items-center justify-between gap-2">
                                {{-- Objetivos de 44px: esta pantalla se usa con el dedo en tablet. --}}
                                <div class="flex items-center gap-1">
                                    <button type="button" @click="dec(i)" aria-label="Quitar una unidad"
                                            class="h-11 w-11 rounded-lg bg-white text-lg font-bold text-slate-600 shadow-sm transition active:scale-95">−</button>
                                    <span class="w-9 text-center text-base font-semibold tabular-nums" x-text="item.qty"></span>
                                    <button type="button" @click="inc(i)" aria-label="Añadir una unidad"
                                            class="h-11 w-11 rounded-lg bg-white text-lg font-bold text-slate-600 shadow-sm transition active:scale-95">+</button>
                                </div>
                                <span class="text-sm font-bold text-slate-700" x-text="rd(lineNet(item))"></span>
                            </div>
                        </div>
                    </template>
                </div>

                {{-- El cobro va por fetch, no por envío de formulario: recargar la página sacaría al
                     terminal del modo pantalla completa en cada venta. --}}
                <form @submit.prevent="cobrar()" class="border-t border-slate-100 p-3">
                    @csrf

                    {{-- Acuse del último cobro, sin recargar. --}}
                    <div x-show="ultimaVenta" x-cloak
                         class="mb-3 rounded-lg border border-emerald-200 bg-emerald-50 p-2 text-sm text-emerald-800">
                        <p class="font-semibold" x-text="ultimaVenta?.message"></p>
                        <a :href="ultimaVenta?.receipt_url" target="_blank" rel="noopener"
                           class="mt-1 inline-block text-xs font-semibold text-emerald-700 underline">🖨️ Imprimir recibo</a>
                    </div>
                    <div x-show="errorCobro" x-cloak
                         class="mb-3 rounded-lg border border-rose-200 bg-rose-50 p-2 text-sm font-medium text-rose-700"
                         x-text="errorCobro"></div>

                    <div class="mb-2 flex items-center justify-between text-sm">
                        <span class="text-slate-500">Subtotal <span class="text-slate-400" x-text="'(' + count + ')'"></span></span>
                        <span class="font-semibold text-slate-700" x-text="rd(subtotal)"></span>
                    </div>
                    <div class="mb-3 flex items-center justify-between text-xs text-slate-400">
                        <span>ITBIS incluido en el precio</span>
                    </div>

                    {{-- Forma de cobro. Solo el efectivo suma al arqueo de la caja; el servidor
                         decide eso a partir de este valor, no el navegador. --}}
                    <div class="mb-3">
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
                    </div>

                    {{-- Pago, cambio y cobrar comparten escala: se leen como un solo bloque. --}}
                    <div class="bmos-pos-cierre mb-3">
                        <label class="bmos-field-label" x-text="method === 'cash' ? 'Pago recibido' : 'Importe cobrado'"></label>
                        <input type="number" name="paid" x-model="paid" step="0.01" min="0" inputmode="decimal"
                               class="bmos-pos-input-pago" placeholder="0.00">
                        {{-- El cambio solo tiene sentido en efectivo: con tarjeta se cobra el importe exacto. --}}
                        <div x-show="method === 'cash' && change > 0" x-cloak class="bmos-pos-change">
                            <span class="bmos-pos-change-label">Cambio</span>
                            <span class="bmos-pos-change-value" x-text="rd(change)"></span>
                        </div>
                        <button type="button" x-show="method !== 'cash'" @click="paid = subtotal.toFixed(2)"
                                class="mt-1 text-xs font-semibold text-indigo-600">Poner el importe exacto</button>

                        {{-- `cobrando` bloquea el botón mientras vuela la petición: sin esto, un
                             doble toque impaciente cobraría la venta dos veces. --}}
                        <button type="submit" :disabled="!canPay || cobrando" class="bmos-pos-cobrar mt-3">
                            <span x-text="cobrando ? 'Cobrando...' : 'Cobrar'"></span>
                            <span class="bmos-pos-cobrar-total" x-text="rd(subtotal)"></span>
                        </button>
                    </div>
                </form>
            </div>
        </div>

        {{-- Paso de elección: aparece al tocar un producto que ofrece tamaño, sabor o extras. --}}
        <div x-show="eligiendo" x-cloak @keydown.escape.window="cerrarOpciones()"
             class="fixed inset-0 z-50 flex items-end justify-center bg-slate-900/50 p-0 sm:items-center sm:p-4">
            <div @click.outside="cerrarOpciones()"
                 class="flex max-h-[85vh] w-full flex-col rounded-t-2xl bg-white sm:max-w-md sm:rounded-2xl">
                <div class="flex items-center justify-between gap-3 border-b border-slate-100 p-4">
                    <div class="min-w-0">
                        <p class="truncate font-semibold text-slate-800" x-text="eligiendo?.name"></p>
                        <p class="text-sm text-slate-400" x-text="rd(precioElegido)"></p>
                    </div>
                    <button type="button" @click="cerrarOpciones()"
                            class="grid h-11 w-11 shrink-0 place-items-center rounded-lg text-slate-400 hover:bg-slate-100">✕</button>
                </div>

                <div class="min-h-0 flex-1 overflow-y-auto p-4">
                    <p x-show="cargandoOpciones" class="py-6 text-center text-sm text-slate-400">Cargando opciones...</p>

                    <template x-for="g in grupos" :key="g.id">
                        <div class="mb-4">
                            <div class="mb-2 flex items-baseline justify-between gap-2">
                                <p class="font-semibold text-slate-700" x-text="g.name"></p>
                                <p class="text-xs text-slate-400" x-text="pistaGrupo(g)"></p>
                            </div>
                            <div class="grid grid-cols-2 gap-2">
                                <template x-for="o in g.options" :key="o.id">
                                    <button type="button" @click="alternarOpcion(g, o)"
                                            :class="estaElegida(o.id) ? 'border-indigo-500 bg-indigo-50 text-indigo-700' : 'border-slate-200 text-slate-600'"
                                            class="min-h-[52px] rounded-lg border px-3 text-left text-sm font-medium transition">
                                        <span class="block" x-text="o.name"></span>
                                        <span x-show="Number(o.price_delta) !== 0" class="block text-xs font-semibold text-slate-400"
                                              x-text="(Number(o.price_delta) > 0 ? '+' : '') + rd(o.price_delta)"></span>
                                    </button>
                                </template>
                            </div>
                        </div>
                    </template>
                </div>

                <div class="border-t border-slate-100 p-4">
                    <p x-show="faltaElegir" x-cloak class="mb-2 text-xs font-medium text-amber-600"
                       x-text="faltaElegir"></p>
                    <button type="button" @click="confirmarOpciones()" :disabled="!!faltaElegir"
                            class="bmos-btn bmos-btn-primary min-h-[52px] w-full justify-between text-base disabled:opacity-50">
                        <span>Añadir al pedido</span>
                        <span x-text="rd(precioElegido)"></span>
                    </button>
                </div>
            </div>
        </div>

        <script>
            function quickPos(catalogUrl, categoriasIniciales) {
                return {
                    catalogUrl,
                    cats: categoriasIniciales,
                    // `all` guarda lo ya traído del servidor; `visible` es lo que se pinta tras
                    // aplicar chip y buscador. Separarlos evita volver a la red al escribir.
                    all: [],
                    loaded: new Set(),
                    category: null,
                    query: '',
                    loading: false,
                    hasMore: false,
                    cart: [],
                    paid: '',
                    method: 'cash',
                    cobrando: false,
                    ultimaVenta: null,
                    errorCobro: '',
                    eligiendo: null,
                    grupos: [],
                    elegidas: [],
                    cargandoOpciones: false,
                    checkoutUrl: '{{ route('panel.pos.checkout') }}',

                    rd(n) {
                        return 'RD$ ' + Number(n || 0).toLocaleString('es-DO', {
                            minimumFractionDigits: 2, maximumFractionDigits: 2,
                        });
                    },

                    get visible() {
                        const q = this.query.trim().toLowerCase();

                        return this.all.filter((p) => {
                            const okCat = this.category === null || p.category_id === this.category;
                            const okQuery = q === '' || p.name.toLowerCase().includes(q);

                            return okCat && okQuery;
                        });
                    },

                    /**
                     * Arranque del terminal.
                     *
                     * Además de la primera carga, deja el catálogo al día sin recargar la página:
                     * un terminal en modo kiosco puede quedarse abierto toda la jornada, y sin esto
                     * no vería los productos que se den de alta durante el turno.
                     */
                    arrancar() {
                        this.load();

                        // Al volver a la pestaña: es el momento típico tras dar de alta un producto
                        // en otra ventana, y no cuesta nada mientras el terminal está en segundo plano.
                        this._alVolver = () => {
                            if (document.visibilityState === 'visible') this.refrescar();
                        };
                        document.addEventListener('visibilitychange', this._alVolver);

                        // Y un repaso periódico, para el terminal que nunca pierde el foco.
                        this._reloj = setInterval(() => this.refrescar(), 3 * 60 * 1000);
                    },

                    destroy() {
                        document.removeEventListener('visibilitychange', this._alVolver);
                        clearInterval(this._reloj);
                    },

                    /**
                     * Vuelve a pedir catálogo y categorías.
                     *
                     * NO toca el carrito: el cajero puede estar a medio pedido y perder sus líneas
                     * por un refresco de fondo sería inaceptable.
                     */
                    async refrescar() {
                        if (this.cobrando) return; // no interrumpir un cobro en vuelo

                        this.all = [];
                        this.loaded.clear();
                        await this.load(this.category);

                        // Si la categoría abierta se quedó sin productos, deja de existir: se vuelve
                        // a «Todo» en vez de dejar la rejilla vacía sin explicación.
                        if (this.category !== null && !this.cats.some((c) => c.id === this.category)) {
                            this.pick(null);
                        }
                    },

                    async load(categoryId = null) {
                        // Cada categoría se pide una sola vez por sesión de pantalla.
                        const key = categoryId ?? 'all';
                        if (this.loaded.has(key)) return;

                        this.loading = true;
                        try {
                            const url = new URL(this.catalogUrl, window.location.origin);
                            if (categoryId !== null) url.searchParams.set('category', categoryId);

                            const res = await fetch(url, { headers: { Accept: 'application/json' } });
                            if (!res.ok) return;

                            const data = await res.json();
                            const known = new Set(this.all.map((p) => p.id));
                            this.all.push(...data.results.filter((p) => !known.has(p.id)));
                            this.hasMore = data.has_more;
                            this.loaded.add(key);

                            // La barra viaja con el catálogo: crece y se encoge al ritmo del
                            // inventario sin pedir nada aparte.
                            if (data.categories) this.cats = data.categories;
                        } catch (e) {
                            // Sin conexión: se conserva lo ya cargado en vez de vaciar la rejilla.
                        } finally {
                            this.loading = false;
                        }
                    },

                    pick(categoryId) {
                        this.category = categoryId;
                        this.load(categoryId);
                    },

                    add(p) {
                        if (!p.sellable) return;

                        // Con opciones hay que preguntar antes: el tamaño cambia el precio.
                        if (p.has_options) {
                            this.abrirOpciones(p);
                            return;
                        }

                        this.agregarLinea(p, []);
                    },

                    /**
                     * Añade la línea. Dos unidades del mismo producto solo se agrupan si llevan las
                     * MISMAS opciones: un cono de chocolate y uno de fresa son líneas distintas
                     * aunque sean el mismo producto.
                     */
                    agregarLinea(p, opciones) {
                        const firma = opciones.map((o) => o.id).sort().join(',');
                        const line = this.cart.find((i) => i.id === p.id && i.firma === firma);

                        if (line) {
                            line.qty++;
                            return;
                        }

                        const extra = opciones.reduce((n, o) => n + Number(o.price_delta || 0), 0);

                        this.cart.push({
                            id: p.id,
                            name: p.name,
                            price: Math.max(0, Number(p.price) + extra),
                            image: p.image,
                            qty: 1,
                            firma,
                            opciones,
                        });
                    },

                    async abrirOpciones(p) {
                        this.eligiendo = p;
                        this.grupos = [];
                        this.elegidas = [];
                        this.cargandoOpciones = true;

                        try {
                            const url = '{{ url('panel/pos-rapido/producto') }}/' + p.id + '/opciones';
                            const res = await fetch(url, { headers: { Accept: 'application/json' } });

                            if (!res.ok) { this.cerrarOpciones(); return; }

                            this.grupos = (await res.json()).groups;

                            // Preselecciona la primera opción de los grupos obligatorios de una
                            // sola elección: es lo que el cajero elegiría el 90% de las veces.
                            this.grupos.forEach((g) => {
                                if (!g.multiple && g.min > 0 && g.options.length > 0) {
                                    this.elegidas.push({ ...g.options[0], grupo: g.id });
                                }
                            });
                        } catch (e) {
                            this.cerrarOpciones();
                        } finally {
                            this.cargandoOpciones = false;
                        }
                    },

                    cerrarOpciones() {
                        this.eligiendo = null;
                        this.grupos = [];
                        this.elegidas = [];
                    },

                    estaElegida(id) {
                        return this.elegidas.some((o) => o.id === id);
                    },

                    alternarOpcion(g, o) {
                        if (this.estaElegida(o.id)) {
                            this.elegidas = this.elegidas.filter((x) => x.id !== o.id);
                            return;
                        }

                        if (!g.multiple) {
                            // Selección única: la nueva reemplaza a la de su grupo.
                            this.elegidas = this.elegidas.filter((x) => x.grupo !== g.id);
                        } else if (g.max && this.elegidas.filter((x) => x.grupo === g.id).length >= g.max) {
                            return; // tope alcanzado
                        }

                        this.elegidas.push({ ...o, grupo: g.id });
                    },

                    pistaGrupo(g) {
                        if (!g.multiple) return g.min > 0 ? 'Obligatorio' : 'Opcional';
                        if (g.max) return `Hasta ${g.max}`;
                        return g.min > 0 ? `Mínimo ${g.min}` : 'Opcional';
                    },

                    /** Mensaje de lo que falta, o cadena vacía si ya se puede añadir. */
                    get faltaElegir() {
                        for (const g of this.grupos) {
                            const n = this.elegidas.filter((o) => o.grupo === g.id).length;
                            if (n < g.min) {
                                return `Elige ${g.min === 1 ? 'una opción' : g.min + ' opciones'} en «${g.name}».`;
                            }
                        }
                        return '';
                    },

                    get precioElegido() {
                        if (!this.eligiendo) return 0;
                        const extra = this.elegidas.reduce((n, o) => n + Number(o.price_delta || 0), 0);
                        return Math.max(0, Number(this.eligiendo.price) + extra);
                    },

                    confirmarOpciones() {
                        if (this.faltaElegir) return;

                        this.agregarLinea(this.eligiendo, this.elegidas.map((o) => ({
                            id: o.id, name: o.name, price_delta: o.price_delta,
                        })));

                        this.cerrarOpciones();
                    },

                    inc(i) { this.cart[i].qty++; },
                    dec(i) { if (--this.cart[i].qty <= 0) this.cart.splice(i, 1); },
                    remove(i) { this.cart.splice(i, 1); },
                    clear() { this.cart = []; this.paid = ''; this.method = 'cash'; },

                    lineNet(item) { return Number(item.price) * item.qty; },

                    get count() { return this.cart.reduce((n, i) => n + i.qty, 0); },
                    get subtotal() { return this.cart.reduce((n, i) => n + this.lineNet(i), 0); },
                    get change() { return Math.max(0, Number(this.paid || 0) - this.subtotal); },
                    get canPay() {
                        return this.cart.length > 0 && Number(this.paid || 0) >= this.subtotal;
                    },

                    async cobrar() {
                        if (!this.canPay || this.cobrando) return;

                        this.cobrando = true;
                        this.errorCobro = '';

                        try {
                            const res = await fetch(this.checkoutUrl, {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json',
                                    // Sin este Accept el servidor devolvería la redirección de
                                    // siempre en vez del JSON.
                                    Accept: 'application/json',
                                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                                },
                                body: JSON.stringify({
                                    // Solo id, cantidad y qué opciones se eligieron: los precios
                                    // (el del producto y el recargo de cada opción) los pone el
                                    // servidor releyéndolos de la base.
                                    cart: JSON.stringify(this.cart.map((i) => ({
                                        id: i.id,
                                        qty: i.qty,
                                        options: (i.opciones ?? []).map((o) => o.id),
                                    }))),
                                    paid: this.paid,
                                    payment_method: this.method,
                                }),
                            });

                            const data = await res.json().catch(() => ({}));

                            if (!res.ok) {
                                // 419 = la sesión caducó; recargar es lo único que lo arregla.
                                this.errorCobro = res.status === 419
                                    ? 'La sesión expiró. Recarga la página.'
                                    : (data.message ?? 'No se pudo completar el cobro.');
                                return;
                            }

                            this.ultimaVenta = data;
                            this.cart = [];
                            this.paid = '';
                            this.method = 'cash';

                            // La venta acaba de mover el stock: un producto que se agotó tiene que
                            // aparecer marcado antes de que el cajero intente venderlo otra vez.
                            this.$nextTick(() => this.refrescar());
                        } catch (e) {
                            // Red caída: NO se limpia el carrito. El cobro pudo llegar o no, y
                            // borrar las líneas dejaría al cajero sin saber qué estaba vendiendo.
                            this.errorCobro = 'Sin conexión. Comprueba si la venta se registró antes de repetirla.';
                        } finally {
                            this.cobrando = false;
                        }
                    },
                };
            }
        </script>
    @endif
</x-dynamic-component>
