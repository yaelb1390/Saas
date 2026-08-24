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
        {{-- El tablero ocupa EXACTAMENTE lo que queda de pantalla y cada columna se desplaza por
             dentro. Así el botón de Cobrar nunca se va por debajo del borde, que es lo que pasaba:
             con la pantalla de un portátil, el ticket terminaba 72 px más abajo del viewport y había
             que desplazar la página entera para poder cobrar.

             El alto se MIDE en vez de escribirse a mano porque el hueco de arriba no es constante:
             un aviso de «venta cobrada» o el banner de vencimiento de la suscripción empujan el
             tablero hacia abajo, y un `calc(100dvh - 11rem)` fijo se equivocaría justo en esos
             momentos. --}}
        <div x-data="quickPos('{{ route('panel.quick-pos.catalog') }}', @js($categories), @js($negocio), @js($openSession?->id))"
             x-init="arrancar(); medirAlto()"
             @resize.window.debounce.150ms="medirAlto()"
             :style="altoTablero"
             class="grid grid-cols-1 gap-4 lg:grid-cols-[10rem_minmax(0,1fr)_21rem] lg:overflow-hidden xl:grid-cols-[11.5rem_minmax(0,1fr)_23rem]">

            {{-- ── Categorías ───────────────────────────────────────────────────────────── --}}
            {{-- Columna lateral en pantalla grande; en móvil se convierte en una fila que se
                 desplaza, porque una columna vertical robaría el ancho que necesita la rejilla. --}}
            {{-- Se pinta desde el estado de Alpine, sembrado con los datos del servidor: así aparece
                 al instante en la primera carga y luego se actualiza sola cuando el inventario
                 cambia, sin recargar la página. --}}
            <nav class="bmos-pos-cats lg:min-h-0 lg:overflow-y-auto" aria-label="Categorías">
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
            <div class="bmos-card flex min-w-0 flex-col overflow-hidden lg:min-h-0">
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
                          <div class="relative">
                            {{-- «Hoy no hay». Va FUERA de la ficha porque un botón dentro de otro es
                                 HTML inválido y el navegador desmonta el interior en silencio.

                                 El producto agotado se queda a la vista, en gris: es lo que permite
                                 volver a encenderlo mañana desde el mismo sitio. Desactivarlo en
                                 Inventario lo haría desaparecer de aquí y el cajero se quedaría sin
                                 desde dónde revivirlo. --}}
                            @can('products.view')
                                <button type="button" @click.stop="alternarDisponible(p)"
                                        :title="p.reason === 'unavailable' ? 'Volver a tenerlo' : 'Marcar que se acabó'"
                                        :class="p.reason === 'unavailable' ? 'bg-amber-500 text-white' : 'bg-white/90 text-slate-400 hover:text-slate-700'"
                                        class="absolute right-1.5 top-1.5 z-10 grid h-8 w-8 place-items-center rounded-full shadow ring-1 ring-slate-200">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-4 w-4">
                                        <path stroke-linecap="round" stroke-linejoin="round"
                                              d="M3.98 8.223A10.477 10.477 0 0 0 1.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.451 10.451 0 0 1 12 4.5c4.756 0 8.773 3.162 10.065 7.498a10.522 10.522 0 0 1-4.293 5.774M6.228 6.228 3 3m3.228 3.228 3.65 3.65m7.894 7.894L21 21m-3.228-3.228-3.65-3.65m0 0a3 3 0 1 0-4.243-4.243"/>
                                    </svg>
                                </button>
                            @endcan
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
                                    {{-- «Se acabó» y «sin existencia» se distinguen: lo primero lo
                                         decidió alguien y se arregla con un toque; lo segundo hay que
                                         reponerlo. --}}
                                    <span x-show="!p.sellable" class="bmos-pos-tile-flag"
                                          x-text="p.reason === 'unavailable' ? 'Se acabó' : 'Agotado'"></span>
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
                          </div>
                        </template>
                    </div>

                    <p x-show="hasMore && !query" class="pt-4 text-center text-xs text-slate-400">
                        Se muestran los primeros 60 productos de esta categoría. Usa el buscador o los chips para acotar.
                    </p>
                </div>
            </div>

            {{-- ── Ticket ───────────────────────────────────────────────────────────────── --}}
            {{-- Ya no hace falta `sticky` ni un alto máximo inventado: el tablero mide lo que hay y
                 esta columna se limita a ocuparlo entero. --}}
            {{-- `data-asis-evitar`: el asistente flotante se aparta de esta columna en vez de
                 taparla. Aquí van el total, el tipo de pedido y el cobro. --}}
            <div data-asis-evitar class="bmos-card flex min-w-0 flex-col overflow-hidden lg:min-h-0">
                <div class="flex items-center justify-between gap-2 border-b border-slate-100 p-3">
                    <p class="font-semibold text-slate-800">
                        Pedido
                        {{-- Al recuperar uno aparcado se muestra su referencia: el cajero sabe cuál
                             está cobrando cuando hay varios en la barra. --}}
                        <span x-show="refActiva" x-cloak
                              class="ml-1 rounded bg-amber-100 px-1.5 py-0.5 text-xs font-bold text-amber-700"
                              x-text="refActiva"></span>
                    </p>
                    <div class="flex items-center gap-1" x-show="cart.length > 0" x-cloak>
                        <button type="button" @click="aparcar()" :disabled="aparcando"
                                class="bmos-btn bmos-btn-ghost min-h-[44px] text-xs disabled:opacity-50">
                            <span x-text="aparcando ? '...' : 'En espera'"></span>
                        </button>
                        <button type="button" @click="clear()"
                                class="bmos-btn bmos-btn-ghost min-h-[44px] text-xs">Vaciar</button>
                    </div>
                </div>

                {{-- Pedidos aparcados. Solo aparece si hay alguno: en un mostrador sin pedidos a
                     medias, una barra vacía sería ruido permanente. --}}
                <div x-show="enEspera.length > 0" x-cloak
                     class="flex gap-2 overflow-x-auto border-b border-slate-100 bg-amber-50/60 p-2">
                    <template x-for="p in enEspera" :key="p.id">
                        <div class="flex shrink-0 items-center gap-1 rounded-lg border border-amber-200 bg-white px-2 py-1">
                            <button type="button" @click="recuperar(p)" class="min-h-[40px] text-left">
                                <span class="block text-xs font-bold text-amber-700" x-text="p.reference"></span>
                                <span class="block text-[11px] text-slate-500"
                                      x-text="p.items + (p.items === 1 ? ' art.' : ' arts.') + ' · ' + rd(p.total)"></span>
                            </button>
                            <button type="button" @click="descartar(p)" aria-label="Descartar pedido"
                                    class="grid h-9 w-9 shrink-0 place-items-center rounded text-slate-300 transition hover:bg-rose-50 hover:text-rose-500">
                                ✕
                            </button>
                        </div>
                    </template>
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

                    <x-panel.estado-conexion class="mb-3" />

                    {{-- Acuse del último cobro, sin recargar. --}}
                    <div x-show="ultimaVenta" x-cloak
                         class="mb-3 rounded-lg border border-emerald-200 bg-emerald-50 p-2 text-sm text-emerald-800">
                        <p class="font-semibold" x-text="ultimaVenta?.message"></p>
                        {{-- Solo si hay recibo que abrir. Una venta cobrada sin conexión ya
                             imprimió el suyo y no tiene página en el servidor todavía: el enlace
                             llevaría a un error justo cuando no hay red para entenderlo. --}}
                        <a x-show="ultimaVenta?.receipt_url" :href="ultimaVenta?.receipt_url" target="_blank" rel="noopener"
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

                    @if ($pideTipoDePedido)
                        {{-- Cómo se lleva el cliente el pedido. Va ANTES de la forma de pago porque
                             cambia lo que se pregunta después: un envío pide dirección y puede
                             cobrarse en la puerta. --}}
                        <div class="mb-3">
                            <label class="bmos-field-label">Tipo de pedido</label>
                            <input type="hidden" name="order_type" :value="orderType">
                            <div class="grid grid-cols-{{ $ofreceEnvio ? '3' : '2' }} gap-1.5">
                                @foreach (\App\Modules\Sales\Enums\OrderType::cases() as $tipo)
                                    @continue($tipo->generaEntrega() && ! $ofreceEnvio)
                                    <button type="button" @click="orderType = '{{ $tipo->value }}'"
                                            :class="orderType === '{{ $tipo->value }}' ? 'border-indigo-500 bg-indigo-50 text-indigo-700' : 'border-slate-200 text-slate-500'"
                                            class="min-h-[44px] rounded-lg border px-1 text-xs font-semibold transition">
                                        {{ $tipo->label() }}
                                    </button>
                                @endforeach
                            </div>
                        </div>

                        @if ($ofreceEnvio)
                            {{-- A dónde va. Solo la dirección es obligatoria: sin ella la entrega no
                                 sirve de nada, y el resto se puede preguntar por teléfono. --}}
                            <div x-show="esEnvio" x-cloak class="mb-3 space-y-2 rounded-xl border border-violet-200 bg-violet-50 p-3">
                                <div>
                                    <label class="bmos-field-label">Dirección <span class="text-rose-500">*</span></label>
                                    <input type="text" name="delivery_address" x-model="envio.direccion"
                                           class="bmos-input" placeholder="Calle Duarte 45, casa amarilla">
                                </div>
                                <div class="grid grid-cols-2 gap-2">
                                    <input type="text" name="delivery_phone" x-model="envio.telefono"
                                           class="bmos-input" placeholder="Teléfono">
                                    <select name="delivery_employee_id" x-model="envio.repartidor" class="bmos-input">
                                        <option value="">Asignar solo</option>
                                        @foreach ($repartidores as $r)
                                            <option value="{{ $r->id }}">{{ $r->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <input type="text" name="delivery_notes" x-model="envio.notas"
                                       class="bmos-input" placeholder="Referencia: portón azul, timbre de abajo">

                                {{-- Quién cobra. Si lo cobra el motorista, la venta se registra a
                                     crédito y ese dinero NO entra en la caja hasta que él lo trae:
                                     lo decide el servidor a partir de esta casilla. --}}
                                <label class="flex items-center gap-2 text-sm font-medium text-violet-900">
                                    <input type="checkbox" name="delivery_pay_on_arrival" value="1"
                                           x-model="envio.pagaAlRecibir" class="rounded border-violet-300">
                                    El cliente paga al recibir
                                </label>
                            </div>
                        @endif
                    @endif

                    {{-- Forma de cobro. Solo el efectivo suma al arqueo de la caja; el servidor
                         decide eso a partir de este valor, no el navegador. --}}
                    <div class="mb-3" x-show="! cobraElMotorista" x-cloak>
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
                        <template x-if="! cobraElMotorista">
                            <div>
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
                            </div>
                        </template>

                        {{-- Lo cobra el motorista: aquí no se recibe dinero. Se dice en vez de dejar
                             un campo de pago vacío que el cajero intentaría rellenar. --}}
                        <p x-show="cobraElMotorista" x-cloak
                           class="rounded-lg bg-violet-100 px-3 py-2 text-sm font-medium text-violet-900">
                            El motorista cobra <span x-text="rd(subtotal)"></span> en la puerta. No recibas nada ahora.
                        </p>

                        {{-- `cobrando` bloquea el botón mientras vuela la petición: sin esto, un
                             doble toque impaciente cobraría la venta dos veces. --}}
                        <button type="submit" :disabled="!canPay || cobrando" class="bmos-pos-cobrar mt-3">
                            <span x-text="cobrando ? 'Cobrando...' : (cobraElMotorista ? 'Enviar pedido' : 'Cobrar')"></span>
                            <span class="bmos-pos-cobrar-total" x-text="rd(subtotal)"></span>
                        </button>
                    </div>
                </form>
            </div>

        {{-- Paso de elección: aparece al tocar un producto que ofrece tamaño, sabor o extras.

             VA DENTRO del `x-data`, y esto era un fallo: estaba fuera, así que Alpine no le daba
             ámbito y tocar un producto con tamaños o sabores lanzaba «eligiendo is not defined» sin
             abrir nada. La función se vendía y no funcionaba en el terminal.

             Ser hijo de la rejilla no le estorba: `position: fixed` lo saca del flujo, así que no
             ocupa una celda de la cuadrícula. --}}
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
        </div>

        <script>
            function quickPos(catalogUrl, categoriasIniciales, negocio, sesionCaja) {
                return {
                    catalogUrl,
                    negocio,
                    sesionCaja,
                    cats: categoriasIniciales,

                    /*
                     * El modo sin internet.
                     *
                     * `disponible` arranca en false y solo se enciende si el navegador puede guardar
                     * de verdad. Si no puede —navegación privada, almacenamiento bloqueado— el
                     * terminal NO ofrece cobrar sin línea: prometerle al cajero que la venta se
                     * guarda y perderla es mucho peor que decirle de entrada que hoy no se puede.
                     */
                    sinLinea: {
                        disponible: false,
                        conexion: navigator.onLine ? 'en-linea' : 'sin-conexion',
                        pendientes: 0,
                        apartadas: 0,
                        subiendo: false,
                        pideLogin: false,
                        catalogoDe: null,
                    },
                    // `all` guarda lo ya traído del servidor; `visible` es lo que se pinta tras
                    // aplicar chip y buscador. Separarlos evita volver a la red al escribir.
                    all: [],
                    loaded: new Set(),
                    category: null,
                    query: '',
                    loading: false,
                    hasMore: false,
                    // Alto del tablero, calculado en `medirAlto()`. Vacío en móvil, donde la página
                    // se desplaza con normalidad.
                    altoTablero: '',
                    cart: [],
                    paid: '',
                    method: 'cash',
                    // Cómo se lleva el cliente el pedido. Por omisión, para comer aquí: es lo que más
                    // pasa en un local con mesas, y el cajero no tiene que tocar nada en ese caso.
                    orderType: 'dine_in',
                    envio: { direccion: '', telefono: '', notas: '', repartidor: '', pagaAlRecibir: false },
                    cobrando: false,
                    ultimaVenta: null,
                    errorCobro: '',
                    eligiendo: null,
                    grupos: [],
                    elegidas: [],
                    cargandoOpciones: false,
                    enEspera: [],
                    aparcando: false,
                    refActiva: '',
                    holdUrl: '{{ route('panel.quick-pos.held.index') }}',
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
                    /**
                     * Ajusta el tablero para que ocupe justo lo que queda de pantalla.
                     *
                     * Sin esto, el ticket crecía con cada producto y el botón de Cobrar acababa por
                     * debajo del borde: en un portátil de 1366x768 se salía 72 px ya con el carrito
                     * VACÍO, y había que desplazar la página entera para poder cobrar. En un punto de
                     * venta eso no es un detalle estético; es el cajero buscando el botón con el
                     * cliente delante.
                     *
                     * El hueco de arriba se MIDE en lugar de escribirse a mano porque no es fijo: un
                     * aviso de «venta cobrada» o el banner de vencimiento de la suscripción empujan
                     * el tablero hacia abajo, y un alto fijo se equivocaría justo en esos momentos.
                     *
                     * Solo en pantalla grande. En el teléfono las columnas se apilan y desplazar la
                     * página es lo natural; encajarlo todo en una pantalla dejaría cada trozo
                     * inservible de pequeño.
                     */
                    medirAlto() {
                        if (window.innerWidth < 1024) {
                            this.altoTablero = '';
                            return;
                        }

                        const arriba = this.$el.getBoundingClientRect().top + window.scrollY;

                        // El margen de abajo es el mismo respiro que el padding del contenido.
                        this.altoTablero = `height: calc(100dvh - ${Math.round(arriba)}px - 1.25rem)`;
                    },

                    arrancar() {
                        this.arrancarSinLinea();
                        this.load();
                        this.cargarEnEspera();

                        // El tablero se remide cuando aparece o desaparece un aviso arriba: sin esto,
                        // tras cobrar una venta el banner de «venta cobrada» lo empujaría hacia abajo
                        // y el botón volvería a salirse.
                        if (typeof ResizeObserver !== 'undefined' && this.$el.parentElement) {
                            this._observador = new ResizeObserver(() => this.medirAlto());
                            this._observador.observe(this.$el.parentElement);
                        }

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
                        this._observador?.disconnect();
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

                            // Copia local de la carga completa. Solo la de «Todo»: guardar además
                            // cada filtro por categoría duplicaría los mismos productos.
                            if (categoryId === null) this.guardarCatalogo(data);
                        } catch (e) {
                            // Sin conexión. Se conserva lo ya cargado y, si no había nada —el
                            // terminal se abrió ya sin línea—, se tira de la última copia guardada:
                            // sin catálogo no hay precios y no se puede cobrar.
                            if (this.all.length === 0) await this.recuperarCatalogo();
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

                    get esEnvio() { return this.orderType === 'delivery'; },

                    /** Lo cobra el motorista en la puerta: aquí no se recibe dinero. */
                    get cobraElMotorista() { return this.esEnvio && this.envio.pagaAlRecibir; },

                    get canPay() {
                        if (this.cart.length === 0) return false;

                        // Un envío sin dirección no se puede mandar a ninguna parte. Se bloquea el
                        // botón además de validarlo en el servidor: descubrirlo al pulsar, con el
                        // cliente delante, es peor que no poder pulsar.
                        if (this.esEnvio && this.envio.direccion.trim() === '') return false;

                        // Si paga al recibir no hay pago que comprobar: la venta va a crédito.
                        if (this.cobraElMotorista) return true;

                        return Number(this.paid || 0) >= this.subtotal;
                    },

                    /**
                     * «Hoy no hay» / «ya volvimos a tener».
                     *
                     * Se pinta el cambio ANTES de que responda el servidor: el cajero está atendiendo
                     * y esperar medio segundo a que se ponga gris es medio segundo mirando la pantalla
                     * sin saber si el toque entró. Si el servidor lo rechaza, se deshace.
                     */
                    async alternarDisponible(p) {
                        const antes = p.reason;
                        const disponible = p.reason === 'unavailable';

                        p.reason = disponible ? null : 'unavailable';
                        p.sellable = disponible;

                        try {
                            const res = await fetch(`{{ url('panel/inventario') }}/${p.id}/disponible`, {
                                method: 'POST',
                                headers: this._cabeceras(),
                                body: JSON.stringify({ is_available: disponible }),
                            });

                            if (!res.ok) throw new Error('rechazado');
                        } catch (e) {
                            p.reason = antes;
                            p.sellable = antes === null;
                            this.errorCobro = 'No se pudo cambiar la disponibilidad.';
                        }
                    },

                    /** Cabeceras comunes de las peticiones que escriben. */
                    _cabeceras() {
                        return {
                            'Content-Type': 'application/json',
                            Accept: 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        };
                    },

                    async cargarEnEspera() {
                        try {
                            const res = await fetch(this.holdUrl, { headers: { Accept: 'application/json' } });
                            if (res.ok) this.enEspera = (await res.json()).pending;
                        } catch (e) {
                            // Sin conexión: se deja la barra como esté en vez de vaciarla.
                        }
                    },

                    /** Aparca el pedido en curso y deja el ticket libre para el siguiente cliente. */
                    async aparcar() {
                        if (this.cart.length === 0 || this.aparcando) return;

                        this.aparcando = true;
                        this.errorCobro = '';

                        try {
                            const res = await fetch(this.holdUrl, {
                                method: 'POST',
                                headers: this._cabeceras(),
                                body: JSON.stringify({
                                    // Solo qué se eligió: los precios se releen al recuperarlo.
                                    cart: this.cart.map((i) => ({
                                        id: i.id,
                                        qty: i.qty,
                                        options: (i.opciones ?? []).map((o) => o.id),
                                    })),
                                }),
                            });

                            if (!res.ok) {
                                this.errorCobro = 'No se pudo dejar el pedido en espera.';
                                return;
                            }

                            this.enEspera = (await res.json()).pending;
                            this.cart = [];
                            this.paid = '';
                            this.refActiva = '';
                        } catch (e) {
                            // NO se vacía el carrito: si la petición no llegó, el pedido se habría
                            // perdido sin que el cajero pudiera recuperarlo de ningún sitio.
                            this.errorCobro = 'Sin conexión. El pedido sigue aquí, vuelve a intentarlo.';
                        } finally {
                            this.aparcando = false;
                        }
                    },

                    /** Devuelve un pedido aparcado al ticket, con los precios de hoy. */
                    async recuperar(p) {
                        // Con un pedido a medias, recuperar otro perdería el actual sin aviso.
                        if (this.cart.length > 0 && ! await window.confirmarAccion({
                            titulo: '¿Reemplazar el pedido actual?',
                            mensaje: `Se quitará del ticket lo que hay ahora (${this.cart.length} línea${this.cart.length === 1 ? '' : 's'}) y en su lugar entrará el pedido ${p.reference}.`,
                            aviso: 'Si lo que quieres es guardarlo para después, cierra esto y púlsalo en «Aparcar».',
                            confirmar: 'Reemplazar',
                        })) {
                            return;
                        }

                        try {
                            const res = await fetch(`${this.holdUrl}/${p.id}`, { headers: { Accept: 'application/json' } });
                            if (!res.ok) return;

                            const data = await res.json();

                            this.cart = data.cart.map((i) => ({
                                ...i,
                                firma: (i.opciones ?? []).map((o) => o.id).sort().join(','),
                            }));
                            this.refActiva = data.reference;
                            this.ultimaVenta = null;

                            // Se descarta al recuperarlo: si siguiera en la barra, el cajero podría
                            // cobrarlo dos veces desde dos terminales.
                            await this.descartar(p, true);
                        } catch (e) {
                            this.errorCobro = 'No se pudo recuperar el pedido.';
                        }
                    },

                    async descartar(p, silencioso = false) {
                        // `silencioso` es el descarte que hace `recuperar()` por dentro: ahí ya se
                        // preguntó, y volver a preguntar sería incomprensible.
                        if (! silencioso && ! await window.confirmarAccion({
                            titulo: `¿Descartar el pedido ${p.reference}?`,
                            mensaje: 'Sale de la barra de pedidos en espera sin llegar a cobrarse.',
                            aviso: 'Esto no se puede deshacer: el pedido se borra y habría que volver a montarlo línea por línea.',
                            avisoGrave: true,
                            confirmar: 'Descartar',
                        })) {
                            return;
                        }

                        try {
                            const res = await fetch(`${this.holdUrl}/${p.id}`, {
                                method: 'DELETE',
                                headers: this._cabeceras(),
                            });

                            if (res.ok) this.enEspera = (await res.json()).pending;
                        } catch (e) {
                            // Se recargará la lista en el proximo refresco.
                        }
                    },

                    async cobrar() {
                        if (!this.canPay || this.cobrando) return;

                        this.cobrando = true;
                        this.errorCobro = '';

                        /*
                         * La llave de este cobro, generada ANTES de mandarlo.
                         *
                         * Es lo que convierte «no sé si llegó» en algo resoluble. Si la petición se
                         * corta a mitad, la venta se encola con esta misma llave: o el servidor ya
                         * la tiene y al subirla contestará «ya estaba», o no la tiene y entrará
                         * entonces. Nunca las dos cosas.
                         */
                        const uuid = crypto.randomUUID();

                        // Sin conexión no se pregunta a la red: se cobra y se guarda directamente.
                        if (this.sinLinea.disponible && !navigator.onLine) {
                            await this.cobrarSinLinea(uuid);
                            this.cobrando = false;
                            return;
                        }

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
                                    // Si lo cobra el motorista no se recibe nada aquí. Se manda cero
                                    // en vez de vacío para no depender de cómo trate el servidor un
                                    // campo ausente.
                                    paid: this.cobraElMotorista ? '0' : this.paid,
                                    client_uuid: uuid,
                                    payment_method: this.method,
                                    order_type: this.orderType,
                                    // Los datos del reparto viajan siempre que el pedido sea envío. El
                                    // servidor decide qué hacer con ellos —y si la forma de pago pasa
                                    // a crédito—: aquí no se decide nada sobre el dinero.
                                    ...(this.esEnvio ? {
                                        delivery_address: this.envio.direccion,
                                        delivery_phone: this.envio.telefono,
                                        delivery_notes: this.envio.notas,
                                        delivery_employee_id: this.envio.repartidor,
                                        delivery_pay_on_arrival: this.envio.pagaAlRecibir,
                                    } : {}),
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
                            this.refActiva = '';
                            // El pedido siguiente empieza limpio: si la dirección del anterior se
                            // quedara puesta, el segundo envío saldría a la casa del primero.
                            this.orderType = 'dine_in';
                            this.envio = { direccion: '', telefono: '', notas: '', repartidor: '', pagaAlRecibir: false };

                            // La venta acaba de mover el stock: un producto que se agotó tiene que
                            // aparecer marcado antes de que el cajero intente venderlo otra vez.
                            this.$nextTick(() => this.refrescar());
                        } catch (e) {
                            /*
                             * Se cayó la red con el cobro en vuelo.
                             *
                             * Antes esto dejaba al cajero comprobando a mano si la venta había
                             * entrado. Ya no hace falta: la venta se encola con la MISMA llave que
                             * llevaba la petición perdida, así que al subirla el servidor sabrá si
                             * ya la tenía. Se cobra, se imprime y se sigue atendiendo.
                             */
                            if (this.sinLinea.disponible) {
                                await this.cobrarSinLinea(uuid);
                            } else {
                                // Sin dónde guardar no se puede prometer nada. Se conserva el
                                // carrito: borrarlo dejaría al cajero sin saber qué estaba vendiendo.
                                this.errorCobro = 'Sin conexión y este navegador no puede guardar la venta. '
                                    + 'Comprueba si se registró antes de repetirla.';
                            }
                        } finally {
                            this.cobrando = false;
                        }
                    },

                    // ── Sin internet ──────────────────────────────────────────────────────────

                    async arrancarSinLinea() {
                        const offline = await window.cargarOffline();
                        if (!offline) return;

                        this.sinLinea.disponible = true;

                        offline.cola.alCambiar((estado) => {
                            this.sinLinea = { ...this.sinLinea, ...estado };
                        });

                        offline.cola.vigilar();
                    },

                    async guardarCatalogo(data) {
                        const offline = await window.cargarOffline();
                        if (!offline) return;

                        await offline.almacen.guardar(
                            offline.almacen.CATALOGO,
                            { results: data.results, categories: data.categories, guardado_en: Date.now() },
                            'actual',
                        ).catch(() => {});
                    },

                    async recuperarCatalogo() {
                        const offline = await window.cargarOffline();
                        if (!offline) return;

                        const copia = await offline.almacen.leer(offline.almacen.CATALOGO, 'actual').catch(() => null);
                        if (!copia) return;

                        this.all = copia.results ?? [];
                        if (copia.categories) this.cats = copia.categories;
                        this.sinLinea.catalogoDe = copia.guardado_en ?? null;
                        this.loaded.add('all');
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
                     * Cobra sin línea: guarda la venta, imprime el recibo y deja el terminal listo.
                     *
                     * El orden importa. Primero se GUARDA y solo si eso sale bien se limpia el
                     * carrito: si se limpiara antes y el guardado fallara, la venta desaparecería de
                     * los dos sitios a la vez y no quedaría ni rastro de lo que se acaba de cobrar.
                     */
                    async cobrarSinLinea(uuid) {
                        const offline = await window.cargarOffline();

                        if (!offline) {
                            this.errorCobro = 'Este navegador no puede guardar la venta. No la des por cobrada.';
                            return;
                        }

                        const detalle = this.cart.map((i) => ({
                            nombre: i.name,
                            cantidad: i.qty,
                            precio: Number(i.price),
                            importe: Number(i.price) * i.qty,
                        }));

                        const venta = {
                            uuid,
                            cash_session_id: this.sesionCaja,
                            payment_method: this.method,
                            paid: this.paid || String(this.subtotal),
                            order_type: this.orderType === 'delivery' ? 'takeaway' : this.orderType,
                            lines: this.cart.map((i) => ({
                                product_id: i.id,
                                quantity: String(i.qty),
                                // El precio que se está cobrando AHORA, con el recargo de las
                                // opciones ya dentro. Es el número que va a llevar el recibo del
                                // cliente, y por eso es el que se guarda.
                                unit_price: String(i.price),
                                options: (i.opciones ?? []).map((o) => o.id),
                            })),
                        };

                        let guardada;

                        try {
                            guardada = await offline.cola.encolar(venta);
                        } catch (e) {
                            this.errorCobro = 'No se pudo guardar la venta en este equipo. No la des por cobrada.';
                            return;
                        }

                        // Se imprime lo guardado, no lo que se iba a guardar: así el papel y la cola
                        // dicen lo mismo, con la misma hora.
                        offline.recibo.imprimir(guardada, this.negocio, detalle);

                        this.ultimaVenta = {
                            message: 'Venta cobrada sin conexión. Se enviará al volver la línea.',
                            code: 'Ref. ' + uuid.slice(0, 8),
                            change: String(Math.max(0, Number(this.paid || 0) - this.subtotal)),
                        };

                        this.cart = [];
                        this.paid = '';
                        this.method = 'cash';
                        this.orderType = 'dine_in';
                    },
                };
            }
        </script>
    @endif
</x-dynamic-component>
