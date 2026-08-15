{{--
    Entrada de mercancía.

    Antes era «un producto, un envío, una recarga»: una remesa de treinta artículos eran treinta
    viajes al servidor, y si el almacenista se distraía a la mitad quedaban quince dentro y quince
    fuera sin nada que dijera cuáles.

    Ahora funciona como el carrito del punto de venta, que es la forma que ya conoce quien usa esto: se
    va escaneando, la lista crece, se corrigen cantidades y un botón lo registra todo de una vez.
--}}
<x-layouts.admin title="Entrada de mercancía" heading="Entrada de mercancía"
                 subheading="Escanea todo lo que llegó y confírmalo de una vez">

    <div x-data="entradaMercancia('{{ route('panel.products.lookup') }}')" x-init="recuperarBorrador()"
         @codigo-escaneado="barcode = $event.detail.codigo; escanear()">

        <form method="POST" action="{{ route('panel.stock.store') }}" @submit="volcarLineas($event)">
            @csrf

            {{-- Datos de la remesa. Todos opcionales salvo el almacén: hay que poder seguir metiendo
                 existencia rápido sin rellenar nada, que es como se usa a diario. --}}
            <div class="bmos-card bmos-card-pad">
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <div>
                        <label class="bmos-field-label">Almacén <span class="text-rose-500">*</span></label>
                        <select name="warehouse_id" class="bmos-input" required>
                            @foreach ($warehouses as $w)
                                <option value="{{ $w->id }}" @selected($w->is_default)>{{ $w->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="bmos-field-label">Proveedor</label>
                        <select name="supplier_id" class="bmos-input">
                            <option value="">— Ninguno —</option>
                            @foreach ($suppliers as $s)
                                <option value="{{ $s->id }}">{{ $s->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="bmos-field-label">Nº de factura o conduce</label>
                        <input type="text" name="reference" class="bmos-input" placeholder="B0100000123">
                    </div>
                    <div>
                        <label class="bmos-field-label">Fecha de entrada</label>
                        <input type="date" name="received_at" value="{{ now()->toDateString() }}"
                               max="{{ now()->toDateString() }}" class="bmos-input" required>
                    </div>
                </div>
            </div>

            <div class="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-[minmax(0,1fr)_20rem]">

                <div class="bmos-card overflow-hidden">
                    {{-- El lector escribe aquí y pulsa Enter solo. El foco vuelve tras cada escaneo:
                         es lo que permite pasar treinta códigos sin tocar el ratón. --}}
                    <div class="border-b border-slate-100 p-4">
                        <label class="bmos-field-label" for="entry-scan">Escanea o teclea el código</label>
                        <div class="flex flex-wrap items-center gap-2">
                            <input id="entry-scan" type="text" x-ref="scanInput" x-model="barcode"
                                   @keydown.enter.prevent="escanear()" autofocus autocomplete="off"
                                   placeholder="Pasa el lector por el código y pulsa Enter"
                                   class="bmos-input min-w-0 flex-1 font-mono">
                            <button type="button" @click="escanear()" class="bmos-btn bmos-btn-ghost" x-bind:disabled="buscando">
                                <span x-text="buscando ? 'Buscando...' : 'Añadir'"></span>
                            </button>
                        </div>

                        <p x-show="error" x-cloak class="mt-2 text-sm font-medium text-rose-600" x-text="error"></p>

                        {{-- Inventariar a pie de estantería es justo donde la cámara sí compensa: sin
                             mostrador, sin cola, y con tiempo para enfocar. --}}
                        <x-panel.camera-scanner />

                        @can('products.manage')
                            <div x-show="noEncontrado" x-cloak
                                 class="mt-2 flex flex-wrap items-center justify-between gap-2 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-800">
                                <span>Ese código no existe: <b x-text="codigoDesconocido"></b></span>
                                <a :href="'{{ route('panel.products') }}?nuevo=' + encodeURIComponent(codigoDesconocido)"
                                   class="bmos-btn bmos-btn-ghost text-xs">Crear el producto</a>
                            </div>
                        @endcan
                    </div>

                    <div class="overflow-x-auto">
                        <table class="bmos-table">
                            <thead>
                                <tr>
                                    <th>Producto</th><th class="text-right">Cantidad</th>
                                    <th class="text-right">Costo</th><th class="text-right">Importe</th><th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <template x-for="(l, i) in lineas" :key="l.id">
                                    <tr>
                                        <td>
                                            <p class="font-medium text-slate-800" x-text="l.name"></p>
                                            <p class="text-xs text-slate-400" x-text="l.sku || l.barcode || ''"></p>

                                            {{-- El aviso del costo aparece SOLO si cambió. Preguntarlo
                                                 siempre sería ruido; no preguntarlo nunca deja el
                                                 margen de los informes envejeciendo en silencio. --}}
                                            <label x-show="costoCambio(l)" x-cloak
                                                   class="mt-1 flex cursor-pointer items-center gap-1.5 text-xs text-amber-700">
                                                <input type="checkbox" x-model="l.actualizarCosto"
                                                       class="rounded border-amber-300 text-amber-600">
                                                <span>
                                                    Antes <b x-text="rd(l.costoActual)"></b> · ¿actualizar el costo del producto?
                                                </span>
                                            </label>
                                        </td>
                                        <td class="text-right">
                                            <input type="number" step="0.001" min="0.001" x-model.number="l.quantity"
                                                   class="bmos-input w-24 text-right">
                                        </td>
                                        <td class="text-right">
                                            <input type="number" step="0.01" min="0" x-model="l.unit_cost"
                                                   :placeholder="l.costoActual" class="bmos-input w-28 text-right">
                                        </td>
                                        <td class="text-right font-semibold text-slate-700" x-text="rd(importe(l))"></td>
                                        <td class="text-right">
                                            <button type="button" @click="quitar(i)" title="Quitar"
                                                    class="rounded-lg p-1.5 text-slate-400 hover:bg-rose-50 hover:text-rose-600">
                                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" style="width:1.1rem;height:1.1rem"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/></svg>
                                            </button>
                                        </td>
                                    </tr>
                                </template>

                                <tr x-show="lineas.length === 0">
                                    <td colspan="5" class="bmos-empty">
                                        Pasa el lector por el primer producto. Se irán acumulando aquí y
                                        los confirmas todos juntos al final.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                {{-- Resumen y confirmación --}}
                <div class="space-y-4">
                    <div class="bmos-card bmos-card-pad lg:sticky lg:top-20">
                        <p class="font-semibold text-slate-800">Resumen</p>

                        <dl class="mt-3 space-y-2 text-sm">
                            <div class="flex justify-between"><dt class="text-slate-500">Productos</dt>
                                <dd class="font-semibold" x-text="lineas.length"></dd></div>
                            <div class="flex justify-between"><dt class="text-slate-500">Unidades</dt>
                                <dd class="font-semibold" x-text="totalUnidades"></dd></div>
                            <div class="flex justify-between border-t border-slate-100 pt-2">
                                <dt class="text-slate-500">Costo total</dt>
                                <dd class="text-lg font-bold text-slate-800" x-text="rd(totalCosto)"></dd></div>
                        </dl>

                        <p x-show="cuantosCostosCambian > 0" x-cloak
                           class="mt-3 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800">
                            Vas a actualizar el costo de <b x-text="cuantosCostosCambian"></b>
                            <span x-text="cuantosCostosCambian === 1 ? 'producto' : 'productos'"></span>.
                        </p>

                        <div class="mt-4">
                            <label class="bmos-field-label">Notas</label>
                            <textarea name="notes" rows="2" class="bmos-input" placeholder="Opcional"></textarea>
                        </div>

                        <button type="submit" class="bmos-btn bmos-btn-primary mt-4 w-full justify-center"
                                x-bind:disabled="lineas.length === 0 || enviando">
                            <span x-text="enviando ? 'Registrando...' : 'Confirmar entrada'"></span>
                        </button>

                        <button type="button" @click="vaciar()" x-show="lineas.length > 0" x-cloak
                                class="bmos-btn bmos-btn-ghost mt-2 w-full justify-center text-xs text-slate-500">
                            Vaciar la lista
                        </button>
                    </div>
                </div>
            </div>

            {{-- Las líneas viajan como campos ocultos, montados justo antes de enviar. --}}
            <div x-ref="ocultos"></div>
        </form>

        {{-- Últimas entradas: el acuse de recibo de ESTA pantalla. Antes aquí salían los movimientos
             de existencia, ventas del punto de venta incluidas, que no dicen nada de lo que acabas de
             meter. --}}
        <div class="mt-6 bmos-card overflow-hidden">
            <div class="border-b border-slate-100 p-4">
                <p class="font-semibold text-slate-800">Últimas entradas</p>
            </div>
            <div class="overflow-x-auto">
                <table class="bmos-table">
                    <thead>
                        <tr>
                            <th>Código</th><th>Fecha</th><th>Proveedor</th><th>Referencia</th>
                            <th>Almacén</th><th class="text-right">Productos</th><th class="text-right">Costo</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($receipts as $r)
                            <tr>
                                <td class="font-mono text-xs text-slate-500">{{ $r->code }}</td>
                                <td class="text-xs text-slate-500">{{ $r->received_at?->format('d/m/Y') }}</td>
                                <td class="text-sm text-slate-700">{{ $r->deQuien() }}</td>
                                <td class="text-xs text-slate-500">{{ $r->reference ?? '—' }}</td>
                                <td class="text-sm text-slate-600">{{ $r->warehouse?->name ?? '—' }}</td>
                                <td class="text-right">{{ $r->lines_count }}</td>
                                <td class="text-right font-semibold text-slate-700">
                                    {{ (float) $r->costoTotal() > 0 ? money($r->costoTotal()) : '—' }}
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="bmos-empty">Todavía no se ha registrado ninguna entrada.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script>
        function entradaMercancia(lookupUrl) {
            return {
                barcode: '',
                lineas: [],
                buscando: false,
                enviando: false,
                error: '',
                noEncontrado: false,
                codigoDesconocido: '',

                /**
                 * La lista se guarda en el navegador después de cada cambio.
                 *
                 * Es el único riesgo real de acumular antes de confirmar: media remesa escaneada y un
                 * navegador que se cierra. Con esto, al volver sigue ahí.
                 */
                _CLAVE: 'bmos.entrada.borrador',

                recuperarBorrador() {
                    try {
                        const guardado = localStorage.getItem(this._CLAVE);
                        if (guardado) this.lineas = JSON.parse(guardado) || [];
                    } catch { /* un borrador ilegible no debe impedir trabajar */ }

                    this.$watch('lineas', () => this.guardarBorrador());
                },

                guardarBorrador() {
                    try {
                        localStorage.setItem(this._CLAVE, JSON.stringify(this.lineas));
                    } catch { /* sin espacio: se sigue trabajando igual, solo sin red de seguridad */ }
                },

                async escanear() {
                    const codigo = this.barcode.trim();
                    if (!codigo || this.buscando) return;

                    this.buscando = true;
                    this.error = '';
                    this.noEncontrado = false;

                    try {
                        const res = await fetch(lookupUrl + '?codigo=' + encodeURIComponent(codigo), {
                            headers: { Accept: 'application/json' },
                        });

                        if (!res.ok) {
                            this.error = 'No se pudo consultar el código. Recarga la página.';
                            return;
                        }

                        const data = await res.json();

                        if (!data.found) {
                            this.noEncontrado = true;
                            this.codigoDesconocido = codigo;
                            return;
                        }

                        this.agregar(data.product);
                    } catch {
                        this.error = 'Sin conexión con el servidor. Inténtalo de nuevo.';
                    } finally {
                        this.barcode = '';
                        this.buscando = false;
                        this.$refs.scanInput.focus();
                    }
                },

                /**
                 * Escanear dos veces el mismo producto SUMA en su línea en vez de crear otra.
                 *
                 * Es lo que hace el lector cuando pasas cinco cajas iguales, y dos líneas del mismo
                 * producto en la misma remesa no significan nada distinto: solo obligan a sumarlas de
                 * cabeza para saber cuánto entró.
                 */
                agregar(p) {
                    const existente = this.lineas.find((l) => l.product_id === p.id);

                    if (existente) {
                        existente.quantity = Number((existente.quantity + 1).toFixed(3));
                        return;
                    }

                    this.lineas.push({
                        id: p.id + '-' + Date.now(),
                        product_id: p.id,
                        name: p.name,
                        sku: p.sku || '',
                        barcode: p.barcode || '',
                        quantity: 1,
                        unit_cost: '',
                        costoActual: p.cost ?? '0.00',
                        actualizarCosto: true,
                    });
                },

                quitar(i) { this.lineas.splice(i, 1); },

                vaciar() {
                    this.lineas = [];
                    this.$refs.scanInput.focus();
                },

                /** ¿El costo escrito difiere del que tiene el producto? Solo entonces se pregunta. */
                costoCambio(l) {
                    if (l.unit_cost === '' || l.unit_cost === null) return false;

                    return Number(l.unit_cost).toFixed(2) !== Number(l.costoActual || 0).toFixed(2);
                },

                importe(l) {
                    if (l.unit_cost === '' || l.unit_cost === null) return 0;

                    return (Number(l.quantity) || 0) * (Number(l.unit_cost) || 0);
                },

                get totalUnidades() {
                    return this.lineas.reduce((s, l) => s + (Number(l.quantity) || 0), 0)
                        .toLocaleString('es-DO', { maximumFractionDigits: 3 });
                },

                get totalCosto() {
                    return this.lineas.reduce((s, l) => s + this.importe(l), 0);
                },

                get cuantosCostosCambian() {
                    return this.lineas.filter((l) => this.costoCambio(l) && l.actualizarCosto).length;
                },

                rd(n) {
                    return 'RD$ ' + (Number(n) || 0).toLocaleString('es-DO', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                },

                /**
                 * Monta los campos ocultos con las líneas justo antes de enviar.
                 *
                 * Se hace aquí y no con inputs dentro del bucle porque el nombre tiene que llevar el
                 * índice (`lines[0][product_id]`) y Alpine reordena el DOM al quitar una línea: los
                 * índices quedarían con huecos y Laravel recibiría un array salteado.
                 */
                volcarLineas(e) {
                    if (this.lineas.length === 0) {
                        e.preventDefault();
                        return;
                    }

                    const caja = this.$refs.ocultos;
                    caja.innerHTML = '';

                    this.lineas.forEach((l, i) => {
                        const campos = {
                            product_id: l.product_id,
                            quantity: l.quantity,
                            unit_cost: l.unit_cost,
                            update_cost: this.costoCambio(l) && l.actualizarCosto ? 1 : 0,
                        };

                        for (const [clave, valor] of Object.entries(campos)) {
                            const input = document.createElement('input');
                            input.type = 'hidden';
                            input.name = `lines[${i}][${clave}]`;
                            input.value = valor ?? '';
                            caja.appendChild(input);
                        }
                    });

                    this.enviando = true;

                    // La remesa ya viaja: el borrador deja de hacer falta y dejarlo puesto la
                    // reaparecería entera al volver a la pantalla.
                    try { localStorage.removeItem(this._CLAVE); } catch { /* da igual */ }
                },
            };
        }
    </script>
</x-layouts.admin>
