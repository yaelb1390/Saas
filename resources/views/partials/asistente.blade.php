{{--
    El asistente de ayuda, flotando sobre cualquier pantalla del panel.

    Por qué una burbuja y no una pantalla más: la duda aparece MIENTRAS se está haciendo algo —parado
    en facturación sin saber qué botón tocar—, y mandar al usuario a otra pantalla le obliga a
    abandonar lo que tenía a medias para poder preguntar cómo terminarlo.

    La pantalla `/panel/ayuda` sigue existiendo y el «?» del topbar sigue llevando a ella: para leer
    un artículo entero, una ventana de 23rem es peor que una página.

    Sólo se incluye si la empresa tiene el asistente encendido, pero eso NO es la seguridad: el
    endpoint lo vuelve a comprobar, porque esconder un botón no cierra una puerta.
--}}
<div x-data="asistenteDeAyuda('{{ route('panel.assistant.ask') }}', '{{ route('panel.assistant.reset') }}')"
     x-cloak>

    {{-- El botón --}}
    <button type="button" class="asis-boton" x-show="!abierto" @click="abrir()"
            :style="`right:${derecha}px`"
            title="Pregúntame cómo se usa el sistema">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-5 w-5">
            <path stroke-linecap="round" stroke-linejoin="round"
                  d="M8 10.5h8M8 14h5m-9 7 3.5-3.5H18a3 3 0 0 0 3-3V7a3 3 0 0 0-3-3H6a3 3 0 0 0-3 3v14Z"/>
        </svg>
        <span class="asis-boton-texto">¿Cómo se hace?</span>
    </button>

    {{-- La ventana --}}
    <div class="asis-panel" x-show="abierto" x-transition.opacity :style="`right:${derecha}px`">
        <div class="asis-cabecera">
            <span class="grid h-8 w-8 shrink-0 place-items-center rounded-full bg-indigo-600 text-white">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-4 w-4">
                    <path stroke-linecap="round" stroke-linejoin="round"
                          d="M8 10.5h8M8 14h5m-9 7 3.5-3.5H18a3 3 0 0 0 3-3V7a3 3 0 0 0-3-3H6a3 3 0 0 0-3 3v14Z"/>
                </svg>
            </span>
            <div class="min-w-0 flex-1">
                <p class="asis-titulo">Asistente</p>
                <p class="asis-sub">Te explico cómo se usa el sistema</p>
            </div>
            {{-- Empezar de cero. Hace falta: el hilo va contigo de pantalla en pantalla, y una
                 conversación sobre ventas seguiría dando contexto a una pregunta de inventario. --}}
            <button type="button" class="rounded-lg p-1.5 text-slate-400 transition hover:text-slate-600"
                    x-show="mensajes.length > 0" @click="limpiar()" title="Empezar de cero">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-4 w-4">
                    <path stroke-linecap="round" stroke-linejoin="round"
                          d="M16.023 9.348h4.992V4.356m-.001 9.667a8.25 8.25 0 0 1-15.357 1.99M3.985 14.652h4.99v4.992m0-9.667a8.25 8.25 0 0 1 15.357-1.99"/>
                </svg>
            </button>
            <button type="button" class="rounded-lg p-1.5 text-slate-400 transition hover:text-slate-600"
                    @click="abierto = false" title="Cerrar">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-4 w-4">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>

        <div class="asis-hilo" x-ref="hilo">
            {{-- Delante de una caja vacía la gente no sabe qué escribir, y si la primera pregunta no
                 encuentra nada, no hay segunda. --}}
            <template x-if="mensajes.length === 0">
                <div>
                    <p class="mb-2 text-xs text-slate-400">Prueba con una de estas:</p>
                    <template x-for="s in sugerencias" :key="s">
                        <button type="button" class="asis-sugerencia" x-text="s" @click="preguntar(s)"></button>
                    </template>
                </div>
            </template>

            <template x-for="(m, i) in mensajes" :key="i">
                <div class="asis-fila" :class="m.mio ? 'asis-fila--mia' : ''">
                    <div>
                        <div class="asis-burbuja" x-text="m.texto"></div>
                        {{-- El artículo del que salió, para que se pueda comprobar. Un asistente que
                             no enseña de dónde lo sacó pide un acto de fe. --}}
                        <template x-if="m.articulo">
                            <a class="asis-fuente" :href="m.articulo.url">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" class="h-3 w-3">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 6.042A8.967 8.967 0 0 0 6 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 0 1 6 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 0 1 6-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0 0 18 18a8.967 8.967 0 0 0-6 2.292m0-14.25v14.25"/>
                                </svg>
                                <span x-text="'Leer: ' + m.articulo.titulo"></span>
                            </a>
                        </template>
                    </div>
                </div>
            </template>

            <div class="asis-fila" x-show="pensando">
                <div class="asis-burbuja asis-pensando"><span></span><span></span><span></span></div>
            </div>
        </div>

        {{-- Sólo cuando quedan pocas: avisar de que quedan 47 de 50 es ruido. --}}
        <p class="asis-cuota" x-show="restantes !== null && restantes <= 5 && restantes > 0" x-cloak>
            Te quedan <span x-text="restantes"></span> preguntas hoy.
        </p>

        <form class="asis-pie" @submit.prevent="preguntar(texto)">
            <input type="text" class="asis-input" x-model="texto" maxlength="300"
                   placeholder="¿Cómo anulo una venta?" :disabled="pensando" x-ref="caja">
            <button type="submit" class="asis-enviar" :disabled="pensando || !texto.trim()" title="Preguntar">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" class="h-4 w-4">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 12 3.269 3.125A59.769 59.769 0 0 1 21.485 12 59.768 59.768 0 0 1 3.27 20.875L5.999 12Zm0 0h7.5"/>
                </svg>
            </button>
        </form>
    </div>
</div>

{{--
    En línea y no en `@push`: el layout del panel NO tiene `@stack('scripts')`, así que un push se
    traga el guion en silencio. Es como lo hacen la bandeja de WhatsApp y el punto de venta.
--}}
<script>
    function asistenteDeAyuda(urlPreguntar, urlLimpiar) {
        return {
            abierto: false,
            texto: '',
            pensando: false,
            restantes: null,
            mensajes: [],
            // Distancia al borde derecho, en píxeles. La calcula `recolocar()`.
            derecha: 20,

            init() {
                this.recolocar();
                // Al girar el móvil o cambiar el tamaño, la columna que se esquiva cambia de sitio.
                window.addEventListener('resize', () => this.recolocar());
            },
            // Tres, no seis: la ventana es estrecha y una lista larga tapa el hilo.
            sugerencias: @js(array_slice(\App\Modules\Help\Services\HelpLibrary::SUGERENCIAS, 0, 3)),

            /*
             * A qué distancia del borde derecho se coloca.
             *
             * Por omisión pegado a la esquina, que es donde se busca. Pero en las pantallas de cobro
             * —Punto de Venta, Venta rápida, Mostrador— la columna de la derecha lleva el total y el
             * botón de cobrar, y ahí la burbuja tapaba justo eso: comprobado en el navegador, cubría
             * el ticket entero a 1280 y a 1920.
             *
             * La columna se declara a sí misma con `data-asis-evitar` y aquí se MIDE. No se usa un
             * desplazamiento fijo porque cada pantalla la dimensiona distinto: 21/23rem en Venta
             * rápida y un tercio de la rejilla en las otras dos. Un número inventado acertaría en una.
             */
            recolocar() {
                const margen = 20;

                /*
                 * Por debajo de 1024px no se esquiva NADA, y es deliberado.
                 *
                 * Ahí las pantallas de cobro apilan la columna del ticket debajo del catálogo (son
                 * rejillas `lg:`), así que no hay un lado al que apartarse: el panel ocupa casi todo
                 * el ancho y se superpone a la página mientras está abierto, como cualquier chat en
                 * un móvil. Intentar esquivar movería la ventana fuera de la pantalla.
                 */
                if (window.innerWidth < 1024) { this.derecha = margen; return; }

                const zona = document.querySelector('[data-asis-evitar]');

                if (!zona) { this.derecha = margen; return; }

                const r = zona.getBoundingClientRect();

                if (r.width === 0) { this.derecha = margen; return; }

                const propuesta = window.innerWidth - r.left + 12;

                /* Si apartarse la sacaría de la pantalla, se queda donde estaba: media burbuja fuera
                   es peor que una burbuja encima de algo. */
                this.derecha = propuesta + this.anchoPanel() > window.innerWidth - 16 ? margen : propuesta;
            },

            anchoPanel() {
                return this.$el.querySelector('.asis-panel')?.offsetWidth || 368;
            },

            abrir() {
                this.recolocar();
                this.abierto = true;
                // El foco en la caja: si hay que abrir y encima pulsar dentro, la mitad no pregunta.
                this.$nextTick(() => this.$refs.caja?.focus());
            },

            async preguntar(pregunta) {
                pregunta = (pregunta || '').trim();

                if (!pregunta || this.pensando) return;

                this.mensajes.push({ mio: true, texto: pregunta, articulo: null });
                this.texto = '';
                this.pensando = true;
                this.alFondo();

                try {
                    const res = await fetch(urlPreguntar, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            Accept: 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        },
                        body: JSON.stringify({ pregunta }),
                    });

                    const datos = await res.json().catch(() => ({}));

                    if (!res.ok) {
                        // 422 trae el motivo de validación; lo demás es un fallo que no sabemos
                        // explicar y no vale la pena inventarle un texto bonito.
                        this.responder(datos.message || 'No pude responder ahora mismo. Inténtalo de nuevo en un momento.');

                        return;
                    }

                    this.restantes = datos.restantes ?? null;

                    /*
                     * Sin respuesta redactada pero CON artículo: el proveedor no redacta (no hay
                     * clave) o falló. El artículo responde igual, así que se ofrece en vez de decir
                     * que no se sabe.
                     */
                    if (!datos.respuesta && datos.articulo) {
                        this.responder('Eso te lo explico aquí mismo: ábrelo y lo tienes paso a paso.', datos.articulo);

                        return;
                    }

                    this.responder(
                        datos.respuesta || 'Eso no lo tengo cubierto todavía. Dímelo con otras palabras —qué quieres hacer y en qué pantalla— y lo busco otra vez; si no, escríbenos y te ayudamos.',
                        datos.articulo,
                    );
                } catch {
                    // Un fallo de red no puede dejar la ventana colgada pensando para siempre.
                    this.responder('Parece que te quedaste sin conexión. Inténtalo otra vez.');
                }
            },

            responder(texto, articulo = null) {
                this.pensando = false;
                this.mensajes.push({ mio: false, texto, articulo });
                this.alFondo();
            },

            async limpiar() {
                this.mensajes = [];
                this.texto = '';

                try {
                    await fetch(urlLimpiar, {
                        method: 'DELETE',
                        headers: {
                            Accept: 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        },
                    });
                } catch {
                    // Si no se pudo borrar en el servidor, se ha borrado en pantalla igualmente: el
                    // hilo caduca solo con la sesión y no vale la pena molestar con un error.
                }
            },

            alFondo() {
                this.$nextTick(() => {
                    const h = this.$refs.hilo;

                    if (h) h.scrollTop = h.scrollHeight;
                });
            },
        };
    }
</script>
