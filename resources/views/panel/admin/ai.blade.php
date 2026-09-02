{{--
    Los ajustes de IA de la plataforma.

    Una sola clave para todas las empresas. Un dueño de colmado no va a sacar una clave en Google AI
    Studio, y exigírselo dejaría el módulo apagado para todo el mundo — con Zernio ya se comprobó.

    La clave NUNCA se devuelve al HTML: el campo va vacío siempre y, si se envía vacío, se conserva
    la que había.
--}}
@php
    use App\Modules\AI\Support\CatalogoDeModelos;

    // Qué modelos ofrece cada proveedor. Sale del catálogo del módulo, que es el mismo que usa el
    // controlador al guardar: si estuviera escrito aquí, formulario y servidor podrían discrepar.
    $catalogo = CatalogoDeModelos::todos();

    $proveedores = [
        'gemini' => ['Gemini (Google)', 'Hace las dos cosas: redacta y también indexa documentos. Es el recomendado.'],
        'openai' => ['OpenAI', 'Redacta e indexa. Se paga por uso desde el primer día.'],
        'anthropic' => ['Claude (Anthropic)', 'Solo redacta: NO puede indexar documentos, así que el asistente no encontrará nada.'],
        'local' => ['Ninguno (apagado)', 'Sin proveedor. El asistente no redacta y el sentimiento usa el clasificador por palabras.'],
    ];
@endphp

<x-layouts.admin title="IA de la plataforma" heading="Inteligencia Artificial"
                 subheading="La clave con la que funciona el asistente de todas las empresas">
    <div class="mx-auto max-w-3xl space-y-5">
        {{-- El estado, antes que nada: es lo que se viene a mirar. --}}
        <div class="bmos-cifras">
            <div class="bmos-cifra" data-tono="{{ $ajustes->configurado() ? 'tone-emerald' : 'es-neutra' }}">
                <p class="bmos-cifra-valor">{{ $ajustes->configurado() ? 'Encendida' : 'Apagada' }}</p>
                <p class="bmos-cifra-etq">
                    {{ $ajustes->configurado() ? ($proveedores[$ajustes->provider][0] ?? $ajustes->provider) : 'sin clave' }}
                </p>
            </div>
            <div class="bmos-cifra" data-tono="tone-indigo">
                <p class="bmos-cifra-valor">{{ $ajustes->embedding_dimensions }}</p>
                <p class="bmos-cifra-etq">dimensiones del vector</p>
            </div>
            <div class="bmos-cifra" data-tono="{{ $desfasados > 0 ? 'tone-amber' : 'tone-emerald' }}">
                <p class="bmos-cifra-valor">{{ number_format($desfasados) }}</p>
                <p class="bmos-cifra-etq">{{ $desfasados > 0 ? 'fragmentos por reindexar' : 'todo al día' }}</p>
            </div>
        </div>

        {{-- Los documentos indexados con otro proveedor NO se usan, y hay que decirlo: si no, el
             asistente contestaría «no encontré nada» sin que nadie pudiera saber por qué. --}}
        @if ($desfasados > 0)
            <div class="rounded-xl border border-amber-200 bg-amber-50 p-4">
                <p class="font-semibold text-amber-900">Hay documentos indexados con otro proveedor</p>
                <p class="mt-1 text-sm text-amber-800">
                    Cada proveedor genera vectores de un espacio distinto, así que no se pueden mezclar:
                    mientras no se reindexen, el asistente <b>no los usa</b>. Se hace por tandas porque
                    reindexarlos todos de golpe agotaría el tiempo de la petición.
                </p>
                <form method="POST" action="{{ route('platform.ai.reindex') }}" class="mt-3">
                    @csrf
                    <button type="submit" class="bmos-btn bmos-btn-primary">Reindexar una tanda</button>
                </form>
            </div>
        @endif

        <div class="bmos-card bmos-card-pad">
            <form method="POST" action="{{ route('platform.ai.update') }}" class="space-y-4"
                  x-data="ajustesDeIa(@js($catalogo), @js($ajustes->provider), @js($ajustes->chat_model),
                                      @js($ajustes->embedding_model), @js((int) $ajustes->embedding_dimensions))">
                @csrf
                @method('PUT')

                <div>
                    <label class="bmos-field-label">Proveedor</label>
                    {{-- Al cambiar de proveedor se rellenan modelos y dimensiones con los suyos. --}}
                    <select name="provider" class="bmos-input" x-model="proveedor" @change="cambiarProveedor()">
                        @foreach ($proveedores as $clave => $datos)
                            <option value="{{ $clave }}" @selected($ajustes->provider === $clave)>{{ $datos[0] }}</option>
                        @endforeach
                    </select>
                    @foreach ($proveedores as $clave => $datos)
                        <p class="mt-1 text-xs {{ $clave === 'anthropic' ? 'text-amber-600' : 'text-slate-400' }}"
                           x-show="proveedor === '{{ $clave }}'" x-cloak>{{ $datos[1] }}</p>
                    @endforeach
                </div>

                <div x-show="proveedor !== 'local'" x-cloak>
                    <label class="bmos-field-label">Clave de API</label>
                    {{-- Vacío = se conserva la que ya había. La clave no se devuelve nunca al HTML. --}}
                    <input type="password" name="api_key" class="bmos-input font-mono" autocomplete="off"
                           placeholder="{{ filled($ajustes->api_key) ? 'Ya hay una guardada — déjalo vacío para conservarla' : 'Pega aquí la clave' }}">
                    <p class="mt-1 text-xs text-slate-400">
                        Se guarda cifrada y no se vuelve a mostrar.
                        <span x-show="proveedor === 'gemini'" x-cloak>Se saca gratis en <b>aistudio.google.com</b>.</span>
                    </p>

                    {{-- Borrar la clave se pide a propósito, con su casilla. Antes bastaba con
                         guardar el formulario con el campo vacío —cosa que pasa en cualquier
                         guardado normal— y la IA se quedaba apagada para todas las empresas sin
                         que nadie lo hubiera pedido. --}}
                    @if (filled($ajustes->api_key))
                        <label class="mt-2 flex items-center gap-2 text-xs text-slate-500">
                            <input type="checkbox" name="borrar_api_key" value="1" class="rounded border-slate-300">
                            Borrar la clave guardada (apaga la IA para todas las empresas)
                        </label>
                    @endif
                </div>

                {{--
                    Los modelos, elegidos de una lista y no escritos a mano.

                    Con campos de texto libre nada impedía guardar un modelo de Gemini con el
                    proveedor OpenAI: se guardaba sin protestar y fallaba después, al llamar, con un
                    error del proveedor que no dice que la culpa sea de la combinación.

                    Al cambiar de proveedor, los tres campos se rellenan con lo suyo. Las
                    dimensiones también: son parte del modelo de embeddings, no un ajuste aparte, y
                    dejarlas con el número del proveedor anterior es la forma más silenciosa de
                    romper el buscador.

                    «Otro» sigue existiendo porque los proveedores sacan modelos cada pocas semanas y
                    una lista cerrada obligaría a tocar código para estrenar uno.
                --}}
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-3" x-show="proveedor !== 'local'" x-cloak>
                    <div>
                        <label class="bmos-field-label">Modelo de chat</label>
                        {{-- El `$nextTick` no es adorno: Alpine fija el valor del desplegable ANTES
                             de que `x-for` haya creado las opciones, así que el navegador no
                             encuentra la guardada y cae en la única que ya existe —«Otro»—. El
                             estado y lo que se envía eran correctos, pero en pantalla se leía
                             «Otro» con el campo de escribir vacío. Se resincroniza tras pintar. --}}
                        <select class="bmos-input" x-model="chatSel"
                                x-init="$nextTick(() => $el.value = chatSel)">
                            <template x-for="m in modelosChat()" :key="m">
                                <option :value="m" x-text="m"></option>
                            </template>
                            <option value="__otro">Otro (escribirlo a mano)</option>
                        </select>
                        <input type="text" class="bmos-input mt-2 font-mono" x-model="chatLibre"
                               x-show="chatSel === '__otro'" x-cloak placeholder="nombre exacto del modelo">
                        {{-- Lo que viaja al servidor es esto, no los controles: así el contrato del
                             formulario no cambia por haber puesto un desplegable delante. --}}
                        <input type="hidden" name="chat_model" :value="chatEfectivo()">
                    </div>

                    <div x-show="proveedor !== 'anthropic'">
                        <label class="bmos-field-label">Modelo de embeddings</label>
                        <select class="bmos-input" x-model="embSel"
                                x-init="$nextTick(() => $el.value = embSel)">
                            <template x-for="m in modelosEmbedding()" :key="m">
                                <option :value="m" x-text="m"></option>
                            </template>
                            <option value="__otro">Otro (escribirlo a mano)</option>
                        </select>
                        <input type="text" class="bmos-input mt-2 font-mono" x-model="embLibre"
                               x-show="embSel === '__otro'" x-cloak placeholder="nombre exacto del modelo">
                        <input type="hidden" name="embedding_model" :value="embEfectivo()">
                    </div>

                    <div x-show="proveedor !== 'anthropic'">
                        <label class="bmos-field-label">Dimensiones</label>
                        <input type="number" name="embedding_dimensions" class="bmos-input"
                               x-model="dimensiones" min="64" max="3072">
                    </div>
                </div>

                {{-- El tamaño se fija a propósito y no se hereda del modelo: si el proveedor cambiara
                     mañana lo que devuelve por omisión, todo lo indexado dejaría de casar en silencio. --}}
                <p class="text-xs text-slate-400" x-show="proveedor !== 'local' && proveedor !== 'anthropic'" x-cloak>
                    Cambiar las dimensiones o el proveedor obliga a reindexar todos los documentos.
                </p>

                {{-- El tope del asistente de ayuda.

                     Va aquí, con los ajustes de IA, porque es lo mismo: cuánto estás dispuesto a
                     gastar. Y es un solo número para toda la plataforma a propósito: un tope por
                     empresa serían quince sitios donde mirar cuando alguien diga «se me acabó». --}}
                <div class="border-t border-slate-100 pt-4">
                    <label class="bmos-field-label">Preguntas al día por empresa</label>
                    <input type="number" name="daily_limit" class="bmos-input" style="max-width:10rem"
                           value="{{ $ajustes->daily_limit }}" min="0" max="1000">
                    <p class="mt-1 text-xs text-slate-400">
                        Cuántas veces al día puede preguntarle cada empresa al asistente de ayuda.
                        Cada pregunta la pagas tú en el proveedor. <b>Cero lo apaga para todas.</b>
                    </p>
                </div>

                <div class="border-t border-slate-100 pt-4">
                    <button type="submit" class="bmos-btn bmos-btn-primary">Guardar</button>
                </div>
            </form>

            {{-- Probar va en su PROPIO formulario: uno dentro de otro es HTML inválido y el navegador
                 desmonta el interior, rompiendo el envío del exterior. Ya pasó en «Mi empresa». --}}
            @if ($ajustes->configurado())
                <form method="POST" action="{{ route('platform.ai.test') }}" class="mt-3 border-t border-slate-100 pt-4">
                    @csrf
                    <button type="submit" class="bmos-btn bmos-btn-ghost">Probar la conexión</button>
                    <span class="ml-2 text-xs text-slate-400">
                        Hace una llamada de verdad y dice el motivo exacto si falla.
                    </span>
                </form>
            @endif
        </div>
    </div>

    {{-- En línea y no en `@push`: el layout del panel no tiene `@stack('scripts')`, así que un push
         se traga el guion en silencio. Es como lo hacen WhatsApp y el punto de venta. --}}
    <script>
        function ajustesDeIa(catalogo, proveedor, chatGuardado, embGuardado, dimsGuardadas) {
            /*
             * Un modelo guardado que no está en la lista NO se pierde ni se sustituye: se muestra
             * como «Otro» con su nombre escrito. Puede ser un modelo nuevo que alguien puso a
             * propósito, y machacarlo al abrir la pantalla sería cambiar la configuración de la
             * plataforma sin que nadie lo pidiera.
             */
            const enLista = (lista, valor) => valor && lista.includes(valor);

            return {
                catalogo,
                proveedor,
                chatSel: enLista(catalogo[proveedor]?.chat ?? [], chatGuardado) ? chatGuardado : '__otro',
                chatLibre: enLista(catalogo[proveedor]?.chat ?? [], chatGuardado) ? '' : (chatGuardado ?? ''),
                embSel: enLista(catalogo[proveedor]?.embedding ?? [], embGuardado) ? embGuardado : '__otro',
                embLibre: enLista(catalogo[proveedor]?.embedding ?? [], embGuardado) ? '' : (embGuardado ?? ''),
                dimensiones: dimsGuardadas,

                modelosChat() { return this.catalogo[this.proveedor]?.chat ?? []; },
                modelosEmbedding() { return this.catalogo[this.proveedor]?.embedding ?? []; },

                // Lo que de verdad se envía: el desplegable, o lo escrito a mano si se eligió «Otro».
                chatEfectivo() { return this.chatSel === '__otro' ? this.chatLibre : this.chatSel; },
                embEfectivo() { return this.embSel === '__otro' ? this.embLibre : this.embSel; },

                /*
                 * Al cambiar de proveedor se pone lo recomendado de ese proveedor.
                 *
                 * Se pisa lo que hubiera a propósito: los modelos de un proveedor no existen en
                 * otro, así que conservarlos dejaría la pantalla en un estado que no funciona —que
                 * es justo como estaba: proveedor OpenAI con modelos de Gemini—.
                 */
                cambiarProveedor() {
                    const c = this.catalogo[this.proveedor] ?? { chat: [], embedding: [], dimensiones: null };

                    this.chatSel = c.chat[0] ?? '__otro';
                    this.chatLibre = '';
                    this.embSel = c.embedding[0] ?? '__otro';
                    this.embLibre = '';

                    // Las dimensiones solo se tocan si el proveedor tiene las suyas: con Anthropic
                    // no hay embeddings, y borrarlas perdería el número al que están indexados los
                    // documentos que ya existen.
                    if (c.dimensiones) this.dimensiones = c.dimensiones;
                },
            };
        }
    </script>
</x-layouts.admin>
