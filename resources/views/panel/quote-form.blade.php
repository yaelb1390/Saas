@php
    // La MISMA vista sirve para crear y para editar: son el mismo formulario, con las líneas
    // precargadas o no. Duplicarla sería mantener dos veces el Alpine de las líneas dinámicas, que
    // es la parte con más riesgo de desincronizarse entre las dos copias.
    $editando = isset($quote);
@endphp

<x-layouts.admin :back="$editando ? route('panel.quotes.show', $quote) : route('panel.quotes.index')" :back-label="$editando ? 'Cotización '.$quote->code : 'Cotizaciones'"
                 :title="$editando ? 'Editar '.$quote->code : 'Nueva cotización'"
                :heading="$editando ? 'Editar '.$quote->code : 'Nueva cotización'"
                subheading="Lo que ofrezcas aquí queda por escrito, con su fecha">

    <form method="POST" action="{{ $editando ? route('panel.quotes.update', $quote) : route('panel.quotes.store') }}"
          x-data="cotizador(@js($productos), @js($clientes), @js($editando ? [
              'clienteId' => (string) ($quote->customer_id ?? ''),
              'nombre' => $quote->customer_name,
              'telefono' => $quote->customer_phone ?? '',
              'descuento' => (float) $quote->discount_total,
              'validoHasta' => $quote->valid_until?->format('Y-m-d'),
              'lineas' => $quote->items->map(fn ($item) => [
                  'libre' => $item->product_id === null,
                  'product_id' => (string) ($item->product_id ?? ''),
                  'description' => $item->description,
                  'quantity' => (float) $item->quantity,
                  'unit_price' => (float) $item->unit_price,
              ])->values(),
          ] : null))" class="space-y-5">
        @csrf
        @if ($editando) @method('PUT') @endif

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
                            <input type="date" name="valid_until" x-model="validoHasta" class="bmos-input"
                                   value="{{ $editando ? $quote->valid_until?->format('Y-m-d') : now()->addDays($validezPorOmision)->format('Y-m-d') }}">
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
                            <button type="button" @click="agregar()" class="bmos-btn bmos-btn-suave">+ Producto</button>
                            {{-- La mano de obra y el transporte se cotizan igual que un tornillo y no
                                 están en el catálogo. Obligar a crearlos como producto llenaría el
                                 inventario de cosas que nadie va a contar nunca. --}}
                            <button type="button" @click="agregar(true)" class="bmos-btn bmos-btn-suave">+ Concepto libre</button>
                        </div>
                    </div>

                    @if (count($rapidos) > 0)
                        {{-- Los más vendidos en los últimos 30 días: un toque y la línea ya queda
                             con producto y precio puestos, sin abrir el desplegable ni buscar. --}}
                        <div class="border-b border-slate-100 bg-slate-50 p-3">
                            <p class="mb-2 px-1 text-[0.7rem] font-semibold uppercase tracking-wide text-slate-400">
                                Productos rápidos
                            </p>
                            <div class="flex flex-wrap gap-2">
                                @foreach ($rapidos as $p)
                                    <button type="button" @click="agregarRapido(@js($p))"
                                            class="rounded-full border border-slate-200 bg-white px-3 py-1.5 text-xs font-medium text-slate-700 shadow-sm transition hover:border-indigo-300 hover:bg-indigo-50 hover:text-indigo-700">
                                        {{ $p['name'] }}
                                        <span class="text-slate-400">· {{ money((float) $p['price']) }}</span>
                                    </button>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    <div class="divide-y divide-slate-50">
                        <template x-for="(linea, i) in lineas" :key="linea.uid">
                            <div class="grid grid-cols-12 items-end gap-2 p-4">
                                <div class="col-span-12 sm:col-span-5">
                                    <label class="bmos-field-label" x-show="i === 0">Concepto</label>

                                    <template x-if="!linea.libre">
                                        <div>
                                            {{-- Busca por código o nombre SIN tocar cómo se generan las
                                                 opciones: solo oculta/enseña las que ya están en el select
                                                 (ver el porqué justo debajo). Nunca las quita ni las vuelve
                                                 a crear, así que el valor elegido no se pierde al buscar. --}}
                                            <input type="text" placeholder="Buscar por código o nombre…"
                                                   class="bmos-input mb-1 text-xs" autocomplete="off"
                                                   @input="filtrarProductos($event)">

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
                                                    <option value="{{ $producto['id'] }}"
                                                            data-buscar="{{ strtolower(trim(($producto['sku'] ?? '').' '.$producto['name'])) }}">
                                                        {{ $producto['sku'] ? $producto['sku'].' — ' : '' }}{{ $producto['name'] }}
                                                    </option>
                                                @endforeach
                                            </select>
                                        </div>
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
                              placeholder="Incluye instalación. No incluye transporte fuera de la ciudad."
                              >{{ $editando ? $quote->notes : '' }}</textarea>
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
                    {{ $editando ? 'Guardar cambios' : 'Crear cotización' }}
                </button>
            </div>
        </div>
    </form>

    {{-- El script va aquí mismo. Este layout no tiene pila de scripts, así que apilarlo se
         perdería en silencio y el formulario quedaría muerto sin dar un solo error. --}}
    <script>
        // `datosIniciales`: null al crear (arranca con una línea vacía). Al editar, trae lo que la
        // cotización ya tiene —incluidas sus líneas, con la misma forma que `agregar()`/`agregarRapido()`
        // ya producen— para que Alpine parta de ahí en vez de un formulario en blanco.
        function cotizador(productos, clientes, datosIniciales = null) {
            return {
                productos,
                clientes,
                clienteId: datosIniciales?.clienteId ?? '',
                nombre: datosIniciales?.nombre ?? '',
                telefono: datosIniciales?.telefono ?? '',
                descuento: datosIniciales?.descuento ?? '',
                validoHasta: datosIniciales?.validoHasta ?? document.querySelector('[name="valid_until"]')?.value ?? '',
                lineas: [],
                proximo: 1,

                init() {
                    if (datosIniciales?.lineas?.length) {
                        for (const linea of datosIniciales.lineas) {
                            this.lineas.push({ uid: this.proximo++, ...linea });
                        }

                        return;
                    }

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

                /*
                 * Un toque y la línea ya queda con producto y precio de hoy: no hace falta abrir
                 * el desplegable ni buscar. Si ya hay una línea de ese mismo producto SIN tocar
                 * (recién añadida, cantidad 1), se le suma uno en vez de crear una línea repetida
                 * al lado —así diez toques seguidos al mismo botón arman "10", no diez filas de "1".
                 */
                agregarRapido(p) {
                    const existente = this.lineas.find((l) => !l.libre && String(l.product_id) === String(p.id));

                    if (existente) {
                        existente.quantity = (Number(existente.quantity) || 0) + 1;
                        return;
                    }

                    this.lineas.push({
                        uid: this.proximo++,
                        libre: false,
                        product_id: String(p.id),
                        description: p.name,
                        quantity: 1,
                        unit_price: Number(p.price),
                    });
                },

                quitar(i) {
                    this.lineas.splice(i, 1);
                },

                /**
                 * Oculta/enseña las opciones YA PINTADAS del <select> vecino, nunca las regenera —ver
                 * el comentario junto al <select> sobre por qué—. `hidden` en una <option> es DOM
                 * nativo, no Alpine: se cambia a mano sobre el elemento real, sin pasar por `x-model`
                 * ni por el estado de la línea, así que el valor ya elegido no se toca aunque deje de
                 * verse en la lista mientras se escribe.
                 */
                filtrarProductos(event) {
                    const texto = event.target.value.trim().toLowerCase();
                    const select = event.target.nextElementSibling;

                    if (!select) return;

                    for (const opcion of select.options) {
                        if (opcion.value === '') continue; // "Elige un producto…" siempre visible

                        opcion.hidden = texto !== '' && !(opcion.dataset.buscar || '').includes(texto);
                    }
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
