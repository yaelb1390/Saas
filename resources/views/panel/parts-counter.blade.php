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

    @if (! $openSession)
        {{--
            SIN TURNO NO SE FACTURA.

            Antes sí se podía: la venta se registraba igual, pero se quedaba fuera de todo arqueo, y
            el descuadre aparecía al contar el efectivo sin forma de saber de qué factura venía.

            La apertura vive en una ruta propia del mostrador y no en la del punto de venta porque
            aquella está detrás del módulo `pos`: una empresa que solo contrató Facturación se habría
            quedado mirando una pantalla que le pide un turno que no puede abrir.
        --}}
        <div class="mx-auto max-w-md bmos-card bmos-card-pad text-center">
            <span class="mx-auto mb-3 flex h-12 w-12 items-center justify-center rounded-xl bg-indigo-100 text-indigo-600 text-2xl">🔒</span>
            <p class="text-lg font-semibold text-slate-800">Caja cerrada</p>
            <p class="mb-4 text-sm text-slate-500">
                Facturar mueve dinero y existencias. Abre el turno con su fondo inicial para que el
                cobro entre en el arqueo.
            </p>

            @can('cash.open')
                <form method="POST" action="{{ route('panel.parts.open-session') }}" class="flex flex-wrap items-end gap-3">
                    @csrf
                    <div class="flex-1 text-left">
                        <label class="bmos-field-label" for="parts-fondo">Fondo de apertura</label>
                        <input id="parts-fondo" type="number" name="opening_amount" step="0.01" min="0" value="1000" required class="bmos-input">
                    </div>

                    {{-- Con un solo almacén no se pregunta: no hay nada que decidir. --}}
                    @if (count($warehouses) > 1)
                        <div class="flex-1 text-left">
                            <label class="bmos-field-label" for="parts-almacen-apertura">Almacén</label>
                            <select id="parts-almacen-apertura" name="warehouse_id" class="bmos-input">
                                @foreach ($warehouses as $w)
                                    <option value="{{ $w->id }}">{{ $w->name }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endif

                    <button type="submit" class="bmos-btn bmos-btn-primary">Abrir caja</button>
                </form>
            @else
                <p class="bmos-empty">Tu usuario no puede abrir caja. Pídeselo a quien lleve el turno.</p>
            @endcan
        </div>
    @else
    <div x-data="partsCounter('{{ route('panel.parts.search') }}', '{{ route('panel.parts.customers') }}', @js($siguienteNcf), @js($itbis), @js($rapidos), @js($topeDescuento))">
        {{--
            CABECERA COMPACTA: quién cobra, por qué caja y con qué comprobante.

            Una sola línea a propósito. Lo que hace falta saber de un vistazo antes de teclear es que
            el turno está abierto y qué NCF va a salir; todo lo demás roba altura al ticket, que es
            donde se trabaja.
        --}}
        <div class="bmos-mostrador-cab">
            <div class="bmos-mostrador-cab-izq">
                <span class="bmos-badge badge-green is-punto">Caja abierta</span>
                <span class="bmos-mostrador-dato">
                    <span class="bmos-mostrador-etq">Caja</span>
                    <b>{{ $openSession->cashRegister?->name ?? 'Principal' }}</b>
                </span>
                <span class="bmos-mostrador-dato">
                    <span class="bmos-mostrador-etq">Cajero</span>
                    <b>{{ $openSession->user?->name ?? auth()->user()?->name }}</b>
                </span>
                <span class="bmos-mostrador-dato">
                    <span class="bmos-mostrador-etq">Fondo</span>
                    <b>{{ money($openSession->opening_amount) }}</b>
                </span>
                @if ($almacenDelTurno = $openSession->almacenDeSalida())
                    <span class="bmos-mostrador-dato">
                        <span class="bmos-mostrador-etq">Almacén</span>
                        <b>{{ $almacenDelTurno->name }}</b>
                    </span>
                @endif
            </div>

            <div class="bmos-mostrador-cab-der">
                {{-- El NCF que saldría, no uno reservado: si otro terminal se adelanta será el
                     siguiente. Y si el tipo elegido no tiene secuencia, se dice AQUÍ y no al final. --}}
                <span class="bmos-mostrador-ncf" x-show="proximoNcf" x-cloak>
                    <span class="bmos-mostrador-etq">Próximo NCF</span>
                    <b class="bmos-mono" x-text="proximoNcf"></b>
                </span>
                <span class="bmos-mostrador-ncf is-grave" x-show="!proximoNcf" x-cloak>
                    Sin secuencia activa para este comprobante
                </span>
                <span class="bmos-mostrador-dato">{{ now()->format('d/m/Y H:i') }}</span>
            </div>
        </div>

        <div class="mt-4 grid grid-cols-1 gap-5 lg:grid-cols-4">
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

                <div class="bmos-tabla-envoltura pos-ticket-scroll">
                    <table class="bmos-table pos-rejilla">
                        <thead>
                            <tr>
                                <th class="pos-rej-cant">Cant.</th>
                                <th class="pos-rej-clave">Clave</th>
                                <th>Descripción</th>
                                <th class="pos-num pos-rej-desc">Desc.</th>
                                <th class="pos-num pos-rej-precio">Precio</th>
                                <th class="pos-num pos-rej-importe">Importe</th>
                                <th class="pos-rej-quitar"><span class="sr-only">Quitar</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-for="(item, i) in cart" :key="item.id">
                                <tr>
                                    <td data-rotulo="Cant." class="pos-rej-cant">
                                        {{-- Con decimales, y antes no: el `(int)` del servidor convertía medio
                                             kilo en cero. La columna de la base ya era decimal(15,3); era esta
                                             pantalla la que redondeaba. --}}
                                        <input type="number" step="0.001" min="0" x-model.number="item.qty"
                                               aria-label="Cantidad" class="pos-celda pos-num">
                                    </td>
                                    <td data-rotulo="Clave" class="pos-mono pos-rej-clave" x-text="item.sku || '—'"></td>
                                    <td data-rotulo="Descripción" class="pos-recorta" :title="item.name">
                                        <span class="pos-valor" x-text="item.name"></span>
                                    </td>
                                    {{-- El descuento es la vía para rebajar, y queda registrado COMO descuento:
                                         en la venta se distingue de un precio de catálogo bajo, y por eso se
                                         puede auditar después. Tocar el precio no dejaría rastro. --}}
                                    <td data-rotulo="Desc." class="pos-rej-desc">
                                        <input type="number" step="0.01" min="0" x-model.number="item.discount"
                                               aria-label="Descuento de la línea" placeholder="0" class="pos-celda pos-num">
                                    </td>
                                    {{-- El precio no se escribe: al facturar, el servidor lo relee de la base. --}}
                                    <td data-rotulo="Precio" class="pos-num pos-rej-precio" x-text="rd(item.price)"></td>
                                    <td data-rotulo="Importe" class="pos-num pos-rej-total" x-text="rd(importe(item))"></td>
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
                                <td colspan="6">
                                    <input id="parts-search" type="text" x-ref="searchInput" x-model="query"
                                           @input.debounce.250ms="search()"
                                           @keydown.enter.prevent="meter()"
                                           @keydown.arrow-down.prevent="mover(1)"
                                           @keydown.arrow-up.prevent="mover(-1)"
                                           @keydown.escape="results = []; marcado = -1; ficha = null"
                                           autofocus autocomplete="off"
                                           placeholder="Pasa el lector, teclea la clave, o unas letras (ej. «corolla», «90915»)"
                                           class="pos-celda font-mono">
                                </td>
                            </tr>

                            <tr x-show="cart.length === 0" x-cloak>
                                <td colspan="7" class="pos-rej-vacio">
                                    Pasa el lector, teclea la clave, o unas letras para buscar.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <p x-show="searchError" x-cloak x-text="searchError"
                   class="mt-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-700"></p>
            </div>

            {{--
                PRODUCTOS RÁPIDOS: lo que más se despacha, a un toque.

                Salen de las ventas reales del último mes, no de una lista configurada —esas se
                rellenan el primer día y nadie vuelve a tocarlas—. Entran al ticket por el MISMO
                camino que una coincidencia de búsqueda, porque llegan con la misma forma.

                Si el negocio todavía no ha vendido nada, la sección no se pinta: seis botones vacíos
                no ayudan a nadie.
            --}}
            <div class="mt-4" x-show="rapidos.length > 0" x-cloak>
                <p class="pos-sug-titulo">
                    Productos rápidos
                    <span class="pos-sug-ayuda">Lo que más se vende este mes</span>
                </p>

                <div class="bmos-mostrador-rapidos">
                    <template x-for="p in rapidos" :key="p.id">
                        <button type="button" class="bmos-mostrador-rapido"
                                :disabled="!p.sellable"
                                :class="!p.sellable ? 'is-agotado' : ''"
                                :title="p.sellable ? p.name : porQueNo(p)"
                                @click="p.sellable ? add(p) : (searchError = porQueNo(p))">
                            <span class="bmos-mostrador-rapido-nombre" x-text="p.name"></span>
                            <span class="bmos-mostrador-rapido-precio" x-text="rd(p.price)"></span>
                        </button>
                    </template>
                </div>
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
                                <th x-show="col.imagen" class="pos-col-img"><span class="sr-only">Foto</span></th>
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
                                    <td x-show="col.imagen" class="pos-col-img" data-rotulo="">
                                        <template x-if="p.image">
                                            <img :src="p.image" :alt="p.name" loading="lazy" class="pos-mini">
                                        </template>
                                    </td>
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

                {{--
                    LA FICHA del artículo marcado: todo lo que tenga relleno, y nada de lo que no.

                    Un artículo de colmado no enseña «Aplica a:» en blanco. Un rótulo sin valor no es
                    información, es un hueco que hace dudar de si el dato falta o el sistema falla.
                --}}
                <div x-show="ficha" x-cloak class="pos-ficha">
                    <template x-if="ficha">
                        <div>
                            <div class="pos-ficha-cab">
                                <template x-if="ficha.image">
                                    <img :src="ficha.image" :alt="ficha.name" class="pos-ficha-img">
                                </template>
                                <div class="min-w-0">
                                    <p class="pos-ficha-nombre" x-text="ficha.name"></p>
                                    <p class="pos-ficha-codigos">
                                        <span x-text="'SKU ' + ficha.sku"></span>
                                        <template x-if="ficha.barcode">
                                            <span x-text="' · Código ' + ficha.barcode"></span>
                                        </template>
                                    </p>
                                </div>
                                <span class="pos-ficha-precio" x-text="rd(ficha.price)"></span>
                                <button type="button" @click="ficha = null" class="pos-ficha-cerrar" aria-label="Cerrar la ficha">&times;</button>
                            </div>

                            <dl class="pos-datos">
                                <template x-if="ficha.part_number">
                                    <div><dt>Nº de parte</dt><dd class="pos-mono" x-text="ficha.part_number"></dd></div>
                                </template>
                                <template x-if="ficha.brand">
                                    <div><dt>Marca</dt><dd x-text="ficha.brand"></dd></div>
                                </template>
                                <template x-if="ficha.vehicle">
                                    <div><dt>Aplica a</dt><dd x-text="ficha.vehicle"></dd></div>
                                </template>
                                <template x-if="ficha.location">
                                    <div><dt>Ubicación</dt><dd x-text="ficha.location"></dd></div>
                                </template>
                                <template x-if="unidadPropia(ficha)">
                                    <div><dt>Unidad</dt><dd x-text="ficha.unit"></dd></div>
                                </template>
                                <div><dt>Existencia</dt><dd x-text="existencia(ficha)"></dd></div>
                            </dl>

                            {{-- Dónde está la existencia. Un total de «12» no sirve si ocho están en
                                 la sucursal del otro lado: se le diría que sí a un cliente y luego no
                                 habría qué entregarle. --}}
                            <template x-if="ficha.stock_por_almacen && ficha.stock_por_almacen.length > 1">
                                <p class="pos-almacenes">
                                    <template x-for="a in ficha.stock_por_almacen" :key="a.almacen">
                                        <span class="pos-almacen"><span x-text="a.almacen"></span>: <b x-text="limpio(a.cantidad)"></b></span>
                                    </template>
                                </p>
                            </template>

                            <template x-if="ficha.description">
                                <p class="pos-ficha-desc" x-text="ficha.description"></p>
                            </template>
                        </div>
                    </template>
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
                  @submit="procesando = true; $refs.cartInput.value = JSON.stringify(cart.map(i => ({ id: i.id, qty: i.qty, discount: i.discount || 0 }))); $refs.paymentsInput.value = dividido ? JSON.stringify(lineasDeReparto()) : ''"
                  class="bmos-card bmos-card-pad bmos-mostrador-resumen">
                @csrf
                <input type="hidden" name="cart" x-ref="cartInput">
                <input type="hidden" name="payment_method" :value="metodo">
                {{-- Vacío cuando no se divide: entonces manda `payment_method`, como toda la vida. --}}
                <input type="hidden" name="payments" x-ref="paymentsInput">

                <div>
                    {{--
                        EL DESGLOSE, que antes no estaba: solo se veía el total.

                        Sin él, quien factura no puede comprobar nada. Un cliente que pregunta cuánto
                        es el ITBIS obligaba a sacar la calculadora, y un descuento mal tecleado no se
                        distinguía de un precio bajo hasta ver el recibo impreso.

                        Los números se calculan con la MISMA regla que `TaxCalculator` —la tasa viaja
                        desde el servidor, no está escrita aquí—, pero lo que se emite lo calcula
                        siempre el servidor: esto es lo que se ve mientras se arma el ticket.
                    --}}
                    <div class="bmos-mostrador-linea">
                        <span>Subtotal</span><span x-text="rd(base)"></span>
                    </div>
                    <div class="bmos-mostrador-linea" x-show="descuento > 0" x-cloak>
                        <span>Descuento</span><span class="text-rose-600" x-text="'− ' + rd(descuento)"></span>
                    </div>
                    <div class="bmos-mostrador-linea">
                        <span>ITBIS <span class="bmos-mostrador-etq" x-text="'(' + itbis.tasa + '%)'"></span></span>
                        <span x-text="rd(impuesto)"></span>
                    </div>
                    <div class="bmos-mostrador-total">
                        <span>TOTAL</span><span x-text="rd(total)"></span>
                    </div>
                </div>

                <div class="mt-3 border-t border-slate-100 pt-3">

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

                    {{--
                        EL CLIENTE SE BUSCA, ya no se elige de una lista.

                        El desplegable cargaba TODOS los clientes activos de la empresa en cada
                        visita: con doscientos ya pesa, y con dos mil no sirve —nadie encuentra a
                        nadie desplazando—. Se busca por nombre, RNC, cédula o teléfono, porque quien
                        llama para recoger una pieza dice su número, no el nombre con el que lo
                        dieron de alta.
                    --}}
                    <label class="bmos-field-label mt-3" for="parts-cliente">Cliente (opcional)</label>
                    <input type="hidden" name="customer_id" :value="clienteElegido?.id ?? ''">

                    {{-- Elegido: se enseña quién es y se puede soltar de un clic. --}}
                    <div x-show="clienteElegido" x-cloak class="bmos-mostrador-cliente">
                        <span>
                            <b x-text="clienteElegido?.name"></b>
                            <span class="bmos-mostrador-etq" x-show="clienteElegido?.tax_id" x-text="clienteElegido?.tax_id"></span>
                        </span>
                        <button type="button" class="pos-quitar" aria-label="Quitar el cliente"
                                @click="soltarCliente()">&times;</button>
                    </div>

                    <div x-show="!clienteElegido" x-cloak class="relative">
                        <input id="parts-cliente" type="text" x-model="clienteQuery"
                               @input.debounce.250ms="buscarClientes()"
                               @keydown.escape="clientes = []"
                               autocomplete="off"
                               placeholder="Nombre, RNC, cédula o teléfono…" class="bmos-input">

                        <div x-show="clientes.length > 0" x-cloak class="bmos-mostrador-sug">
                            <template x-for="c in clientes" :key="c.id">
                                <button type="button" class="bmos-mostrador-sug-fila" @click="elegirCliente(c)">
                                    <span x-text="c.name"></span>
                                    <span class="bmos-mostrador-etq"
                                          x-text="[c.tax_id, c.phone].filter(Boolean).join(' · ') || '—'"></span>
                                </button>
                            </template>
                        </div>

                        <p x-show="clienteQuery.trim().length > 0 && clientes.length === 0 && !buscandoCliente" x-cloak
                           class="mt-1 text-xs text-slate-400">
                            Sin coincidencias. Se facturará al nombre que escribas abajo.
                        </p>

                        {{-- Sin identificar: el nombre que se imprime, sin ficha en el CRM. Es la
                             venta de mostrador de toda la vida y tiene que seguir siendo un paso. --}}
                        <input type="text" name="customer_name" x-model="customer"
                               placeholder="Consumidor final" class="bmos-input mt-2">
                    </div>

                    <label class="bmos-field-label mt-3">RNC / Cédula <span x-show="requiresTaxId" class="text-rose-500">*</span></label>
                    <input type="text" name="customer_tax_id" x-model="taxId"
                           placeholder="Obligatorio para Crédito Fiscal / Gubernamental" class="bmos-input">

                    {{--
                        FORMA DE PAGO. Antes no se preguntaba y todo entraba como efectivo: una
                        factura cobrada con tarjeta inflaba el cajón y el cierre salía con un
                        sobrante que nadie sabía explicar. El tono de cada botón lo decide el enum en
                        PHP, para que no haya dos paletas que mantener.
                    --}}
                    {{-- El cierre del ticket, con los mismos controles y la misma escala que el
                         Punto de Venta: forma de pago, importe, cambio y el botón se leen como un
                         solo bloque porque comparten altura y tamaño de cifra. --}}
                    <div class="bmos-pos-cierre mt-3">
                    <div class="flex items-center justify-between">
                        <label class="bmos-field-label mb-0">Forma de pago</label>
                        @if ($puedeDividir)
                            {{-- Solo si la migración está aplicada: sin la tabla no hay dónde guardar
                                 el reparto, y ofrecerlo sería llevar a un rechazo seguro. --}}
                            <button type="button" class="bmos-mostrador-dividir"
                                    :class="dividido ? 'is-activa' : ''"
                                    :aria-pressed="dividido"
                                    @click="alternarDividido()">
                                <span x-text="dividido ? 'Una sola forma' : 'Dividir el pago'"></span>
                            </button>
                        @endif
                    </div>

                    <div x-show="!dividido" class="bmos-mostrador-pagos">
                        @foreach ($paymentMethods as $method)
                            <button type="button" class="bmos-pos-opcion"
                                    data-tono="{{ $method->tono() }}"
                                    :class="metodo === '{{ $method->value }}' ? 'is-activa' : ''"
                                    :aria-pressed="metodo === '{{ $method->value }}'"
                                    @click="metodo = '{{ $method->value }}'">
                                {{ $method->label() }}
                            </button>
                        @endforeach
                    </div>

                    {{--
                        EL COBRO REPARTIDO.

                        Se escribe lo que el cliente ENTREGA por cada vía; el reparto lo hace el
                        servidor. Solo el efectivo admite dar de más —de ahí sale el vuelto—: un
                        datáfono no devuelve, así que cobrar de más con tarjeta se rechaza en vez de
                        taparse repartiendo el sobrante.
                    --}}
                    <div x-show="dividido" x-cloak class="bmos-mostrador-reparto">
                        @foreach ($paymentMethods as $method)
                            <label class="bmos-mostrador-reparto-fila">
                                <span class="bmos-pos-opcion is-activa" data-tono="{{ $method->tono() }}">{{ $method->label() }}</span>
                                <input type="number" step="0.01" min="0" placeholder="0.00"
                                       x-model="reparto.{{ $method->value }}"
                                       aria-label="Importe en {{ $method->label() }}"
                                       class="bmos-input pos-num">
                            </label>
                        @endforeach

                        <div class="bmos-mostrador-linea">
                            <span x-text="pendienteReparto > 0 ? 'Falta por cubrir' : 'Cubierto'"></span>
                            <span :class="pendienteReparto > 0 ? 'text-rose-600 font-semibold' : 'text-emerald-600 font-semibold'"
                                  x-text="rd(Math.abs(pendienteReparto))"></span>
                        </div>
                    </div>

                    {{-- Al dividir manda la lista de arriba, así que este campo sobra: dejarlo a la
                         vista invita a teclear un importe que el servidor va a ignorar. --}}
                    <label class="bmos-field-label mt-3" x-show="!dividido"
                           x-text="metodo === 'cash' ? 'Pago recibido' : 'Importe cobrado'"></label>
                    {{-- Deshabilitado al dividir, no solo escondido: un campo oculto se envía igual,
                         y con el reparto puesto el servidor ni lo mira. --}}
                    <input type="number" name="paid" step="0.01" min="0" inputmode="decimal"
                           x-show="!dividido" :disabled="dividido"
                           x-model="paid" placeholder="0.00" class="bmos-pos-input-pago">

                    {{-- El cambio solo tiene sentido en efectivo: con tarjeta se cobra el importe
                         exacto, y enseñar un «cambio» ahí es invitar a devolver dinero de más. --}}
                    <div x-show="!dividido && metodo === 'cash' && change > 0" x-cloak class="bmos-pos-change">
                        <span class="bmos-pos-change-label">Cambio</span>
                        <span class="bmos-pos-change-value" x-text="rd(change)"></span>
                    </div>
                    <button type="button" x-show="!dividido && metodo !== 'cash'" x-cloak
                            @click="paid = total.toFixed(2)"
                            class="mt-1 text-xs font-semibold text-indigo-600">Poner el importe exacto</button>

                    <button type="submit" :disabled="!canInvoice" class="bmos-pos-cobrar mt-3">
                        <span x-show="!procesando">Facturar</span>
                        <span x-show="procesando" x-cloak>Emitiendo…</span>
                        <span class="bmos-pos-cobrar-total" x-text="rd(total)"></span>
                    </button>

                    {{-- El porqué, siempre visible cuando el botón está apagado: adivinar por qué no
                         se puede cobrar es lo que hace que alguien recargue la página y pierda el
                         ticket entero. --}}
                    <p x-show="motivoParaNoFacturar && cart.length > 0" x-cloak
                       x-text="motivoParaNoFacturar"
                       class="mt-2 text-center text-xs text-amber-600"></p>
                    </div>
                </div>
            </form>
        </div>
        </div>
    </div>
    @endif

    <script>
        function partsCounter(searchUrl, clientesUrl, siguienteNcf, itbis, rapidos, topeDescuento) {
            return {
                query: '', results: [], busy: false, searchError: '',
                cart: [], paid: '', customer: '', taxId: '', ncfType: 'B02',

                // ── El cliente, que ahora se busca en vez de elegirse de una lista ────────────
                clienteQuery: '', clientes: [], buscandoCliente: false, clienteElegido: null,

                async buscarClientes() {
                    const q = this.clienteQuery.trim();
                    if (q.length < 2) { this.clientes = []; return; }

                    this.buscandoCliente = true;
                    try {
                        const res = await fetch(clientesUrl + '?q=' + encodeURIComponent(q), { headers: { Accept: 'application/json' } });
                        this.clientes = res.ok ? ((await res.json()).results || []) : [];
                    } catch {
                        // Sin conexión no se puede buscar, pero SÍ se puede facturar a nombre suelto:
                        // se deja la lista vacía y el campo de «Consumidor final» sigue ahí.
                        this.clientes = [];
                    } finally {
                        this.buscandoCliente = false;
                    }
                },

                elegirCliente(c) {
                    this.clienteElegido = c;
                    this.clientes = [];
                    this.clienteQuery = '';

                    /*
                     * Se rellena el RNC/cédula del cliente, y esto ahorra el error más caro de la
                     * pantalla: un crédito fiscal emitido con el identificador tecleado a mano y mal.
                     * Se puede corregir encima; lo que no se hace es obligar a copiarlo.
                     */
                    if (c.tax_id) this.taxId = c.tax_id;
                },

                soltarCliente() {
                    this.clienteElegido = null;
                    this.clienteQuery = '';
                    this.clientes = [];
                },

                /*
                 * La forma de pago, que decide si el cobro engorda el arqueo del turno.
                 *
                 * Antes no se preguntaba y todo se registraba como efectivo: una factura cobrada con
                 * tarjeta inflaba el cajón, y el cierre salía con un sobrante que nadie sabía de
                 * dónde venía. El servidor vuelve a acotar el valor, no se fía de esto.
                 */
                metodo: 'cash',

                // ── El cobro repartido ────────────────────────────────────────────────────────
                dividido: false,
                reparto: { cash: '', card: '', transfer: '' },

                alternarDividido() {
                    this.dividido = ! this.dividido;
                    // Al volver a una sola vía se limpia el reparto: dejarlo escrito y oculto haría
                    // que se enviara sin que nadie lo viera.
                    if (! this.dividido) this.reparto = { cash: '', card: '', transfer: '' };
                },

                /** Lo entregado entre todas las vías del reparto. */
                get entregadoReparto() {
                    return Object.values(this.reparto).reduce((s, v) => s + (parseFloat(v) || 0), 0);
                },

                get pendienteReparto() {
                    return Math.max(0, this.total - this.entregadoReparto);
                },

                /**
                 * Las líneas del reparto, con las que NO dan vuelto primero.
                 *
                 * El servidor imputa en el orden recibido y no deja que una tarjeta cobre más de lo
                 * pendiente. Mandando tarjeta y transferencia antes que el efectivo, cada una se mide
                 * contra un pendiente más grande y el efectivo absorbe el resto y el vuelto — que es
                 * justo como se cobra en un mostrador.
                 */
                lineasDeReparto() {
                    return ['card', 'transfer', 'cash']
                        .map((m) => ({ method: m, amount: parseFloat(this.reparto[m]) || 0 }))
                        .filter((p) => p.amount > 0);
                },

                /** Mientras se factura: evita el doble envío con el cliente delante. */
                procesando: false,

                /** Qué NCF saldría por cada tipo, resuelto en el servidor al abrir la pantalla. */
                siguienteNcf,

                /*
                 * La tasa y si el precio ya la incluye, tal como los tiene el servidor.
                 *
                 * El desglose de pantalla usa la MISMA regla que `TaxCalculator`, y viaja desde PHP en
                 * vez de estar escrito aquí: con la tasa duplicada, cambiarla en el `.env` habría
                 * dejado la pantalla enseñando un ITBIS y la factura declarando otro. Lo que se emite
                 * lo calcula siempre el servidor; esto solo es lo que se ve mientras se arma.
                 */
                itbis,

                /**
                 * Los más vendidos del último mes, ya con forma de resultado de búsqueda.
                 *
                 * Esa forma común es lo que permite que un botón rápido y una coincidencia entren al
                 * ticket por el MISMO camino: sin ella harían falta dos funciones de meter línea, y
                 * el día que una cambie la otra se queda atrás sin que nadie lo note.
                 */
                rapidos,

                /**
                 * Hasta qué porcentaje puede rebajar este usuario, o null si no tiene tope.
                 *
                 * Es un AVISO, no la regla: quien manda es el servidor, que vuelve a comprobarlo al
                 * facturar. Esto está para que nadie arme el ticket entero y se lleve el rechazo con
                 * el cliente delante.
                 */
                topeDescuento,

                /*
                 * Los mismos gestos que en el Punto de Venta: la fila marcada, la cantidad de la línea
                 * nueva y las columnas que se adaptan a lo que traigan los resultados. Las dos
                 * pantallas buscan contra el mismo presenter; que además se manejen igual es lo que
                 * evita tener que aprenderse dos mostradores.
                 */
                marcado: -1,
                nuevaCant: '',
                resultsPara: '',
                col: { imagen: false, vehiculo: false, ubicacion: false },

                /*
                 * LA FICHA del artículo marcado, igual que en el Punto de Venta.
                 *
                 * Solo informa: no pide cantidad ni descuento ni tiene botón de agregar, porque eso
                 * ya vive en la rejilla y tenerlo en dos sitios eran dos verdades para el mismo dato.
                 * Su trabajo es que quien atiende compruebe que esa es la pieza correcta —el número
                 * de parte, en qué almacén está— antes de facturarla, que con un nombre y un precio
                 * no se podía.
                 */
                ficha: null,

                rd(n) {
                    return 'RD$ ' + (parseFloat(n) || 0).toLocaleString('es-DO', {
                        minimumFractionDigits: 2, maximumFractionDigits: 2,
                    });
                },

                /** La existencia con su unidad, cuando la unidad dice algo. */
                /*
                 * Los tres, con los mismos nombres y la misma regla que el Punto de Venta. Estaban
                 * fundidos en uno solo, y la ficha necesita las piezas por separado: si un artículo
                 * se vende por litros, la fila de «Unidad» sobra cuando son unidades sueltas.
                 */
                limpio(n) {
                    return String(Math.round((parseFloat(n) || 0) * 1000) / 1000);
                },

                unidadPropia(p) {
                    const u = String(p?.unit ?? '').trim().toLowerCase();

                    return u !== '' && u !== 'unidad' && u !== 'unidades';
                },

                existencia(p) {
                    return this.limpio(p.stock) + ' ' + (this.unidadPropia(p) ? p.unit : 'u.');
                },

                async search() {
                    const q = this.query.trim();
                    if (q.length < 2) { this.results = []; this.resultsPara = ''; this.marcado = -1; this.ficha = null; return; }
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
                        // La fila 2 de la búsqueda anterior no es la fila 2 de esta, y la ficha se
                        // cierra por lo mismo: abierta seguiría enseñando el artículo de ANTES
                        // mientras la tabla ya muestra otros.
                        this.marcado = -1;
                        this.ficha = null;
                        this.busy = false;
                    }
                },

                /** Una columna solo se pinta si algún resultado trae ese dato. */
                calcularColumnas() {
                    const alguno = (campo) => this.results.some((p) => {
                        const v = p[campo];

                        return v !== null && v !== undefined && String(v).trim() !== '';
                    });

                    this.col = { imagen: alguno('image'), vehiculo: alguno('vehicle'), ubicacion: alguno('location') };
                },

                /** Marca una fila y enseña su ficha. No añade nada al ticket: solo informa. */
                marcar(i) {
                    if (i < 0 || i >= this.results.length) return;
                    this.marcado = i;
                    this.ficha = this.results[i];
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
                    /*
                     * `parseFloat` y no `parseInt`: hay negocios que despachan por peso o por metro, y
                     * con el entero «0,5» entraba como cero y se corregía a uno. El mínimo es un
                     * milésimo, el mismo que usa el servidor al armar la línea.
                     */
                    const cantidad = Math.max(0.001, parseFloat(this.nuevaCant) || 1);
                    const it = this.cart.find(i => i.id === p.id);
                    if (it) it.qty = Math.round((it.qty + cantidad) * 1000) / 1000;
                    else this.cart.push({ id: p.id, sku: p.sku, name: p.name, price: parseFloat(p.price), qty: cantidad, discount: 0 });

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
                /** Importe de una línea: (precio × cantidad) − descuento, nunca negativo. */
                importe(i) {
                    return Math.max(0, (parseFloat(i.price) || 0) * (parseFloat(i.qty) || 0) - (parseFloat(i.discount) || 0));
                },

                /** Lo que se cobra: la suma de las líneas ya con su descuento aplicado. */
                get total() { return this.cart.reduce((s, i) => s + this.importe(i), 0); },

                /** Lo que se rebajó en total, para poder enseñarlo como una línea del resumen. */
                get descuento() {
                    return this.cart.reduce((s, i) => s + (parseFloat(i.discount) || 0), 0);
                },

                /*
                 * Base e ITBIS, con la misma regla que `TaxCalculator`.
                 *
                 * Con el precio ya impuesto incluido —lo habitual aquí— la base se saca hacia atrás
                 * dividiendo, y el impuesto POR DIFERENCIA y no multiplicando: así base + ITBIS cuadra
                 * al céntimo con el total y no aparece un descuadre de un centavo en el comprobante.
                 */
                get base() {
                    const tasa = parseFloat(this.itbis?.tasa) || 0;
                    if (tasa === 0) return this.total;

                    return this.itbis.incluido ? this.total / (1 + tasa / 100) : this.total;
                },
                get impuesto() { return Math.max(0, this.total - this.base); },

                /** El NCF que saldría con el tipo elegido, o null si ese tipo no puede emitir. */
                get proximoNcf() { return this.siguienteNcf?.[this.ncfType] ?? null; },

                get change() { const p = parseFloat(this.paid || 0); return Math.max(0, p - this.total); },
                get requiresTaxId() {
                    const opt = this.$el?.querySelector(`select[name=type] option[value="${this.ncfType}"]`);
                    return opt?.dataset.requires === '1';
                },
                /**
                 * Por qué NO se puede facturar, en una frase, o null si sí se puede.
                 *
                 * Devuelve el motivo en vez de un booleano a propósito: un botón apagado sin decir
                 * por qué obliga a adivinar, y quien está cobrando tiene un cliente delante. El
                 * orden es el de lo que hay que arreglar primero.
                 */
                /*
                 * El bruto: lo que costaría el ticket SIN rebajas. Es la base contra la que se mide
                 * el tope; medirlo sobre el neto haría que el límite diera de sí cuanto más se rebaja.
                 */
                get bruto() {
                    return this.cart.reduce((s, i) => s + (parseFloat(i.price) || 0) * (parseFloat(i.qty) || 0), 0);
                },

                /** El descuento máximo en dinero para este usuario, o null si no tiene tope. */
                get maximoRebaja() {
                    return this.topeDescuento === null ? null : this.bruto * this.topeDescuento / 100;
                },

                get descuentoExcedido() {
                    return this.maximoRebaja !== null && this.descuento > this.maximoRebaja + 0.001;
                },

                get motivoParaNoFacturar() {
                    if (this.procesando) return 'Emitiendo la factura…';
                    if (this.cart.length === 0) return 'Agrega al menos una pieza al ticket.';
                    if (this.total <= 0) return 'El total tiene que ser mayor que cero.';
                    if (this.descuentoExcedido) {
                        return 'Puedes rebajar hasta ' + this.rd(this.maximoRebaja) + ' (' + this.topeDescuento + '% de la venta).';
                    }
                    if (!this.proximoNcf) return 'No hay secuencia activa para este tipo de comprobante.';
                    if (this.requiresTaxId && !this.taxId.trim()) return 'Este comprobante exige el RNC o la cédula del cliente.';

                    if (this.dividido) {
                        if (this.lineasDeReparto().length === 0) return 'Escribe cuánto se cobra por cada forma de pago.';
                        if (this.pendienteReparto > 0.001) return 'Faltan ' + this.rd(this.pendienteReparto) + ' por cubrir.';

                        return null;
                    }

                    if (parseFloat(this.paid || 0) < this.total) return 'El pago recibido no cubre el total.';

                    return null;
                },
                get canInvoice() { return this.motivoParaNoFacturar === null; },
            };
        }
    </script>
</x-layouts.admin>
