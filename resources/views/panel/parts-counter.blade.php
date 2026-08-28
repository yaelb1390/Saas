<x-layouts.admin title="Mostrador de repuestos" heading="Mostrador de repuestos" subheading="Busca la pieza, arma el ticket y factura descontando stock">

    {{-- Acuse con enlace al recibo tras facturar (el aviso de éxito/error lo pinta el toast global). --}}
    @if (session('pos_receipt_id'))
        <div class="mb-5 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800">
            <span class="flex items-center gap-2">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-5 w-5"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5"/></svg>
                {{ session('panel_ok') ?? 'Factura emitida.' }}
            </span>
            <div class="flex gap-2">
                <a href="{{ route('panel.sales.receipt', session('pos_receipt_id')) }}?print=1" target="_blank" rel="noopener"
                   class="bmos-btn bmos-btn-primary text-xs">🖨️ Imprimir recibo</a>
                <a href="{{ route('panel.sales.receipt.pdf', ['sale' => session('pos_receipt_id'), 'mode' => 'descargar']) }}"
                   class="bmos-btn bmos-btn-ghost text-xs">⬇️ PDF 80mm</a>
            </div>
        </div>
    @endif

    @unless ($hasWarehouse)
        <div class="mb-5 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-700">
            No hay un almacén por defecto configurado. Créalo antes de facturar desde el mostrador.
        </div>
    @endunless

    <div x-data="partsCounter('{{ route('panel.parts.search') }}')" class="grid grid-cols-1 gap-5 lg:grid-cols-4">
        {{-- El documento y, debajo, las coincidencias. --}}
        <div class="lg:col-span-3">
            {{--
                LA REJILLA, la misma que en el Punto de Venta y con sus mismas clases.

                Las dos pantallas pintaban el mismo `ProductLookupPresenter` de dos maneras distintas
                —una lista de tarjetas aquí, una tabla allá— y esa era la duplicación de fondo: el
                mismo dato con dos aspectos y dos comportamientos. Ahora comparten estilos y gestos.

                VA FUERA DEL FORMULARIO DE FACTURAR: dentro, el Enter del lector lo enviaría y emitiría
                un comprobante a medio armar, que con un NCF de por medio no se deshace con un clic.
            --}}
            <div class="bmos-card bmos-card-pad">
                <div class="pos-doc-cab">
                    <span class="pos-doc-titulo">Ticket</span>
                    <span class="pos-doc-cuenta" x-show="cart.length > 0" x-cloak
                          x-text="cart.length + (cart.length === 1 ? ' línea' : ' líneas')"></span>
                </div>

                <div class="bmos-tabla-envoltura">
                    <table class="bmos-table pos-rejilla">
                        <thead>
                            <tr>
                                <th class="pos-rej-cant">Cant.</th>
                                <th class="pos-rej-clave">Clave</th>
                                <th>Descripción</th>
                                <th class="pos-num pos-rej-precio">Precio</th>
                                <th class="pos-num pos-rej-importe">Importe</th>
                                <th class="pos-rej-quitar"><span class="sr-only">Quitar</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-for="(item, i) in cart" :key="item.id">
                                <tr>
                                    <td data-rotulo="Cant." class="pos-rej-cant">
                                        <input type="number" step="1" min="0" x-model.number="item.qty"
                                               aria-label="Cantidad" class="pos-celda pos-num">
                                    </td>
                                    <td data-rotulo="Clave" class="pos-mono pos-rej-clave" x-text="item.sku || '—'"></td>
                                    <td data-rotulo="Descripción" class="pos-recorta" :title="item.name">
                                        <span class="pos-valor" x-text="item.name"></span>
                                    </td>
                                    {{-- El precio no se escribe: al facturar, el servidor lo relee de la base. --}}
                                    <td data-rotulo="Precio" class="pos-num pos-rej-precio" x-text="rd(item.price)"></td>
                                    <td data-rotulo="Importe" class="pos-num pos-rej-total" x-text="rd(item.price * item.qty)"></td>
                                    <td class="pos-rej-quitar">
                                        <button type="button" @click="cart.splice(i, 1)" class="pos-quitar" aria-label="Quitar la línea">&times;</button>
                                    </td>
                                </tr>
                            </template>

                            {{-- La fila en blanco: aquí escribe el lector y aquí se teclea la clave o
                                 unas letras. Sustituye a la caja de búsqueda que había arriba. --}}
                            <tr class="pos-rej-nueva">
                                <td data-rotulo="Cant." class="pos-rej-cant">
                                    <input type="number" step="1" min="0" x-model.number="nuevaCant"
                                           @keydown.enter.prevent="$refs.searchInput.focus()"
                                           aria-label="Cantidad de la línea nueva" placeholder="1" class="pos-celda pos-num">
                                </td>
                                <td colspan="5">
                                    <input id="parts-search" type="text" x-ref="searchInput" x-model="query"
                                           @input.debounce.250ms="search()"
                                           @keydown.enter.prevent="meter()"
                                           @keydown.arrow-down.prevent="mover(1)"
                                           @keydown.arrow-up.prevent="mover(-1)"
                                           @keydown.escape="results = []; marcado = -1"
                                           autofocus autocomplete="off"
                                           placeholder="Pasa el lector, teclea la clave, o unas letras (ej. «corolla», «90915»)"
                                           class="pos-celda font-mono">
                                </td>
                            </tr>

                            <tr x-show="cart.length === 0" x-cloak>
                                <td colspan="6" class="pos-rej-vacio">
                                    Pasa el lector, teclea la clave, o unas letras para buscar.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <p x-show="searchError" x-cloak x-text="searchError"
                   class="mt-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-700"></p>
            </div>

            {{-- Las coincidencias, debajo de la rejilla: donde está la vista al teclear. --}}
            <div class="mt-4">
                <p x-show="results.length > 0" x-cloak class="pos-sug-titulo">
                    Coincidencias
                    <span x-text="'(' + results.length + ')'"></span>
                    <span class="pos-sug-ayuda">Enter mete la primera · ↑↓ para elegir otra</span>
                </p>

                <div x-show="results.length > 0" x-cloak class="bmos-tabla-envoltura">
                    <table class="bmos-table pos-tabla">
                        <thead>
                            <tr>
                                <th>Artículo</th>
                                <th x-show="col.vehiculo">Aplica a</th>
                                <th x-show="col.ubicacion">Ubicación</th>
                                <th class="pos-num pos-col-exist">Existencia</th>
                                <th class="pos-num pos-col-precio">Precio</th>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-for="(p, i) in results" :key="p.id">
                                <tr class="pos-fila" :class="{ 'pos-fila--marcada': i === marcado, 'pos-fila--muerta': !p.sellable }"
                                    @click="elegir(i)">
                                    <td data-rotulo="Artículo">
                                        <span class="pos-nombre" x-text="p.name"></span>
                                        <span class="pos-sku">
                                            <span x-text="p.sku"></span>
                                            <template x-if="p.part_number">
                                                <span><span class="pos-sep">·</span><span x-text="p.part_number"></span></span>
                                            </template>
                                            <template x-if="p.brand">
                                                <span><span class="pos-sep">·</span><b x-text="p.brand"></b></span>
                                            </template>
                                        </span>
                                    </td>
                                    <td x-show="col.vehiculo" data-rotulo="Aplica a" class="pos-recorta" :title="p.vehicle || ''"><span class="pos-valor" x-text="p.vehicle || '—'"></span></td>
                                    <td x-show="col.ubicacion" data-rotulo="Ubicación" class="pos-recorta" :title="p.location || ''"><span class="pos-valor" x-text="p.location || '—'"></span></td>
                                    <td data-rotulo="Existencia" class="pos-num pos-col-exist">
                                        <span class="bmos-badge" :class="Number(p.stock) < 5 ? 'badge-amber' : 'badge-blue'"
                                              x-text="p.reason === 'no_stock' ? 'Agotado' : existencia(p)"></span>
                                    </td>
                                    <td data-rotulo="Precio" class="pos-num pos-precio pos-col-precio"><span class="pos-valor" x-text="rd(p.price)"></span></td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>

                <p x-show="results.length === 0 && !busy && query.trim().length < 2"
                   class="py-6 text-center text-sm text-slate-400">
                    Teclea unas letras en <b>Clave</b> y aquí aparece lo que empieza por ahí.
                </p>
                <p x-show="busy" x-cloak class="py-6 text-center text-sm text-slate-400">Buscando…</p>
                <p x-show="results.length === 0 && !busy && query.trim().length >= 2" x-cloak class="bmos-empty">
                    Sin coincidencias para «<span x-text="query"></span>».
                </p>
            </div>
        </div>

        {{-- Ticket + facturación. `data-asis-evitar`: el asistente flotante se aparta en vez de
             taparlo; aquí están el total y el botón de facturar. --}}
        <div data-asis-evitar>
            <form method="POST" action="{{ route('panel.parts.invoice') }}" x-ref="form"
                  @submit="$refs.cartInput.value = JSON.stringify(cart.map(i => ({ id: i.id, qty: i.qty })))"
                  class="bmos-card bmos-card-pad">
                @csrf
                <input type="hidden" name="cart" x-ref="cartInput">

                <div class="mt-3 border-t border-slate-100 pt-3">
                    <div class="flex items-center justify-between text-lg font-bold text-slate-800">
                        <span>Total</span><span x-text="total.toFixed(2)"></span>
                    </div>

                    {{-- De qué almacén sale la mercancía. Con un solo almacén no se pregunta. --}}
                    @if (count($warehouses) > 1)
                        <label class="bmos-field-label" for="parts-almacen">Almacén</label>
                        <select id="parts-almacen" name="warehouse_id" class="bmos-input">
                            @foreach ($warehouses as $w)
                                <option value="{{ $w->id }}">{{ $w->name }}</option>
                            @endforeach
                        </select>
                    @endif

                    <label class="bmos-field-label mt-3">Tipo de comprobante (NCF)</label>
                    <select name="type" x-model="ncfType" class="bmos-input">
                        @foreach ($ncfTypes as $type)
                            <option value="{{ $type->value }}" data-requires="{{ $type->requiresTaxId() ? '1' : '0' }}">{{ $type->label() }}</option>
                        @endforeach
                    </select>

                    <label class="bmos-field-label mt-3">Cliente (opcional)</label>
                    <select name="customer_id" x-model="customerId" class="bmos-input">
                        <option value="">Sin identificar</option>
                        @foreach ($customers as $customerOption)
                            <option value="{{ $customerOption->id }}" data-tax="{{ $customerOption->tax_id }}">{{ $customerOption->name }}</option>
                        @endforeach
                    </select>
                    <input type="text" name="customer_name" x-model="customer" x-show="!customerId"
                           placeholder="Consumidor final" class="bmos-input mt-2">

                    <label class="bmos-field-label mt-3">RNC / Cédula <span x-show="requiresTaxId" class="text-rose-500">*</span></label>
                    <input type="text" name="customer_tax_id" x-model="taxId"
                           placeholder="Obligatorio para Crédito Fiscal / Gubernamental" class="bmos-input">

                    <label class="bmos-field-label mt-3">Pago recibido</label>
                    <input type="number" name="paid" step="0.01" min="0" x-model="paid" placeholder="0.00" class="bmos-input">
                    <div class="mt-2 flex items-center justify-between text-sm">
                        <span class="text-slate-500">Cambio</span>
                        <span class="font-semibold text-emerald-600" x-text="change.toFixed(2)"></span>
                    </div>

                    <button type="submit" :disabled="!canInvoice"
                            class="bmos-btn bmos-btn-primary mt-4 w-full justify-center"
                            :class="!canInvoice ? 'opacity-50 cursor-not-allowed' : ''">
                        Facturar <span x-show="cart.length" x-text="'· ' + total.toFixed(2)"></span>
                    </button>
                    <p x-show="requiresTaxId && !taxId.trim()" x-cloak class="mt-2 text-center text-xs text-amber-600">
                        Este tipo de comprobante exige RNC/Cédula del cliente.
                    </p>
                </div>
            </form>
        </div>
    </div>

    <script>
        function partsCounter(searchUrl) {
            return {
                query: '', results: [], busy: false, searchError: '',
                cart: [], paid: '', customer: '', customerId: '', taxId: '', ncfType: 'B02',

                /*
                 * Los mismos gestos que en el Punto de Venta: la fila marcada, la cantidad de la línea
                 * nueva y las columnas que se adaptan a lo que traigan los resultados. Las dos
                 * pantallas buscan contra el mismo presenter; que además se manejen igual es lo que
                 * evita tener que aprenderse dos mostradores.
                 */
                marcado: -1,
                nuevaCant: '',
                resultsPara: '',
                col: { vehiculo: false, ubicacion: false },

                rd(n) {
                    return 'RD$ ' + (parseFloat(n) || 0).toLocaleString('es-DO', {
                        minimumFractionDigits: 2, maximumFractionDigits: 2,
                    });
                },

                /** La existencia con su unidad, cuando la unidad dice algo. */
                existencia(p) {
                    const u = String(p.unit ?? '').trim().toLowerCase();
                    const propia = u !== '' && u !== 'unidad' && u !== 'unidades';

                    return String(Math.round((parseFloat(p.stock) || 0) * 1000) / 1000) + ' ' + (propia ? p.unit : 'u.');
                },

                async search() {
                    const q = this.query.trim();
                    if (q.length < 2) { this.results = []; this.resultsPara = ''; this.marcado = -1; return; }
                    this.busy = true; this.searchError = '';
                    try {
                        const res = await fetch(searchUrl + '?q=' + encodeURIComponent(q), { headers: { Accept: 'application/json' } });
                        if (!res.ok) { this.searchError = 'No se pudo buscar. Recarga la página.'; return; }
                        const data = await res.json();
                        this.results = data.results || [];
                    } catch {
                        this.searchError = 'Sin conexión con el servidor. Inténtalo de nuevo.';
                    } finally {
                        this.resultsPara = q;
                        this.calcularColumnas();
                        this.marcado = -1;
                        this.busy = false;
                    }
                },

                /** Una columna solo se pinta si algún resultado trae ese dato. */
                calcularColumnas() {
                    const alguno = (campo) => this.results.some((p) => {
                        const v = p[campo];

                        return v !== null && v !== undefined && String(v).trim() !== '';
                    });

                    this.col = { vehiculo: alguno('vehicle'), ubicacion: alguno('location') };
                },

                marcar(i) {
                    if (i < 0 || i >= this.results.length) return;
                    this.marcado = i;
                },

                mover(paso) {
                    if (this.results.length === 0) return;
                    const siguiente = this.marcado < 0
                        ? 0
                        : Math.min(this.results.length - 1, Math.max(0, this.marcado + paso));
                    this.marcar(siguiente);
                },

                porQueNo(p) {
                    const nombre = p?.name ?? 'Esa pieza';

                    if (p?.reason === 'no_stock') return 'Sin existencia: ' + nombre;
                    if (p?.reason === 'unavailable') return 'Hoy no hay: ' + nombre;
                    if (p?.reason === 'inactive') return 'Está inactiva: ' + nombre;

                    return 'No se puede vender: ' + nombre;
                },

                /**
                 * Elegir una fila la manda al ticket, de un gesto.
                 *
                 * Y si no se puede vender, LO DICE. Antes se ignoraba en silencio y quien atiende no
                 * sabía si el sistema se había colgado o si la pieza estaba agotada.
                 */
                elegir(i) {
                    this.marcar(i);
                    const pieza = this.results[i];

                    if (pieza && pieza.sellable) {
                        this.add(pieza);

                        return;
                    }

                    this.searchError = this.porQueNo(pieza);
                },

                /**
                 * El Enter de la celda de clave.
                 *
                 * Si las coincidencias son DE ESTE TEXTO se mete una y no se pregunta a nadie: la
                 * respuesta ya está en pantalla y un viaje al servidor ahí se nota en cada línea. Si
                 * son de la búsqueda anterior —o no hay— se busca primero, que es lo que pasa cuando
                 * dispara el lector y el antirrebote todavía no ha saltado.
                 */
                async meter() {
                    const q = this.query.trim();
                    if (!q) return;

                    if (this.resultsPara !== q) await this.search();

                    if (this.results.length === 0) {
                        this.searchError = 'No hay nada que empiece por: ' + q;

                        return;
                    }

                    this.elegir(this.marcado >= 0 ? this.marcado : 0);
                },

                add(p) {
                    if (!p.sellable) return;
                    const cantidad = Math.max(1, parseInt(this.nuevaCant, 10) || 1);
                    const it = this.cart.find(i => i.id === p.id);
                    if (it) it.qty += cantidad;
                    else this.cart.push({ id: p.id, sku: p.sku, name: p.name, price: parseFloat(p.price), qty: cantidad });

                    /*
                     * Se limpia lo tecleado y se suelta la marca, pero LAS COINCIDENCIAS SE QUEDAN: en
                     * un mostrador de repuestos se busca «corolla» una vez y se meten tres piezas de
                     * la misma lista. Soltar la marca evita que el siguiente disparo del lector meta
                     * la fila marcada en vez de lo que se acaba de escanear.
                     */
                    this.nuevaCant = '';
                    this.query = '';
                    this.searchError = '';
                    this.marcado = -1;
                    this.$nextTick(() => this.$refs.searchInput?.focus());
                },
                get total() { return this.cart.reduce((s, i) => s + i.price * i.qty, 0); },
                get change() { const p = parseFloat(this.paid || 0); return Math.max(0, p - this.total); },
                get requiresTaxId() {
                    const opt = this.$el?.querySelector(`select[name=type] option[value="${this.ncfType}"]`);
                    return opt?.dataset.requires === '1';
                },
                get canInvoice() {
                    const paidOk = parseFloat(this.paid || 0) >= this.total && this.total > 0;
                    const taxOk = !this.requiresTaxId || this.taxId.trim().length > 0;
                    return this.cart.length > 0 && paidOk && taxOk;
                },
            };
        }
    </script>
</x-layouts.admin>
