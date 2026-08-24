<x-layouts.admin title="Nueva cotización" heading="Nueva cotización"
                subheading="Lo que ofrezcas aquí queda por escrito, con su fecha">

    <form method="POST" action="{{ route('panel.quotes.store') }}"
          x-data="cotizador(@js($productos), @js($clientes))" class="space-y-5">
        @csrf

        <div class="grid grid-cols-1 gap-5 lg:grid-cols-[minmax(0,1fr)_20rem]">
            <div class="space-y-5">
                {{-- ── A quién ──────────────────────────────────────────────────────── --}}
                <div class="bmos-card bmos-card-pad">
                    <p class="mb-3 font-semibold text-slate-800">¿A quién se le cotiza?</p>

                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <div>
                            <label class="bmos-field-label">Cliente del CRM</label>
                            <select name="customer_id" x-model="clienteId" @change="rellenarCliente()" class="bmos-input">
                                <option value="">— Alguien que solo pidió precio —</option>
                                @foreach ($clientes as $cliente)
                                    <option value="{{ $cliente['id'] }}">{{ $cliente['name'] }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div>
                            <label class="bmos-field-label">Nombre</label>
                            <input type="text" name="customer_name" x-model="nombre" class="bmos-input"
                                   placeholder="Ferretería El Progreso" maxlength="255">
                        </div>

                        <div>
                            <label class="bmos-field-label">
                                Teléfono
                                {{-- Se dice para qué sirve, porque es lo que decide si se puede mandar. --}}
                                <span class="font-normal text-slate-400">— para mandársela por WhatsApp</span>
                            </label>
                            <input type="text" name="customer_phone" x-model="telefono" class="bmos-input"
                                   placeholder="809 555 1234" maxlength="40">
                        </div>

                        <div>
                            <label class="bmos-field-label">Válida hasta</label>
                            <input type="date" name="valid_until" class="bmos-input"
                                   value="{{ now()->addDays($validezPorOmision)->format('Y-m-d') }}">
                            <p class="mt-1 text-xs text-slate-400">
                                Pasada esta fecha, la cotización deja de poder cobrarse sin revisarla.
                            </p>
                        </div>
                    </div>
                </div>

                {{-- ── Las líneas ───────────────────────────────────────────────────── --}}
                <div class="bmos-card overflow-hidden">
                    <div class="flex items-center justify-between border-b border-slate-100 p-5">
                        <p class="font-semibold text-slate-800">¿Qué se le ofrece?</p>
                        <div class="flex gap-2">
                            <button type="button" @click="agregar()" class="bmos-btn">+ Producto</button>
                            {{-- La mano de obra y el transporte se cotizan igual que un tornillo y no
                                 están en el catálogo. Obligar a crearlos como producto llenaría el
                                 inventario de cosas que nadie va a contar nunca. --}}
                            <button type="button" @click="agregar(true)" class="bmos-btn">+ Concepto libre</button>
                        </div>
                    </div>

                    <div class="divide-y divide-slate-50">
                        <template x-for="(linea, i) in lineas" :key="linea.uid">
                            <div class="grid grid-cols-12 items-end gap-2 p-4">
                                <div class="col-span-12 sm:col-span-5">
                                    <label class="bmos-field-label" x-show="i === 0">Concepto</label>

                                    <template x-if="!linea.libre">
                                        {{-- Las opciones las pinta Blade, no Alpine.
                                             Con un <template x-for> dentro del select, el x-model se
                                             aplica ANTES de que existan las opciones, y asignarle a un
                                             select un valor que todavía no está entre sus opciones lo
                                             deja vacío sin dar ningún error: la línea viajaba sin
                                             producto y el servidor la rechazaba sin que se entendiera
                                             por qué. El catálogo no cambia mientras se escribe, así
                                             que no hay motivo para pintarlo en el navegador. --}}
                                        <select :name="`lines[${i}][product_id]`" x-model="linea.product_id"
                                                @change="ponerPrecio(linea)" class="bmos-input" required>
                                            <option value="">Elige un producto…</option>
                                            @foreach ($productos as $producto)
                                                <option value="{{ $producto['id'] }}">{{ $producto['name'] }}</option>
                                            @endforeach
                                        </select>
                                    </template>

                                    <template x-if="linea.libre">
                                        <input type="text" :name="`lines[${i}][description]`" x-model="linea.description"
                                               class="bmos-input" placeholder="Instalación, mano de obra, transporte…"
                                               maxlength="255" required>
                                    </template>
                                </div>

                                <div class="col-span-4 sm:col-span-2">
                                    <label class="bmos-field-label" x-show="i === 0">Cantidad</label>
                                    <input type="number" step="0.001" min="0.001" :name="`lines[${i}][quantity]`"
                                           x-model.number="linea.quantity" class="bmos-input" required>
                                </div>

                                <div class="col-span-4 sm:col-span-2">
                                    <label class="bmos-field-label" x-show="i === 0">Precio</label>
                                    <input type="number" step="0.01" min="0" :name="`lines[${i}][unit_price]`"
                                           x-model.number="linea.unit_price" class="bmos-input" required>
                                </div>

                                <div class="col-span-3 sm:col-span-2">
                                    <label class="bmos-field-label" x-show="i === 0">Importe</label>
                                    <p class="bmos-input bg-slate-50 text-right tabular-nums"
                                       x-text="dinero(importe(linea))"></p>
                                </div>

                                <div class="col-span-1 flex justify-end">
                                    <button type="button" @click="quitar(i)"
                                            class="rounded-lg p-2 text-slate-400 hover:bg-rose-50 hover:text-rose-600"
                                            title="Quitar línea">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" style="width:1.05rem;height:1.05rem"><path stroke-linecap="round" stroke-linejoin="round" d="M9.75 9.75l4.5 4.5m0-4.5-4.5 4.5M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/></svg>
                                    </button>
                                </div>
                            </div>
                        </template>
                    </div>

                    <p x-show="lineas.length === 0" class="bmos-empty">
                        Añade lo que le vas a ofrecer.
                    </p>
                </div>

                <div class="bmos-card bmos-card-pad">
                    <label class="bmos-field-label">Notas <span class="font-normal text-slate-400">— salen en el PDF</span></label>
                    <textarea name="notes" rows="3" class="bmos-input" maxlength="2000"
                              placeholder="Incluye instalación. No incluye transporte fuera de la ciudad."></textarea>
                </div>
            </div>

            {{-- ── El total, a la vista mientras se escribe ─────────────────────────── --}}
            <div class="space-y-3 rounded-2xl bg-slate-50 p-4 lg:sticky lg:top-4 lg:self-start">
                <p class="text-sm font-medium text-slate-700">Total de la cotización</p>

                <div>
                    <label class="bmos-field-label">Descuento del total</label>
                    <input type="number" step="0.01" min="0" name="discount_total"
                           x-model.number="descuento" class="bmos-input" placeholder="0.00">
                </div>

                {{-- El total se calcula aquí solo para que se vea mientras se teclea. El que vale es
                     el que calcula el servidor con el MISMO TaxCalculator que usa una venta: con dos
                     fórmulas parecidas, el descuadre aparece el día del redondeo. --}}
                <div class="rounded-xl bg-white p-3 text-right">
                    <p class="text-xs uppercase tracking-wide text-slate-400">Total aproximado</p>
                    <p class="text-2xl font-bold tabular-nums text-slate-800" x-text="dinero(total)"></p>
                    <p class="mt-1 text-xs text-slate-400">ITBIS incluido</p>
                </div>

                <button type="submit" :disabled="lineas.length === 0"
                        class="bmos-btn bmos-btn-primary w-full justify-center disabled:cursor-not-allowed disabled:opacity-50">
                    Crear cotización
                </button>
            </div>
        </div>
    </form>

    {{-- El script va aquí mismo. Este layout no tiene pila de scripts, así que apilarlo se
         perdería en silencio y el formulario quedaría muerto sin dar un solo error. --}}
    <script>
        function cotizador(productos, clientes) {
            return {
                productos,
                clientes,
                clienteId: '',
                nombre: '',
                telefono: '',
                descuento: '',
                lineas: [],
                proximo: 1,

                init() {
                    this.agregar();
                },

                agregar(libre = false) {
                    this.lineas.push({
                        uid: this.proximo++,
                        libre,
                        product_id: '',
                        description: '',
                        quantity: 1,
                        unit_price: 0,
                    });
                },

                quitar(i) {
                    this.lineas.splice(i, 1);
                },

                /*
                 * Al elegir producto se trae SU PRECIO DE HOY, y a partir de ahí se puede tocar.
                 *
                 * Lo que quede escrito es lo que se cotiza: cotizar es comprometerse con un
                 * precio, y a veces ese precio no es el de la lista.
                 */
                ponerPrecio(linea) {
                    const p = this.productos.find((x) => String(x.id) === String(linea.product_id));
                    if (!p) return;

                    linea.unit_price = Number(p.price);
                    linea.description = p.name;
                },

                rellenarCliente() {
                    const c = this.clientes.find((x) => String(x.id) === String(this.clienteId));
                    if (!c) return;

                    // Se copian para poder corregirlos: el teléfono de la ficha puede estar viejo
                    // y quien cotiza suele tener delante el bueno.
                    this.nombre = c.name ?? '';
                    this.telefono = c.phone ?? '';
                },

                importe(linea) {
                    return Math.max(0, (Number(linea.quantity) || 0) * (Number(linea.unit_price) || 0));
                },

                get total() {
                    const suma = this.lineas.reduce((n, l) => n + this.importe(l), 0);

                    return Math.max(0, suma - (Number(this.descuento) || 0));
                },

                dinero(n) {
                    return 'RD$ ' + (Number(n) || 0).toLocaleString('es-DO', {
                        minimumFractionDigits: 2, maximumFractionDigits: 2,
                    });
                },
            };
        }
    </script>
</x-layouts.admin>
