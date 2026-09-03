<x-layouts.admin title="Punto de Venta" heading="Punto de Venta" subheading="Arma el ticket, cobra y descuenta stock en tiempo real">
    @php
        $opt = $posConfig['options'];
        // Se resuelve UNA vez y con la misma regla que usa el cobro, para que lo que se ve en
        // pantalla y lo que se descuenta no puedan discrepar nunca.
        $almacenDelTurno = $openSession?->almacenDeSalida();

        /*
         * Las columnas de la rejilla: seis fijas más las que el negocio haya activado.
         *
         * Se cuenta aquí y no a ojo en cada `colspan`: son tres sitios que tienen que cuadrar —la
         * fila en blanco, el aviso de ticket vacío y la cabecera— y desfasarlos rompe la tabla de una
         * forma que solo se ve mirándola.
         */
        $columnasRejilla = 6
            + (int) $opt['line_discount']
            + (int) $opt['line_note']
            + (int) $opt['serial']
            + (int) $opt['attendant'];
    @endphp

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
            <form method="POST" action="{{ route('panel.pos.open') }}" class="flex flex-wrap items-end gap-3">
                @csrf
                <div class="flex-1 text-left">
                    <label class="bmos-field-label">Fondo de apertura</label>
                    <input type="number" name="opening_amount" step="0.01" min="0" value="1000" required class="bmos-input">
                </div>

                {{-- De qué almacén sale la mercancía del turno. Con UN solo almacén no se pregunta:
                     no hay nada que decidir y un desplegable de un elemento solo estorba. --}}
                @if (count($warehouses) > 1)
                    <div class="flex-1 text-left">
                        <label class="bmos-field-label">Almacén</label>
                        <select name="warehouse_id" class="bmos-input">
                            @foreach ($warehouses as $w)
                                <option value="{{ $w->id }}">{{ $w->name }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif

                <button type="submit" class="bmos-btn bmos-btn-primary">Abrir caja</button>
            </form>
        </div>
    @else
        <div x-data="posTerminal('{{ route('panel.pos.lookup') }}', '{{ route('panel.pos.search') }}', '{{ route('panel.pos.catalogo') }}', @js($negocio), @js($openSession?->id))"
             x-init="arrancarSinLinea()"
             @codigo-escaneado="barcode = $event.detail.codigo; scan()">
            {{-- Barra de sesión --}}
            <div class="mb-5 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-slate-200 bg-white px-4 py-3">
                {{-- flex-wrap: con el almacén dentro, la barra ya no cabe en un teléfono y sin
                     salto de línea empujaba la página entera a lo ancho —medido: 456 px de contenido
                     en una pantalla de 390—. Una página que se arrastra en horizontal deja el botón
                     de cobrar fuera de la vista. --}}
                <div class="flex flex-wrap items-center gap-2 text-sm">
                    <span class="bmos-badge badge-green">Caja abierta</span>
                    <span class="text-slate-500">Fondo: <b>{{ money($openSession->opening_amount) }}</b></span>
                    <span class="text-slate-400">· desde {{ $openSession->opened_at?->format('d/m H:i') }}</span>

                    {{-- El almacén SIEMPRE se ve, aunque haya uno solo: quien cobra tiene que poder
                         saber de dónde está saliendo la mercancía sin ir a buscarlo a otra pantalla.
                         Con varios, además se puede cambiar sin cerrar el turno. --}}
                    @if ($almacenDelTurno)
                        <span class="pos-almacen-barra">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 21h16.5M4.5 3h15M5.25 3v18m13.5-18v18M9 6.75h1.5m-1.5 3h1.5m-1.5 3h1.5m3-6H15m-1.5 3H15m-1.5 3H15M9 21v-3.375c0-.621.504-1.125 1.125-1.125h3.75c.621 0 1.125.504 1.125 1.125V21"/></svg>
                            <b>{{ $almacenDelTurno->name }}</b>
                        </span>

                        @if (count($warehouses) > 1)
                            <form method="POST" action="{{ route('panel.pos.warehouse') }}" class="inline-flex">
                                @csrf
                                {{--
                                    autocomplete="off", y no es rutina.

                                    El navegador restaura el valor de los desplegables al recargar, y
                                    si restaura uno distinto del que pintó el servidor dispara
                                    `change` — que aquí envía el formulario—. Resultado: el almacén del
                                    turno cambiaría solo, sin que nadie lo tocara, y las ventas
                                    siguientes saldrían de otro sitio sin aviso. Se vio una vez un
                                    cambio de almacén que nadie hizo y esta es la explicación que
                                    encaja.
                                --}}
                                <select name="warehouse_id" onchange="this.form.submit()" autocomplete="off"
                                        class="pos-almacen-cambiar" aria-label="Cambiar el almacén del turno">
                                    @foreach ($warehouses as $w)
                                        <option value="{{ $w->id }}" @selected($w->id === $almacenDelTurno->id)>{{ $w->name }}</option>
                                    @endforeach
                                </select>
                            </form>
                        @endif
                    @endif
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

            {{-- Tres cuartos para el documento y uno para los totales, no dos tercios.
                 Medido: con siete columnas la rejilla pide 662 px y en dos tercios el hueco eran 586,
                 así que el PRECIO UNITARIO se quedaba escondido detrás de las columnas clavadas. La
                 columna de totales no necesitaba ese ancho: son cuatro cifras y un botón. --}}
            <div class="grid grid-cols-1 gap-5 lg:grid-cols-4">
                {{-- Catálogo --}}
                <div class="lg:col-span-3">
                    {{--
                        LA REJILLA: el ticket es el documento, como en un sistema de escritorio.

                        VA FUERA DEL FORMULARIO DE COBRO, y eso no es casualidad de la maquetación.
                        Dentro, el Enter del lector enviaría el formulario y cobraría una venta a
                        medio armar. El formulario solo necesita el carrito serializado en su campo
                        oculto, así que la rejilla puede vivir aquí y quedarse estructuralmente a
                        salvo en vez de depender de que un `.prevent` no falle nunca.
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
                                        @if ($opt['line_discount'])
                                            <th class="pos-num pos-rej-desc">Desc.</th>
                                        @endif
                                        @if ($opt['serial'])
                                            <th class="pos-rej-texto">Nº serie</th>
                                        @endif
                                        @if ($opt['attendant'])
                                            <th class="pos-rej-texto">Empleado</th>
                                        @endif
                                        @if ($opt['line_note'])
                                            <th class="pos-rej-texto">Nota</th>
                                        @endif
                                        <th class="pos-num pos-rej-precio">Precio</th>
                                        <th class="pos-num pos-rej-importe">Importe</th>
                                        <th class="pos-rej-quitar"><span class="sr-only">Quitar</span></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <template x-for="(item, i) in cart" :key="item.id">
                                        <tr>
                                            <td data-rotulo="Cant." class="pos-rej-cant">
                                                {{-- La cantidad se escribe SIEMPRE. Lo que decide la opción de venta
                                                     por peso es el paso, no si el campo existe: vender doce tornillos
                                                     no puede costar once pulsaciones de «+». --}}
                                                <input type="number" step="{{ $opt['decimal_qty'] ? '0.001' : '1' }}" min="0"
                                                       x-model.number="item.qty" aria-label="Cantidad"
                                                       class="pos-celda pos-num">
                                            </td>
                                            <td data-rotulo="Clave" class="pos-mono pos-rej-clave" x-text="item.sku || '—'"></td>
                                            <td data-rotulo="Descripción" class="pos-recorta" :title="item.name">
                                                <span class="pos-valor" x-text="item.name"></span>
                                            </td>
                                            @if ($opt['line_discount'])
                                                <td data-rotulo="Desc." class="pos-rej-desc">
                                                    <input type="number" step="0.01" min="0" x-model.number="item.discount"
                                                           aria-label="Descuento" placeholder="0" class="pos-celda pos-num">
                                                </td>
                                            @endif
                                            {{-- Serie, empleado y nota por línea. Estaban en el ticket de antes y se
                                                 conservan: un negocio que vende equipos apunta el IMEI en cada venta, y
                                                 perderlo al cambiar la pantalla lo dejaría sin poder identificar lo que
                                                 vendió cuando alguien vuelva con una garantía. --}}
                                            @if ($opt['serial'])
                                                <td data-rotulo="Nº serie" class="pos-rej-texto">
                                                    <input type="text" x-model="item.serial" aria-label="Nº de serie"
                                                           placeholder="—" class="pos-celda">
                                                </td>
                                            @endif
                                            @if ($opt['attendant'])
                                                <td data-rotulo="Empleado" class="pos-rej-texto">
                                                    <select x-model="item.employeeId" aria-label="Empleado de la línea" class="pos-celda">
                                                        <option value="">—</option>
                                                        @foreach ($employees as $emp)
                                                            <option value="{{ $emp->id }}">{{ $emp->name }}</option>
                                                        @endforeach
                                                    </select>
                                                </td>
                                            @endif
                                            @if ($opt['line_note'])
                                                <td data-rotulo="Nota" class="pos-rej-texto">
                                                    <input type="text" x-model="item.note" aria-label="Nota de la línea"
                                                           placeholder="—" class="pos-celda">
                                                </td>
                                            @endif

                                            {{-- El precio NO se escribe: al cobrar, el servidor lo relee de la base e
                                                 ignora lo que mande el navegador. Un campo aquí mentiría en silencio. --}}
                                            <td data-rotulo="Precio" class="pos-num pos-rej-precio" x-text="rd(item.price)"></td>
                                            <td data-rotulo="Importe" class="pos-num pos-rej-total" x-text="rd(lineNet(item))"></td>
                                            <td class="pos-rej-quitar">
                                                <button type="button" @click="cart.splice(i, 1)" class="pos-quitar" aria-label="Quitar la línea">&times;</button>
                                            </td>
                                        </tr>
                                    </template>

                                    {{--
                                        LA FILA EN BLANCO, que es la captura matricial y también el destino del lector.

                                        Sustituye a la caja de escaneo que había arriba en vez de sumarse a ella: el
                                        lector de pistola es un teclado, escribe en el campo enfocado y pulsa Enter, así
                                        que le da igual dónde esté el campo. Tener los dos habría sido, otra vez, dos
                                        maneras de hacer lo mismo.
                                    --}}
                                    <tr class="pos-rej-nueva">
                                        <td data-rotulo="Cant." class="pos-rej-cant">
                                            <input type="number" step="{{ $opt['decimal_qty'] ? '0.001' : '1' }}" min="0"
                                                   x-model.number="nuevaCant" @keydown.enter.prevent="$refs.scanInput.focus()"
                                                   aria-label="Cantidad de la línea nueva" placeholder="1" class="pos-celda pos-num">
                                        </td>
                                        <td colspan="{{ $columnasRejilla - 1 }}">
                                            {{--
                                                UN SOLO CAMPO para las tres formas de meter un artículo.

                                                Antes había una caja de búsqueda arriba y esta celda
                                                aquí: la de arriba buscaba por nombre y esta exigía el
                                                código exacto, así que teclear «bom» aquí no encontraba
                                                nada y había que saber cuál de los dos campos usar.

                                                Ahora el lector, la clave exacta y la búsqueda por
                                                letras entran por el mismo sitio: se teclea, aparecen
                                                debajo las coincidencias, y las flechas y el Enter
                                                hacen el resto sin soltar el teclado.
                                            --}}
                                            <input id="pos-scan" type="text" x-ref="scanInput" x-model="barcode"
                                                   @input.debounce.250ms="searchProducts()"
                                                   @keydown.enter.prevent="scan()"
                                                   @keydown.arrow-down.prevent="mover(1)"
                                                   @keydown.arrow-up.prevent="mover(-1)"
                                                   @keydown.escape="results = []; ficha = null; marcado = -1"
                                                   autofocus autocomplete="off"
                                                   placeholder="Pasa el lector, teclea la clave, o unas letras para buscar"
                                                   class="pos-celda font-mono">
                                        </td>
                                    </tr>

                                    <tr x-show="cart.length === 0" x-cloak>
                                        <td colspan="{{ $columnasRejilla }}" class="pos-rej-vacio">
                                            Pasa el lector, teclea la clave, o unas letras para buscar.
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <p x-show="scanError" x-cloak x-text="scanError"
                           class="mt-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-700"></p>

                        {{-- La cámara reutiliza el mismo scan(): para el servidor no hay diferencia entre
                             un código leído con pistola, tecleado o visto por la cámara. --}}
                        <x-panel.camera-scanner />
                    </div>

                    {{-- Las sugerencias van DEBAJO de la rejilla, que es donde está la vista al teclear
                         la clave. Arriba obligaban a mirar a otra parte de la pantalla. --}}
                    <div class="mt-4">
                        {{-- Con rótulo: una tabla que aparece sola debajo del ticket, sin decir de qué
                             es, se confunde con parte del propio ticket. --}}
                        <p x-show="results.length > 0" x-cloak class="pos-sug-titulo">
                            Coincidencias
                            <span x-text="'(' + results.length + ')'"></span>
                            <span class="pos-sug-ayuda">Enter mete la primera · ↑↓ para elegir otra</span>
                        </p>

                    {{--
                        LA TABLA, y no la rejilla de fotos que había antes.

                        En un colmado una foto basta para saber qué es «Coca-Cola 2L». En una
                        ferretería no: hay tres bombas de agua que solo se distinguen por marca,
                        aplicación y estante, y con una foto y un nombre el dependiente acaba yendo
                        al almacén a mirar —o vendiendo la pieza equivocada—.

                        Las COLUMNAS SE ADAPTAN: una solo se pinta si algún resultado trae ese dato.
                        Así el mismo mostrador sirve a un colmado —que no verá nunca «Nº de parte»—
                        y a un taller, sin que nadie elija un modo.

                        El scroll horizontal es del recuadro, no de la página: una tabla que empuja
                        la pantalla a lo ancho deja el ticket fuera de la vista.
                    --}}
                    <div x-show="results.length > 0" x-cloak class="bmos-tabla-envoltura">
                        <table class="bmos-table pos-tabla">
                            <thead>
                                <tr>
                                    <th x-show="col.imagen" class="pos-col-img"><span class="sr-only">Foto</span></th>
                                    {{-- El artículo se lleva dentro su SKU, su número de parte y su
                                         marca. Como columnas propias no cabían: siete columnas piden
                                         731 px y el hueco del mostrador son 631, así que la ubicación
                                         se quedaba cortada debajo de las fijas. Un mostrador de verdad
                                         también los pone juntos: son la identidad de la pieza, no tres
                                         datos que se comparen por separado. --}}
                                    <th>Artículo</th>
                                    <th x-show="col.vehiculo">Aplica a</th>
                                    <th x-show="col.ubicacion">Ubicación</th>
                                    <th class="pos-num pos-col-exist">Existencia</th>
                                    <th class="pos-num pos-col-precio">Precio</th>
                                </tr>
                            </thead>
                            <tbody>
                                <template x-for="(p, i) in results" :key="p.id">
                                    {{-- Un clic MANDA EL ARTÍCULO AL TICKET, no abre un formulario.
                                         Es como se añade en todo el sistema: tocando el producto en
                                         Venta rápida, con un clic en el Mostrador de repuestos y
                                         leyendo el código con el lector de esta misma pantalla. Tener
                                         dos maneras según de dónde venga el artículo obligaba a quien
                                         atiende a saber cuál estaba usando. --}}
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

                        SOLO INFORMA. No pide cantidad ni descuento ni tiene botón de agregar: eso ya
                        vive en el ticket, y tenerlo en los dos sitios eran dos verdades para el mismo
                        dato. Su trabajo es que quien atiende sepa que esa es la pieza correcta antes
                        de meterla, que es lo que no se podía hacer con una foto y un nombre.

                        Un artículo de colmado no enseña «Aplica a:» en blanco. Un rótulo sin valor no
                        es información, es un hueco que hace dudar de si el dato falta o el sistema
                        falla.
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

                                {{-- Dónde está la existencia. Un total de «12» no sirve si ocho están
                                     en la sucursal del otro lado: se le diría que sí a un cliente y
                                     luego no habría qué entregarle. --}}
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

                    {{-- Se miran los RESULTADOS y no lo tecleado: al meter una línea la celda de
                         clave se limpia para el siguiente artículo, pero la lista se queda para poder
                         añadir otro de la misma búsqueda sin volver a escribir. --}}
                    <p x-show="results.length === 0 && !searching && barcode.trim().length < 2"
                       class="py-6 text-center text-sm text-slate-400">
                        Teclea unas letras en <b>Clave</b> y aquí aparece lo que empieza por ahí.
                    </p>
                    <p x-show="searching" x-cloak class="py-8 text-center text-sm text-slate-400">Buscando…</p>
                    <p x-show="results.length === 0 && !searching && barcode.trim().length >= 2" x-cloak class="bmos-empty">
                        Sin coincidencias para «<span x-text="barcode"></span>».
                    </p>

                    </div>
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
                                                data-tono="{{ $option->tono() }}"
                                                :aria-pressed="method === '{{ $option->value }}'"
                                                :class="method === '{{ $option->value }}' && 'is-activa'"
                                                class="bmos-pos-opcion">
                                            <span>{{ $option->label() }}</span>
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
                    /*
                     * Lo tecleado vive SOLO en `barcode`.
                     *
                     * Había además un `query` para la caja de búsqueda de arriba. Dos campos de texto
                     * que buscaban lo mismo, y quien atendía tenía que saber cuál usar: uno aceptaba
                     * nombres y el otro exigía el código exacto. Ahora es uno.
                     */
                    results: [], searching: false,

                    /*
                     * Para qué texto son las sugerencias que hay ahora en pantalla.
                     *
                     * Es lo que distingue «el dependiente tecleó bat y ya está viendo las batidas» de
                     * «acaba de dispararse el lector y la lista es de la búsqueda anterior». Sin esta
                     * marca, un disparo del lector con una lista vieja delante metería un artículo de
                     * esa lista en vez del que se acaba de escanear.
                     */
                    resultsPara: '',


                    /*
                     * Qué fila está marcada, qué artículo se está mirando y con qué cantidad.
                     *
                     * `marcado` es un ÍNDICE y no un id porque se mueve con las flechas: lo que hace
                     * falta es «el siguiente», y con el id habría que buscarlo en la lista cada vez.
                     */
                    marcado: -1,
                    ficha: null,

                    /*
                     * La cantidad de la fila en blanco: «4», Tab, la clave, Enter.
                     *
                     * Es lo que distingue una captura matricial de una lista: quien despacha cuatro
                     * metros de cable teclea el cuatro antes del código, no mete la línea y luego la
                     * corrige. No es un segundo sitio para la cantidad —es la celda de la línea que
                     * se está creando—; en cuanto la línea existe, manda su propia celda.
                     */
                    nuevaCant: '',

                    /*
                     * Qué columnas tienen algo que enseñar.
                     *
                     * Se calcula sobre los resultados que hay en pantalla, no sobre el catálogo: una
                     * ferretería nunca verá «Aplica a» y un taller la verá siempre, sin que nadie
                     * configure un modo. Una columna entera de guiones no es información.
                     */
                    col: { imagen: false, vehiculo: false, ubicacion: false },

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
                        const q = this.barcode.trim();
                        if (q.length < 2) { this.results = []; this.resultsPara = ''; this.trasBuscar(); this.searching = false; return; }

                        this.searching = true;
                        try {
                            const res = await fetch(searchUrl + '?q=' + encodeURIComponent(q), {
                                headers: { Accept: 'application/json' },
                            });
                            if (res.ok) {
                                const data = await res.json();
                                this.results = data.results || [];
                            } else {
                                /*
                                 * UNA BÚSQUEDA QUE FALLA TIENE QUE DECIRLO.
                                 *
                                 * Antes este caso no hacía nada: la lista se quedaba como estaba
                                 * —vacía— y en pantalla se leía igual que «no hay resultados». Un
                                 * 403 por permisos o un 500 del servidor eran indistinguibles de un
                                 * catálogo sin ese artículo, así que el cajero le decía a un cliente
                                 * que no hay algo que sí está, y nadie lo reportaba como fallo.
                                 *
                                 * Se distingue el 403 porque tiene arreglo distinto: no es que el
                                 * sistema esté roto, es que a este usuario le falta permiso.
                                 */
                                this.results = [];
                                this.scanError = res.status === 403
                                    ? 'Tu usuario no tiene permiso para buscar productos aquí. Pídeselo al dueño.'
                                    : 'No se pudo buscar (error ' + res.status + '). Vuelve a intentarlo o recarga la página.';
                            }
                        } catch {
                            // Sin línea se busca en la copia local: es la diferencia entre poder
                            // cobrar y no poder. Un fallo puntual con conexión se reintenta solo al
                            // seguir escribiendo.
                            this.results = this.buscarEnLocal(q);
                        } finally {
                            this.resultsPara = q;
                            this.trasBuscar();
                            this.searching = false;
                        }
                    },

                    /**
                     * Resuelve el código contra el servidor y mete el producto en el ticket.
                     * Reutiliza add(): el lector y los botones del catálogo acaban en el mismo sitio.
                     */
                    async scan() {
                        const code = this.barcode.trim();

                        /*
                         * SIN NADA TECLEADO, el Enter mete la fila marcada.
                         *
                         * Es el segundo Enter de «teclea, Enter marca, Enter mete», y también el que
                         * se pulsa después de bajar con las flechas.
                         *
                         * El orden importa y costó un fallo: la comprobación de la fila marcada
                         * estaba ANTES de mirar lo tecleado, así que con una fila marcada un disparo
                         * del lector metía esa fila en vez del artículo escaneado. Un código exacto
                         * gana siempre.
                         */
                        if (code === '') {
                            if (this.marcado >= 0 && this.results[this.marcado]) {
                                this.elegir(this.marcado);
                            }

                            return;
                        }

                        /*
                         * SI LAS SUGERENCIAS SON DE ESTE TEXTO, se mete una y no se pregunta a nadie.
                         *
                         * El camino de antes pasaba siempre por el servidor para ver si lo tecleado
                         * era un código exacto: medido, alrededor de un segundo de espera con la
                         * respuesta ya en pantalla. En un mostrador eso se nota en cada línea.
                         *
                         * Y solo cuando la lista es DE ESTE TEXTO: si es de la búsqueda anterior, un
                         * disparo del lector metería un artículo de aquella lista en vez del que se
                         * acaba de escanear. Por eso no basta con mirar si hay sugerencias.
                         */
                        if (this.resultsPara === code && this.results.length > 0) {
                            this.elegir(this.marcado >= 0 ? this.marcado : 0);

                            return;
                        }

                        if (this.busy) return;

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
                                /*
                                 * No es un código, pero puede ser el principio de un nombre.
                                 *
                                 * Con sugerencias en pantalla se marca la primera y el siguiente
                                 * Enter la mete: dos pulsaciones y sin tocar el ratón. Decir «código
                                 * no encontrado» teniendo la lista delante sería absurdo.
                                 */
                                if (this.results.length > 0) {
                                    /*
                                     * UN SOLO ENTER, no dos.
                                     *
                                     * Se intentó que el primero marcara y el segundo metiera, y la
                                     * regla se mordía la cola: al conservar lo tecleado, el segundo
                                     * Enter repetía el mismo camino y volvía a marcar sin meter nada.
                                     *
                                     * Ahora entra la fila MARCADA si se eligió con las flechas, y la
                                     * primera si no se tocó ninguna. Es lo que hace cualquier lista de
                                     * sugerencias, y con lo que empieza por lo tecleado ordenado
                                     * primero, la primera suele ser la que se quería.
                                     */
                                    this.elegir(this.marcado >= 0 ? this.marcado : 0);
                                } else {
                                    this.scanError = 'No hay nada que empiece por: ' + code;
                                }
                            } else if (!data.product.sellable) {
                                this.scanError = data.product.reason === 'no_stock'
                                    ? 'Sin existencia: ' + data.product.name
                                    : 'Producto inactivo: ' + data.product.name;
                            } else {
                                this.add(data.product.id, data.product.name, data.product.price, data.product.image, data.product.sku, this.nuevaCant || 1);
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
                                this.add(local.id, local.name, local.price, local.image, local.sku, this.nuevaCant || 1);
                            } else {
                                this.scanError = 'Sin conexión y ese código no está en la copia guardada: ' + code;
                            }
                        } finally {
                            /*
                             * Se limpia lo tecleado y se recupera el foco.
                             *
                             * Lo segundo es la mitad del valor: si el foco se pierde, el siguiente
                             * disparo del lector se escribe en el vacío. Y lo primero evita que la
                             * clave siguiente se pegue a la anterior y salga «batDEMO-MALTA», que es
                             * exactamente lo que pasó al probarlo.
                             */
                            this.barcode = '';
                            this.busy = false;
                            this.$refs.scanInput.focus();
                        }
                    },

                    /**
                     * Lo que hay que rehacer cada vez que cambian los resultados.
                     *
                     * La ficha se cierra a propósito: si se quedara abierta, seguiría enseñando el
                     * artículo de la búsqueda ANTERIOR mientras la tabla ya muestra otros, y quien
                     * atiende acabaría metiendo al ticket algo que ya no está mirando.
                     */
                    trasBuscar() {
                        this.calcularColumnas();
                        // Aquí SÍ se reinicia: la fila 2 de la búsqueda anterior no es la fila 2 de esta.
                        this.marcado = -1;
                        this.ficha = null;
                    },

                    /** Decide qué columnas se pintan mirando lo que traen los resultados. */
                    calcularColumnas() {
                        const alguno = (campo) => this.results.some((p) => {
                            const v = p[campo];

                            return v !== null && v !== undefined && String(v).trim() !== '';
                        });

                        /*
                         * El número de parte y la marca NO tienen bandera propia: van dentro de la
                         * celda del artículo, junto al SKU, y ahí cada fila decide sola si los pinta.
                         * Como columnas aparte no cabían —siete columnas piden 731 px y el hueco del
                         * mostrador son 631— y la ubicación se quedaba cortada bajo las fijas.
                         */
                        this.col = {
                            imagen: alguno('image'),
                            vehiculo: alguno('vehicle'),
                            ubicacion: alguno('location'),
                        };
                    },

                    /** Marca una fila y enseña su ficha. No añade nada: solo informa. */
                    marcar(i) {
                        if (i < 0 || i >= this.results.length) return;

                        this.marcado = i;
                        this.ficha = this.results[i];
                    },

                    /**
                     * Elegir una fila: al ticket, y su ficha queda a la vista.
                     *
                     * Las dos cosas juntas a propósito. Añadir es lo que se quiere el noventa por
                     * ciento de las veces, y la ficha abierta sirve para comprobar, DESPUÉS y sin
                     * haber perdido tiempo, que la pieza que entró es la correcta.
                     */
                    /**
                     * Por qué no se puede vender esto, en palabras.
                     *
                     * Antes se ignoraba en silencio: se pulsaba Enter sobre una fila agotada y no
                     * pasaba absolutamente nada. Quien atiende no sabe si el sistema se colgó, si no
                     * le registró la tecla o si el artículo no se puede vender, y acaba pulsando otras
                     * tres veces con el cliente delante.
                     */
                    porQueNo(p) {
                        const nombre = p?.name ?? 'Ese artículo';

                        if (p?.reason === 'no_stock') return 'Sin existencia: ' + nombre;
                        if (p?.reason === 'unavailable') return 'Hoy no hay: ' + nombre;
                        if (p?.reason === 'inactive') return 'Está inactivo: ' + nombre;

                        return 'No se puede vender: ' + nombre;
                    },

                    elegir(i) {
                        this.marcar(i);

                        const p = this.results[i];

                        if (p && p.sellable) {
                            this.add(p.id, p.name, p.price, p.image, p.sku);

                            return;
                        }

                        this.scanError = this.porQueNo(p);
                    },

                    /** Sube o baja por la lista con las flechas, sin salirse por los extremos. */
                    mover(paso) {
                        if (this.results.length === 0) return;

                        const siguiente = this.marcado < 0
                            ? 0
                            : Math.min(this.results.length - 1, Math.max(0, this.marcado + paso));

                        this.marcar(siguiente);
                    },

                    /**
                     * La existencia con su unidad, cuando la unidad dice algo.
                     *
                     * En la base `unit` no admite nulos y viene con «unidad» de fábrica, así que
                     * pintarla siempre llenaría la columna de «12 unidad» en todas las filas: ruido
                     * que no distingue nada. Solo se enseña cuando el negocio la cambió a algo que sí
                     * informa —lb, m, galón, caja—, que es donde confundirse cuesta dinero.
                     */
                    existencia(p) {
                        return this.limpio(p.stock) + ' ' + (this.unidadPropia(p) ? p.unit : 'u.');
                    },

                    /** ¿La unidad es una decisión del negocio, o el valor de fábrica? */
                    unidadPropia(p) {
                        const u = String(p.unit ?? '').trim().toLowerCase();

                        return u !== '' && u !== 'unidad' && u !== 'unidades';
                    },

                    /** Quita los decimales que no dicen nada: «12.000» se lee «12». */
                    limpio(n) {
                        const v = parseFloat(n) || 0;

                        return String(Math.round(v * 1000) / 1000);
                    },

                    /*
                     * Lleva CLAVE y CANTIDAD, pero NO descuento, y la diferencia importa.
                     *
                     * La clave, porque la rejilla la enseña en su columna: sin ella el documento no
                     * dice qué artículo es más allá del nombre.
                     *
                     * La cantidad, porque es la de la fila en blanco —«4», Tab, la clave, Enter—, que
                     * es la razón de ser de una captura matricial. En cuanto la línea existe, la
                     * cantidad vive en su propia celda y en ningún otro sitio.
                     *
                     * El descuento NO vuelve. Lo tuvo cuando la ficha era un formulario, y costaba
                     * caro: agregar dos veces el mismo artículo pisaba el descuento que se hubiera
                     * escrito en el ticket. Ese campo tiene un solo dueño, que es la línea.
                     */
                    add(id, name, price, image = null, sku = null, qty = 1) {
                        const cantidad = this.round(parseFloat(qty) || 1);
                        const it = this.cart.find(i => i.id === id);

                        // Repetido, se SUMA: quien escanea tres veces la misma lata espera tres.
                        if (it) {
                            it.qty = this.round(it.qty + cantidad);
                        } else {
                            this.cart.push({ id, name, sku, price: parseFloat(price), image, qty: cantidad, discount: 0, note: '', serial: '', employeeId: '' });
                        }

                        /*
                         * La fila en blanco queda limpia y con el foco, lista para la siguiente línea.
                         * Sin esto, el lector escribiría en el vacío.
                         *
                         * Las SUGERENCIAS se quedan: en una ferretería se busca «tubo» una vez y se
                         * meten tres medidas de la misma lista. Lo que se limpia es lo tecleado, para
                         * que la siguiente clave no se escriba pegada a la anterior.
                         */
                        /*
                         * Y la MARCA se suelta. Si se quedara puesta, el siguiente disparo del lector
                         * —que llega con su propio código— podría meter la fila marcada en vez del
                         * artículo escaneado. La lista se queda; lo que se suelta es la elección.
                         */
                        this.nuevaCant = '';
                        this.barcode = '';
                        this.scanError = '';
                        this.marcado = -1;
                        this.$nextTick(() => this.$refs.scanInput?.focus());
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

                        /*
                         * Los MISMOS campos que busca el servidor, ni uno menos.
                         *
                         * Antes solo miraba nombre y SKU. Con el mostrador girando en torno al número
                         * de parte, eso significaba que en un apagón la ferretería no encontraba nada
                         * buscando «GMB-125» —aunque el dato estuviera guardado en el propio
                         * navegador—, y quien atiende no tendría forma de entender por qué el mismo
                         * término funcionaba hace un minuto.
                         */
                        const campos = ['name', 'sku', 'barcode', 'part_number', 'brand', 'vehicle'];

                        return this.catalogoLocal
                            .filter((prod) => campos.some(
                                (campo) => String(prod[campo] ?? '').toLowerCase().includes(t),
                            ))
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
