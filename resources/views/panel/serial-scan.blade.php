{{--
    Escaneo masivo de unidades serializadas.

    Para lo que se lleva por número de serie —celulares, electrónica—, donde cada unidad es única.
    Se elige el producto una vez, se fijan los datos que comparte la tanda (condición, color, precio)
    y se disparan los seriales uno tras otro: cada Enter añade una unidad a la lista, y un botón las
    da de alta todas de golpe.

    El foco vuelve al campo del lector tras cada disparo, para no tener que volver a pincharlo — es lo
    que permite vaciar una caja de treinta teléfonos sin tocar el ratón.

    Solo aparece si hay productos marcados «con número de serie». Si no, se explica cómo marcarlos en
    vez de mostrar un desplegable vacío.
--}}
<x-layouts.admin title="Escaneo masivo" heading="Escaneo masivo"
                 subheading="Elige el producto con serie y escanea las unidades una por una">

    @if ($serializados->isEmpty())
        <div class="bmos-card bmos-card-pad max-w-xl">
            <p class="font-semibold text-slate-800">No tienes productos con número de serie.</p>
            <p class="mt-2 text-sm text-slate-500">
                Esta pantalla es para lo que se lleva unidad por unidad: teléfonos, electrónica, equipos
                con garantía. Marca un producto como <b>«con número de serie»</b> al crearlo o editarlo en
                <a href="{{ route('panel.products') }}" class="font-medium text-indigo-600">Inventario</a>,
                y aparecerá aquí.
            </p>
        </div>
    @else
        <div x-data="escaneoDeSeries()" class="max-w-4xl">
            <form method="POST" action="{{ route('panel.products.scan-serials') }}"
                  @submit="$refs.serialesInput.value = JSON.stringify(unidades.map(u => ({ serial: u })))">
                @csrf
                <input type="hidden" name="seriales" x-ref="serialesInput">

                <div class="bmos-card bmos-card-pad">
                    {{-- El producto y el almacén: se eligen una vez para toda la tanda. --}}
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label class="bmos-field-label" for="serie-producto">Producto *</label>
                            <select id="serie-producto" name="product_id" x-model="productoId" required class="bmos-input">
                                <option value="">— elegir —</option>
                                @foreach ($serializados as $p)
                                    <option value="{{ $p->id }}">{{ $p->name }} @if ($p->sku)· {{ $p->sku }}@endif</option>
                                @endforeach
                            </select>
                        </div>

                        @if ($warehouses->count() > 1)
                            <div>
                                <label class="bmos-field-label" for="serie-almacen">Almacén *</label>
                                <select id="serie-almacen" name="warehouse_id" required class="bmos-input">
                                    @foreach ($warehouses as $w)
                                        <option value="{{ $w->id }}">{{ $w->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                        @else
                            <input type="hidden" name="warehouse_id" value="{{ $warehouses->first()?->id }}">
                        @endif
                    </div>

                    {{-- Lo que comparte la tanda. Todo opcional: si estas veinte no traen nada especial,
                         se escanean y ya. Vacío quiere decir «lo del producto». --}}
                    <div class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <div>
                            <label class="bmos-field-label" for="serie-condicion">Condición</label>
                            <select id="serie-condicion" name="condition" class="bmos-input">
                                <option value="">—</option>
                                <option value="nuevo">Nuevo</option>
                                <option value="usado">Usado</option>
                                <option value="reacondicionado">Reacondicionado</option>
                            </select>
                        </div>
                        <div>
                            <label class="bmos-field-label" for="serie-color">Color</label>
                            <input id="serie-color" type="text" name="color" placeholder="ej. Negro" class="bmos-input">
                        </div>
                        <div>
                            <label class="bmos-field-label" for="serie-costo">Costo (compra)</label>
                            <input id="serie-costo" type="number" step="0.01" min="0" name="cost" placeholder="del producto" class="bmos-input">
                        </div>
                        <div>
                            <label class="bmos-field-label" for="serie-precio">Precio de venta</label>
                            <input id="serie-precio" type="number" step="0.01" min="0" name="price" placeholder="del producto" class="bmos-input">
                        </div>
                    </div>

                    {{-- El campo del lector. Aquí escribe la pistola y pulsa Enter solo; el foco vuelve
                         tras cada uno. Va deshabilitado hasta elegir producto: escanear sin saber a qué
                         producto van las series no lleva a ningún sitio. --}}
                    <div class="mt-4">
                        <label class="bmos-field-label" for="serie-scan">Número de serie / IMEI</label>
                        <input id="serie-scan" type="text" x-ref="scan" x-model="actual"
                               @keydown.enter.prevent="agregar()"
                               :disabled="!productoId"
                               placeholder="Escanea y pulsa Enter…" autocomplete="off"
                               class="bmos-input font-mono">
                        <p x-show="aviso" x-cloak x-text="aviso" class="mt-1 text-xs text-amber-600"></p>
                    </div>
                </div>

                {{-- La lista de lo escaneado, con el contador. Cada una se puede quitar por si coló una. --}}
                <div class="bmos-card mt-4 overflow-hidden">
                    <div class="flex items-center justify-between border-b border-slate-100 p-4">
                        <p class="font-semibold text-slate-800">
                            <span x-text="unidades.length"></span>
                            <span x-text="unidades.length === 1 ? 'unidad escaneada' : 'unidades escaneadas'"></span>
                        </p>
                        <button type="button" x-show="unidades.length > 0" @click="unidades = []"
                                class="text-xs font-medium text-slate-500 hover:text-rose-600">Vaciar</button>
                    </div>

                    <template x-if="unidades.length === 0">
                        <p class="bmos-empty">Nada escaneado todavía.</p>
                    </template>

                    <ul x-show="unidades.length > 0" class="divide-y divide-slate-100">
                        <template x-for="(u, i) in unidades" :key="u">
                            <li class="flex items-center justify-between px-4 py-2 text-sm">
                                <span class="font-mono text-slate-700"><span class="mr-2 text-slate-400" x-text="i + 1"></span><span x-text="u"></span></span>
                                <button type="button" @click="unidades.splice(i, 1)"
                                        class="text-slate-400 hover:text-rose-600" aria-label="Quitar">&times;</button>
                            </li>
                        </template>
                    </ul>
                </div>

                <div class="mt-4 flex justify-end">
                    <button type="submit" :disabled="unidades.length === 0"
                            class="bmos-btn bmos-btn-primary"
                            :class="unidades.length === 0 ? 'opacity-50 cursor-not-allowed' : ''">
                        Dar de alta <span x-show="unidades.length > 0" x-text="'· ' + unidades.length"></span>
                    </button>
                </div>
            </form>
        </div>

        <script>
            function escaneoDeSeries() {
                return {
                    productoId: '',
                    actual: '',
                    unidades: [],
                    aviso: '',

                    agregar() {
                        const serie = this.actual.trim();
                        this.actual = '';

                        if (!serie) return;

                        // Repetido en la misma tanda: se caza aquí antes de mandarlo, para avisar en el
                        // momento en vez de que el servidor lo rechace al final. El servidor lo vuelve a
                        // comprobar de todos modos —incluidas las series de otras altas—, que es donde
                        // manda la regla.
                        if (this.unidades.some((u) => u.toLowerCase() === serie.toLowerCase())) {
                            this.aviso = 'Esa serie ya está en la lista: ' + serie;
                        } else {
                            this.unidades.push(serie);
                            this.aviso = '';
                        }

                        // El foco vuelve al lector: es lo que deja seguir disparando sin tocar nada.
                        this.$nextTick(() => this.$refs.scan.focus());
                    },
                };
            }
        </script>
    @endif

    @include('partials.toast')
</x-layouts.admin>
