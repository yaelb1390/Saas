@php
    // El QR viene de un redirect, así que solo puede llegar por sesión. A partir de ahí lo gobierna
    // Alpine: cuando el sondeo vea la línea en «open», lo esconde solo. Antes había que recargar.
    $qr = session('wa_qr');
@endphp
<x-layouts.admin title="WhatsApp" heading="WhatsApp" subheading="Atiende a tus clientes y deja que el asistente conteste por ti">

    <div class="wa-consola" x-ref="consola" :data-alto="pestana === 'bandeja' ? 'fijo' : 'libre'"
         x-data="waConsola(@js($inbox), @js($qr), {
             sondeo: '{{ route('panel.whatsapp.poll') }}',
             bandeja: '{{ route('panel.whatsapp') }}',
             oficial: '{{ $vias['oficial'] }}',
         })">

        {{-- ============ Tira de conexión ============
             Compacta cuando todo va bien: ocupar media pantalla para decir «no pasa nada» es lo que
             hacía antes. Se abre sola cuando hay algo que hacer. --}}
        <div class="bmos-card wa-linea" :data-tono="linea.tono" :data-abierta="abierta">
            <div class="wa-linea-fila">
                <span class="wa-orbe" :data-tono="linea.tono" :data-pulso="linea.pulso">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M20.25 8.511c.884.284 1.5 1.128 1.5 2.097v4.286c0 1.136-.847 2.1-1.98 2.193-.34.027-.68.052-1.02.072v3.091l-3-3q-1.72 0-3.42-.12M3.75 15.75V8.25a2.25 2.25 0 0 1 2.25-2.25h9a2.25 2.25 0 0 1 2.25 2.25v7.5A2.25 2.25 0 0 1 15 18h-3.75l-3 3v-3H6a2.25 2.25 0 0 1-2.25-2.25Z"/>
                    </svg>
                </span>

                <div class="wa-linea-texto">
                    <div class="wa-linea-titulo">
                        Línea de WhatsApp
                        {{-- El texto va también en el servidor: sin él, la etiqueta parpadea vacía
                             en cada carga y una página sin JavaScript no diría nada. --}}
                        <span class="bmos-badge" :class="linea.badge" x-text="linea.label">{{ $inbox['line']['label'] }}</span>
                    </div>
                    <p class="wa-linea-pista" x-show="abierta" x-text="linea.pista">{{ $inbox['line']['pista'] }}</p>
                    <p class="wa-instancia">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m6.75 7.5 3 2.25-3 2.25m4.5 0h3m-9 8.25h13.5A2.25 2.25 0 0 0 21 18V6a2.25 2.25 0 0 0-2.25-2.25H5.25A2.25 2.25 0 0 0 3 6v12a2.25 2.25 0 0 0 2.25 2.25Z"/></svg>
                        <span x-text="linea.instance"></span>
                    </p>
                </div>

                <div class="wa-linea-acciones">
                    @can('whatsapp.connect')
                        {{-- El botón solo si de verdad puede hacer algo. Ofrecerlo sin la clave de
                             Zernio llevaba a un error que además culpaba al servidor. --}}
                        <form method="POST" action="{{ route('panel.whatsapp.connect') }}" x-show="!linea.connected && !bot.falta">
                            @csrf
                            <button type="submit" class="bmos-btn bmos-btn-primary" x-text="textoConectar"></button>
                        </form>

                        <a href="{{ route('panel.social') }}" class="bmos-btn bmos-btn-ghost"
                           x-show="bot.falta === 'clave'" x-cloak>Conectar Zernio &rarr;</a>

                        {{-- Desvincular NO borra la instancia: el histórico se queda donde está. Por eso
                             el aviso habla de volver a escanear y no de perder nada. --}}
                        <span x-show="linea.connected">
                            <x-panel.confirm-action
                                :action="route('panel.whatsapp.disconnect')"
                                method="POST"
                                title="¿Desvincular el teléfono?"
                                message="La línea deja de enviar y de recibir hasta que la vuelvas a conectar. Las conversaciones no se borran."
                                confirm="Desvincular"
                                tone="danger"
                                class="bmos-btn bmos-btn-ghost">
                                Desvincular
                            </x-panel.confirm-action>
                        </span>
                    @endcan
                </div>
            </div>

            {{-- Emparejamiento. Desaparece solo al conectar. --}}
            <div class="wa-emparejar" x-show="qr && !linea.connected" x-cloak>
                <img :src="qr" alt="Código QR para vincular WhatsApp" class="wa-qr">
                <div>
                    <p class="font-semibold text-slate-800">Vincula el teléfono del negocio</p>
                    <p class="mt-1 mb-4 text-sm text-slate-500">El código caduca en menos de un minuto. Si expira, pulsa «Reintentar vínculo».</p>
                    <div class="wa-pasos">
                        <p class="wa-paso">Abre WhatsApp en el teléfono del negocio.</p>
                        <p class="wa-paso">Entra en <strong>Ajustes → Dispositivos vinculados</strong>.</p>
                        <p class="wa-paso">Pulsa <strong>Vincular un dispositivo</strong> y escanea este código.</p>
                        {{-- Ya no dice «recarga la página»: el estado viaja en el sondeo. --}}
                        <p class="wa-paso">Listo. Esta pantalla pasará a <strong>En línea</strong> sola.</p>
                    </div>

                    {{-- Lo que nadie cuenta del emparejamiento por QR y hay que decir antes, no
                         después de que pase. --}}
                    <p class="wa-nota">
                        Esta vía usa la sesión de WhatsApp Web, que no es la conexión oficial de Meta.
                        Funciona bien, pero <strong>Meta puede bloquear el número</strong> si detecta
                        demasiada automatización. Usa el número del negocio, nunca el personal.
                    </p>
                </div>
            </div>

            {{-- Lo que falta antes de poder conectar nada. Va primero: sin esto no hay pasos que dar. --}}
            <div class="wa-emparejar" x-show="!linea.connected && bot.falta" x-cloak>
                <div>
                    <p class="font-semibold text-slate-800" x-show="bot.falta === 'clave'">Falta conectar tu cuenta de Zernio</p>
                    <p class="font-semibold text-slate-800" x-show="bot.falta === 'servidor'">El código QR no está disponible aquí</p>

                    <p class="mt-1 text-sm text-slate-500" x-show="bot.falta === 'clave'">
                        La vía oficial de Meta pasa por Zernio, y su clave se guarda en Redes sociales.
                        Ponla ahí y vuelve: entonces sí podrás conectar tu número.
                    </p>
                    <p class="mt-1 text-sm text-slate-500" x-show="bot.falta === 'servidor'">
                        Esta instalación no tiene el servicio de emparejamiento por QR. Cambia a la vía
                        oficial de Meta en la pestaña Asistente: no necesita nada instalado y además no
                        te pueden bloquear el número.
                    </p>
                </div>
            </div>

            {{-- Alta por la vía oficial. Aquí no hay nada que escanear: se va a Meta y se vuelve. --}}
            <div class="wa-emparejar wa-emparejar--meta" x-show="esOficial && !linea.connected && !bot.falta" x-cloak>
                <div>
                    <p class="font-semibold text-slate-800">Conecta tu WhatsApp Business con Meta</p>
                    <p class="mt-1 mb-4 text-sm text-slate-500">
                        Es la conexión oficial: Meta no bloquea el número y no hace falta que ningún
                        equipo tuyo quede encendido. Al pulsar «Conectar con Meta» se abre su propia
                        pantalla y vuelves aquí conectado.
                    </p>
                    <div class="wa-pasos">
                        <p class="wa-paso">Ten a mano tu cuenta de <strong>Meta Business</strong> (la misma de tu Facebook del negocio).</p>
                        <p class="wa-paso">Pulsa <strong>Conectar con Meta</strong> y sigue sus pasos.</p>
                        <p class="wa-paso">Elige o crea tu cuenta de WhatsApp Business y registra el número.</p>
                        <p class="wa-paso">Vuelves solo. Esta pantalla pasará a <strong>En línea</strong>.</p>
                    </div>

                    {{-- Las dos cosas que más sorprenden, dichas antes y no cuando ya pasó. --}}
                    <p class="wa-nota">
                        <strong>Hace falta un número que no esté usándose en la app de WhatsApp.</strong>
                        Si usas uno que ya tiene WhatsApp, hay que darlo de baja primero y se pierde su
                        historial en el teléfono. Lo más cómodo es un número nuevo dedicado al negocio.
                    </p>
                    <p class="wa-nota">
                        Si ese número tiene <strong>verificación en dos pasos</strong> con PIN, quítasela
                        antes de conectarlo. Meta rechaza el registro y luego los envíos fallan diciendo
                        que faltan permisos, aunque aquí la línea se vea conectada.
                    </p>
                </div>
            </div>
        </div>

        {{-- ============ Pestañas ============ --}}
        <div class="wa-pestanas" role="tablist">
            <button type="button" role="tab" class="wa-pestana" :aria-selected="pestana === 'bandeja'" @click="pestana = 'bandeja'">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path stroke-linecap="round" stroke-linejoin="round" d="M8.625 12a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0m3.75 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0m3.75 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0M21 12c0 4.556-4.03 8.25-9 8.25a9.8 9.8 0 0 1-2.555-.337A5.97 5.97 0 0 1 5.41 20.97a6 6 0 0 1-.474-.065 4.48 4.48 0 0 0 .978-2.025c.09-.457-.133-.901-.467-1.226C3.93 16.178 3 14.189 3 12c0-4.556 4.03-8.25 9-8.25s9 3.694 9 8.25"/></svg>
                Bandeja
                <span class="wa-pildora" x-show="esperando > 0" x-text="esperando" x-cloak></span>
            </button>
            <button type="button" role="tab" class="wa-pestana" :aria-selected="pestana === 'asistente'" @click="pestana = 'asistente'">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.091 3.091M18.259 8.715 18 9.75l-.259-1.035a3.375 3.375 0 0 0-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 0 0 2.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 0 0 2.456 2.456L21.75 6l-1.035.259a3.375 3.375 0 0 0-2.456 2.456"/></svg>
                Asistente
                <span class="wa-punto" :data-encendido="bot.active"></span>
            </button>
        </div>

        {{-- ============ Bandeja ============ --}}
        <div class="bmos-card wa-shell" x-show="pestana === 'bandeja'" :data-vista="vista">
            <aside class="wa-aside">
                <div class="wa-aside-cabeza">
                    <span class="wa-aside-titulo">Conversaciones</span>
                    <span class="wa-cuenta" x-text="conversations.length"></span>
                </div>

                <div class="wa-lista">
                    <template x-for="c in conversations" :key="c.phone">
                        {{-- Botón y no enlace: cambiar de conversación ya no recarga la página entera.
                             El sondeo ya traía todo lo necesario. --}}
                        <button type="button" class="wa-fila" :aria-current="c.phone === activePhone ? 'true' : 'false'" @click="abrir(c.phone)">
                            <span class="wa-avatar">
                                <template x-if="c.initials"><span x-text="c.initials"></span></template>
                                <template x-if="!c.initials">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0M4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.93 17.93 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632"/></svg>
                                </template>
                            </span>
                            <span class="wa-fila-cuerpo">
                                <span class="wa-fila-alto">
                                    <span class="wa-fila-nombre" x-text="c.title"></span>
                                    <span class="wa-fila-hora" x-text="c.time"></span>
                                </span>
                                <span class="wa-fila-avance">
                                    <span class="text-slate-400" x-show="c.out">Tú:</span>
                                    <span x-text="c.preview"></span>
                                </span>
                                <span class="wa-fila-marcas">
                                    {{-- Quién espera a una persona se ve desde la lista: son los que
                                         hay que atender primero. --}}
                                    <span class="bmos-badge badge-amber" x-show="c.paused">Te espera</span>
                                    <span class="bmos-badge badge-violet" x-show="c.is_customer">Cliente CRM</span>
                                </span>
                            </span>
                        </button>
                    </template>

                    <div class="wa-vacio" x-show="conversations.length === 0">
                        <span class="wa-vacio-icono">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path stroke-linecap="round" stroke-linejoin="round" d="M8.625 12a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0H8.25m4.125 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0H12m4.125 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0h-.375M21 12c0 4.556-4.03 8.25-9 8.25a9.8 9.8 0 0 1-2.555-.337A5.97 5.97 0 0 1 5.41 20.97a6 6 0 0 1-.474-.065 4.48 4.48 0 0 0 .978-2.025c.09-.457-.133-.901-.467-1.226C3.93 16.178 3 14.189 3 12c0-4.556 4.03-8.25 9-8.25s9 3.694 9 8.25Z"/></svg>
                        </span>
                        Aún no hay conversaciones.
                    </div>
                </div>

                {{-- Por la vía oficial no se puede escribir el primero: la ventana la abre el
                     cliente y fuera de ella Meta solo admite plantillas aprobadas. Se desactiva con
                     su motivo en vez de dejar que el envío falle con un error técnico. --}}
                <button type="button" class="wa-nuevo" @click="nuevo()" x-show="bot.puede_escribir_primero">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
                    Escribir a un número nuevo
                </button>
                <p class="wa-nuevo wa-nuevo--no" x-show="!bot.puede_escribir_primero" x-cloak>
                    Por la vía oficial solo puedes responder a quien te escriba primero.
                </p>
            </aside>

            <section class="wa-hilo">
                <div class="wa-hilo-cabeza">
                    {{-- Volver a la lista. Solo en móvil: en pantalla ancha se ven las dos cosas. --}}
                    <button type="button" class="wa-volver" @click="vista = 'lista'" aria-label="Volver a la lista">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5"/></svg>
                    </button>

                    <template x-if="activePhone">
                        <div class="wa-hilo-quien">
                            <span class="wa-avatar">
                                <template x-if="conversacion?.initials"><span x-text="conversacion.initials"></span></template>
                                <template x-if="!conversacion?.initials">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0M4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.93 17.93 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632"/></svg>
                                </template>
                            </span>
                            <div class="min-w-0">
                                <p class="truncate font-semibold text-slate-800" x-text="conversacion?.title ?? activePhone"></p>
                                <p class="wa-instancia" style="margin-top:0" x-text="activePhone"></p>
                            </div>
                            <a class="bmos-badge badge-violet transition hover:brightness-95"
                               x-show="conversacion?.customer_url" :href="conversacion?.customer_url">Ver su ficha &rarr;</a>
                        </div>
                    </template>

                    <p class="font-semibold text-slate-800" x-show="!activePhone">Nuevo mensaje</p>
                </div>

                {{-- El bot se apartó de esta conversación. Se dice aquí y se ofrece devolvérsela:
                     no vuelve solo, porque volvería en medio de lo que una persona está hablando. --}}
                <div class="wa-traspaso" x-show="activePaused" x-cloak>
                    <span>
                        <strong>Lo estás atendiendo tú.</strong>
                        El asistente se apartó de esta conversación y no va a contestar hasta que se lo devuelvas.
                    </span>
                    @can('whatsapp.send')
                        <form method="POST" action="{{ route('panel.whatsapp.resume') }}">
                            @csrf
                            <input type="hidden" name="phone" :value="activePhone">
                            <button type="submit" class="bmos-btn bmos-btn-ghost">Devolver al asistente</button>
                        </form>
                    @endcan
                </div>

                <div class="wa-lienzo" x-ref="lienzo">
                    <template x-for="(m, i) in thread" :key="m.id">
                        <div>
                            {{-- El día, cuando cambia respecto al mensaje de arriba. Sin esto, ayer
                                 y hoy son la misma pared de burbujas y no hay forma de situar una
                                 conversación larga. --}}
                            <template x-if="m.separador">
                                <div class="wa-dia"><span x-text="m.separador"></span></div>
                            </template>

                            {{-- `data-seguido`: continúa lo que venía diciendo el mismo. Se junta al
                                 anterior y pierde la cola, como en cualquier chat. Tres «Hola»
                                 seguidos con tres colas no se parecen a una conversación. --}}
                            <div class="wa-linea-msg" :class="m.out && 'wa-linea-msg--sale'"
                                 :data-seguido="m.seguido ? 'true' : 'false'"
                                 :style="`--i:${Math.min(i, 12)}`">
                            <div class="wa-burbuja" :class="m.out ? (m.bot ? 'wa-burbuja--bot' : 'wa-burbuja--sale') : 'wa-burbuja--entra'">
                                {{-- Quién lo escribió. Sin esto no se puede saber qué prometió el bot
                                     y qué dijo un empleado, que es lo que hay que mirar si algo sale mal. --}}
                                <span class="wa-firma" x-show="m.bot">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.091 3.091"/></svg>
                                    Asistente
                                </span>
                                <p class="wa-texto" x-text="m.body"></p>
                                {{-- `data-leido`: el doble check se pone azul, como en WhatsApp. --}}
                                <span class="wa-meta" :data-leido="m.status === 'read' ? 'true' : 'false'">
                                    <span x-text="m.time"></span>
                                    <template x-if="m.out && m.status === 'failed'">
                                        <span class="wa-meta-fallo">no enviado</span>
                                    </template>
                                    <template x-if="m.out && m.status === 'pending'">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" style="width:.75rem;height:.75rem" aria-label="En cola">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/>
                                        </svg>
                                    </template>
                                    <template x-if="m.out && !['failed', 'pending'].includes(m.status)">
                                        <svg viewBox="0 0 24 20" fill="none" stroke="currentColor" stroke-width="2.4" style="width:.95rem;height:.8rem">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="m1 11 4.5 4.5L14 5"/>
                                            <path x-show="['delivered', 'read'].includes(m.status)" stroke-linecap="round" stroke-linejoin="round" d="m9 11 4.5 4.5L22 5"/>
                                        </svg>
                                    </template>
                                </span>
                            </div>
                            </div>
                        </div>
                    </template>

                    <div class="wa-vacio" x-show="thread.length === 0">
                        <span class="wa-vacio-icono">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path stroke-linecap="round" stroke-linejoin="round" d="M6 12 3.269 3.125A59.77 59.77 0 0 1 21.485 12 59.77 59.77 0 0 1 3.27 20.875L5.999 12Zm0 0h7.5"/></svg>
                        </span>
                        Elige una conversación o escribe a un número nuevo.
                    </div>
                </div>

                <form method="POST" action="{{ route('panel.whatsapp.send') }}" class="wa-redactor">
                    @csrf

                    {{-- Respuestas rápidas. La tabla y su permiso llevaban aquí desde el principio
                         sin que nadie las usara. --}}
                    @if ($plantillas->isNotEmpty())
                        <div class="wa-rapidas" @click.outside="rapidas = false">
                            <button type="button" class="wa-rapidas-boton" @click="rapidas = !rapidas" :aria-expanded="rapidas" aria-label="Respuestas rápidas">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 13.5l10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5z"/></svg>
                            </button>
                            <div class="wa-rapidas-menu" x-show="rapidas" x-cloak x-transition.opacity>
                                <p class="wa-rapidas-titulo">Respuestas rápidas</p>
                                @foreach ($plantillas as $plantilla)
                                    <button type="button" class="wa-rapidas-item" @click="usar(@js($plantilla->body))">
                                        <span class="wa-rapidas-nombre">{{ $plantilla->name }}</span>
                                        <span class="wa-rapidas-texto">{{ Str::limit($plantilla->body, 70) }}</span>
                                    </button>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    {{-- Con una conversación abierta el número se esconde: ya sabes a quién escribes,
                         y en un móvil de 390px esa caja se llevaba una fila entera del hilo. Sigue
                         siendo el mismo campo —no dos con el mismo nombre—, solo que en `hidden`. --}}
                    <input :type="activePhone ? 'hidden' : 'text'" name="phone" x-model="destino" required
                           placeholder="18095551234" inputmode="numeric" aria-label="Teléfono destino"
                           class="wa-campo wa-campo--tel {{ $errors->has('phone') ? 'wa-campo--error' : '' }}">
                    <input type="text" name="body" x-model="borrador" required maxlength="4096"
                           placeholder="Escribe un mensaje…" autocomplete="off" aria-label="Mensaje" x-ref="cuerpo"
                           class="wa-campo {{ $errors->has('body') ? 'wa-campo--error' : '' }}">
                    <button type="submit" class="wa-enviar">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M6 12 3.269 3.125A59.77 59.77 0 0 1 21.485 12 59.77 59.77 0 0 1 3.27 20.875L5.999 12Zm0 0h7.5"/></svg>
                        Enviar
                    </button>
                    @error('phone') <p class="wa-redactor-error">{{ $message }}</p> @enderror
                    @error('body') <p class="wa-redactor-error">{{ $message }}</p> @enderror
                </form>
            </section>
        </div>

        {{-- ============ Asistente ============ --}}
        <div x-show="pestana === 'asistente'" x-cloak class="wa-bot">
            <div class="bmos-card bmos-card-pad">
                <form method="POST" action="{{ route('panel.whatsapp.bot') }}" x-data="{ info: @js(old('business_info', $ajustes?->business_info ?? '')) }">
                    @csrf

                    <div class="wa-bot-cabeza">
                        <div>
                            <h2 class="wa-bot-titulo">Contesta por ti</h2>
                            <p class="wa-bot-sub">
                                Responde a tus clientes con lo que le cuentes aquí abajo y con los precios y la
                                existencia de tu inventario. Si le preguntan algo que no sabe, o si piden hablar
                                con una persona, se aparta y te avisa.
                            </p>
                        </div>
                        @can('whatsapp.connect')
                            <label class="wa-palanca">
                                <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $ajustes?->is_active ?? false))>
                                <span class="wa-palanca-pista"></span>
                                <span class="wa-palanca-texto">Encendido</span>
                            </label>
                        @endcan
                    </div>

                    @can('whatsapp.connect')
                        {{-- Va lo primero porque decide todo lo demás: por dónde salen los mensajes,
                             qué se puede hacer y qué trámite hay que pasar. --}}
                        <div class="wa-bot-campo">
                            <span class="wa-bot-etiqueta">Cómo conectas WhatsApp</span>
                            <div class="wa-vias">
                                <label class="wa-via">
                                    <input type="radio" name="provider" value="{{ $vias['oficial'] }}"
                                           @checked(old('provider', $ajustes?->provider) === $vias['oficial'])>
                                    <span class="wa-via-cuerpo">
                                        <span class="wa-via-titulo">
                                            Oficial de Meta
                                            <span class="bmos-badge badge-green">Recomendada</span>
                                        </span>
                                        <span class="wa-via-texto">
                                            No pueden bloquearte el número y funciona sin que tengas nada
                                            encendido. Para contestar a tus clientes no cuesta dinero. Se
                                            da de alta con Meta Business y necesita un número que no esté
                                            en la app de WhatsApp.
                                        </span>
                                    </span>
                                </label>

                                <label class="wa-via">
                                    <input type="radio" name="provider" value="{{ $vias['qr'] }}"
                                           @checked(old('provider', $ajustes?->provider ?? $vias['qr']) === $vias['qr'])>
                                    <span class="wa-via-cuerpo">
                                        <span class="wa-via-titulo">Código QR</span>
                                        <span class="wa-via-texto">
                                            Se conecta en dos minutos escaneando, con el número que ya
                                            usas. No es la conexión oficial: <strong>Meta puede bloquear
                                            el número</strong>, y necesita un servidor propio encendido.
                                        </span>
                                    </span>
                                </label>
                            </div>
                            @error('provider') <p class="wa-redactor-error">{{ $message }}</p> @enderror
                        </div>
                    @endcan

                    {{-- Sin clave de IA el bot no redacta nada: se apartaría en cada mensaje. Decirlo
                         antes vale más que dejarlo encender y que parezca roto. --}}
                    @unless ($iaLista)
                        <div class="wa-aviso wa-aviso--grave">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0m-9 3.75h.008v.008H12z"/></svg>
                            <span>
                                <strong>Falta la clave de IA de la plataforma.</strong>
                                Sin ella el asistente no puede redactar y pasará todas las conversaciones a una
                                persona. Se configura en Administración › IA de la plataforma.
                            </span>
                        </div>
                    @endunless

                    @unless ($inbox['line']['connected'])
                        <div class="wa-aviso">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0m-9 3.75h.008v.008H12z"/></svg>
                            <span>La línea no está conectada: aunque lo enciendas, no llegará ningún mensaje hasta que vincules el teléfono.</span>
                        </div>
                    @endunless

                    <div class="wa-bot-campo">
                        <label for="business_info" class="wa-bot-etiqueta">Qué tiene que saber de tu negocio</label>
                        <p class="wa-bot-ayuda">
                            Horario, si haces delivery y a dónde, formas de pago, política de cambios, dónde
                            estás. Escríbelo como se lo contarías a un empleado nuevo.
                            <strong>Todo lo que pongas aquí es lo que el asistente puede prometer</strong>: lo
                            que no esté, no lo dirá.
                        </p>
                        <textarea id="business_info" name="business_info" rows="10" x-model="info"
                                  maxlength="{{ $maxInfo }}"
                                  class="bmos-input wa-bot-area @error('business_info') wa-campo--error @enderror"
                                  placeholder="Abrimos de lunes a sábado de 8:00 a 8:00 de la noche, y los domingos hasta el mediodía.&#10;Hacemos delivery en Santiago por 150 pesos; fuera de la ciudad no llegamos.&#10;Aceptamos efectivo, tarjeta y transferencia.&#10;Los cambios son dentro de los 7 días con la factura."></textarea>
                        <div class="wa-bot-pie">
                            <span x-text="`${info.length} / {{ $maxInfo }}`"></span>
                            @error('business_info') <span class="wa-redactor-error">{{ $message }}</span> @enderror
                        </div>
                    </div>

                    <div class="wa-bot-campo">
                        <label for="greeting" class="wa-bot-etiqueta">Cómo saluda</label>
                        <p class="wa-bot-ayuda">
                            Lo que contesta cuando alguien solo escribe «hola» o «klk». Esto no pasa por la IA
                            —siempre es lo mismo, así que no hay nada que redactar ni nada que inventar—.
                        </p>
                        <input type="text" id="greeting" name="greeting" maxlength="500"
                               value="{{ old('greeting', $ajustes?->greeting) }}"
                               class="bmos-input @error('greeting') wa-campo--error @enderror"
                               placeholder="¡Hola! Gracias por escribir a Colmado La Esquina. ¿En qué te ayudo?">
                        @error('greeting') <p class="wa-redactor-error">{{ $message }}</p> @enderror
                    </div>

                    {{-- El papel. Va DESPUÉS de los datos porque se toca mucho más a menudo: se
                         prueba un tono, se cambia la oferta. Los datos casi no se tocan. --}}
                    <div class="wa-bot-campo">
                        <label for="instructions" class="wa-bot-etiqueta">Cómo debe comportarse</label>
                        <p class="wa-bot-ayuda">
                            Quién es y qué debe hacer, no qué sabe. Por ejemplo: «Eres un asesor de BM
                            Business. Antes de recomendar un plan pregunta de qué es el negocio y cuánta
                            gente lo usa. Si le interesa, ofrécele una demo». <strong>Por mucho que
                            escribas aquí, nunca podrá inventarse precios ni prometer descuentos</strong>:
                            esas reglas mandan sobre esto.
                        </p>
                        <textarea id="instructions" name="instructions" rows="5" maxlength="{{ $maxInstrucciones }}"
                                  class="bmos-input wa-bot-area @error('instructions') wa-campo--error @enderror"
                                  placeholder="Eres un asesor de BM Business. Sé directo y cercano. Pregunta de qué es el negocio antes de recomendar nada, y ofrece una demo a quien muestre interés.">{{ old('instructions', $ajustes?->instructions) }}</textarea>
                        @error('instructions') <p class="wa-redactor-error">{{ $message }}</p> @enderror
                    </div>

                    <div class="wa-bot-campo">
                        <span class="wa-bot-etiqueta">De dónde más puede sacar respuestas</span>
                        <div class="wa-fuentes">
                            <label class="wa-fuente">
                                <input type="checkbox" name="uses_documents" value="1"
                                       @checked(old('uses_documents', $ajustes?->uses_documents ?? false))>
                                <span>
                                    <b>De tus documentos</b>
                                    <span class="wa-fuente-nota">
                                        Busca en lo que subas en Administración › IA antes de contestar. Sirve
                                        para lo que no cabe arriba: un folleto, preguntas frecuentes, la lista de
                                        funciones. <strong>Cuesta una consulta de IA más por cada mensaje.</strong>
                                    </span>
                                </span>
                            </label>

                            <label class="wa-fuente">
                                <input type="checkbox" name="includes_plans" value="1"
                                       @checked(old('includes_plans', $ajustes?->includes_plans ?? false))>
                                <span>
                                    <b>Los planes de BM Business y sus precios</b>
                                    <span class="wa-fuente-nota">
                                        Solo si lo que vendes es el sistema. Los lee de los planes reales, así que
                                        no se quedan viejos cuando cambies un precio.
                                    </span>
                                </span>
                            </label>
                        </div>
                    </div>

                    @can('whatsapp.connect')
                        <div class="wa-bot-acciones">
                            @if ($ajustes?->sent_count)
                                <span class="wa-bot-contador">
                                    Ha contestado <strong>{{ number_format((int) $ajustes->sent_count) }}</strong>
                                    {{ (int) $ajustes->sent_count === 1 ? 'mensaje' : 'mensajes' }}@if ($ajustes->last_sent_at), el último {{ $ajustes->last_sent_at->diffForHumans() }}@endif.
                                </span>
                            @endif
                            <button type="submit" class="bmos-btn bmos-btn-primary">Guardar</button>
                        </div>
                    @endcan
                </form>
            </div>

            {{-- Respuestas rápidas: lo que se escribe a mano veinte veces al día. --}}
            <div class="bmos-card bmos-card-pad">
                <h2 class="wa-bot-titulo">Respuestas rápidas</h2>
                <p class="wa-bot-sub">Textos que usas todo el rato. Aparecen en el rayo del cuadro de escritura.</p>

                <div class="wa-plantillas">
                    @forelse ($plantillas as $plantilla)
                        <div class="wa-plantilla">
                            <div class="min-w-0">
                                <p class="wa-plantilla-nombre">{{ $plantilla->name }}</p>
                                <p class="wa-plantilla-texto">{{ $plantilla->body }}</p>
                            </div>
                            @can('whatsapp.templates.manage')
                                <x-panel.confirm-action
                                    :action="route('panel.whatsapp.templates.destroy', $plantilla)"
                                    title="¿Borrar «{{ $plantilla->name }}»?"
                                    message="Deja de aparecer en el cuadro de escritura. Los mensajes que ya enviaste no cambian."
                                    tooltip="Borrar"
                                    class="rounded-lg p-1.5 text-slate-400 hover:bg-rose-50 hover:text-rose-600">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" style="width:1.05rem;height:1.05rem"><path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166M18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916"/></svg>
                                </x-panel.confirm-action>
                            @endcan
                        </div>
                    @empty
                        <p class="wa-bot-ayuda">Todavía no tienes ninguna.</p>
                    @endforelse
                </div>

                @can('whatsapp.templates.manage')
                    <form method="POST" action="{{ route('panel.whatsapp.templates.store') }}" class="wa-plantilla-alta">
                        @csrf
                        <input type="text" name="name" maxlength="80" required placeholder="Nombre (p. ej. «Horario»)"
                               class="bmos-input @error('name') wa-campo--error @enderror" value="{{ old('name') }}">
                        <input type="text" name="body" maxlength="1000" required placeholder="El texto que se envía"
                               class="bmos-input @error('body') wa-campo--error @enderror" value="{{ old('body') }}">
                        <button type="submit" class="bmos-btn bmos-btn-ghost">Añadir</button>
                        @error('name') <p class="wa-redactor-error">{{ $message }}</p> @enderror
                        @error('body') <p class="wa-redactor-error">{{ $message }}</p> @enderror
                    </form>
                @endcan
            </div>
        </div>
    </div>

    <script>
        function waConsola(inicial, qrInicial, urls) {
            return {
                conversations: inicial.conversations,
                thread: inicial.thread,
                activePhone: inicial.active_phone,
                activePaused: inicial.active_paused,
                linea: inicial.line,
                bot: inicial.bot,
                qr: qrInicial,

                pestana: 'bandeja',
                // En móvil solo cabe un panel: se enseña la lista, o el hilo. En pantalla ancha el
                // CSS ignora esto y pinta los dos.
                vista: inicial.active_phone ? 'hilo' : 'lista',
                rapidas: false,
                destino: @js(old('phone', $inbox['active_phone'])) ?? '',
                borrador: @js(old('body', '')),
                temporizador: null,

                get conversacion() {
                    return this.conversations.find((c) => c.phone === this.activePhone);
                },

                /** Cuántos clientes están esperando a una persona. Es la cifra que urge. */
                get esperando() {
                    return this.conversations.filter((c) => c.paused).length;
                },

                /*
                 * Cómo se lee el estado ya viene decidido del servidor.
                 *
                 * Antes este mapa estaba aquí, y el primer pintado no tenía ninguno: la etiqueta
                 * salía vacía hasta que arrancaba Alpine. Con dos mapas —uno para pintar y otro para
                 * refrescar— habría bastado tocar uno para que dijeran cosas distintas.
                 */

                /** La tira se abre sola cuando hay algo que hacer, y se encoge cuando no. */
                get abierta() {
                    return !this.linea.connected;
                },

                get esOficial() {
                    return this.bot.provider === urls.oficial;
                },

                /** Conectar no significa lo mismo en las dos vías, así que no se llama igual. */
                get textoConectar() {
                    if (this.esOficial) return 'Conectar con Meta';

                    return this.linea.state === 'missing' ? 'Vincular teléfono' : 'Reintentar vínculo';
                },

                /**
                 * Cuánto hay por encima de la consola.
                 *
                 * Se mide en vez de restarse a ojo porque la cabecera del panel no mide lo mismo en
                 * móvil que en escritorio: con un número fijo, el cuadro de escribir se quedaba por
                 * debajo del borde de la ventana en una de las dos y había que desplazar la página
                 * entera para escribir.
                 */
                medirAlto() {
                    const el = this.$refs.consola;
                    if (!el) return;

                    // Se lee el borde superior con la altura ya sin fijar, o se mediría sobre el
                    // resultado de la medición anterior y el valor se iría acumulando.
                    el.style.setProperty('--wa-arriba', '0px');
                    const arriba = el.getBoundingClientRect().top + window.scrollY;

                    /*
                     * Y lo que flota pegado ABAJO, que taparía el cuadro de escribir.
                     *
                     * Son dos y no siempre están: la burbuja del asistente y el banner de «instala la
                     * app». Comprobado en el navegador: con la bandeja a alto completo, el banner
                     * quedaba justo encima del campo del mensaje y la burbuja sobre el botón de
                     * enviar. No se reservan a ojo —el banner cambia de alto con su texto— y se
                     * vuelven a medir en cada sondeo, porque el banner aparece más tarde que la
                     * página, cuando el navegador decide que la app es instalable.
                     */
                    let abajo = 16;

                    for (const sel of ['.asis-boton', '#pwa-install-banner']) {
                        const flotante = document.querySelector(sel);
                        if (!flotante) continue;

                        const r = flotante.getBoundingClientRect();
                        if (r.height === 0) continue;

                        abajo = Math.max(abajo, window.innerHeight - r.top + 12);
                    }

                    el.style.setProperty('--wa-arriba', `${Math.round(arriba)}px`);
                    el.style.setProperty('--wa-abajo', `${Math.round(abajo)}px`);
                },

                init() {
                    this.medirAlto();
                    window.addEventListener('resize', () => this.medirAlto());
                    this.$nextTick(() => this.abajo());

                    // Sondeo: los entrantes llegan por webhook, los salientes cambian de estado al
                    // salir de la cola, y la línea cambia cuando alguien escanea el QR. Se pausa si
                    // la pestaña no está visible.
                    this.temporizador = setInterval(() => {
                        if (document.visibilityState !== 'visible') return;

                        // El banner de instalación aparece cuando el navegador quiere, no al cargar.
                        this.medirAlto();
                        this.refrescar();
                    }, 4000);

                    document.addEventListener('visibilitychange', () => {
                        if (document.visibilityState === 'visible') this.refrescar();
                    });
                },

                destroy() {
                    clearInterval(this.temporizador);
                },

                /** Abre una conversación sin recargar. */
                async abrir(phone) {
                    // Vaciar el hilo SOLO si de verdad se cambia de conversación: al volver a pulsar
                    // la que ya está abierta, limpiarlo hacía parpadear los mensajes durante el
                    // segundo que tarda el sondeo en devolver exactamente los mismos.
                    if (phone !== this.activePhone) this.thread = [];

                    this.activePhone = phone;
                    this.destino = phone;
                    this.vista = 'hilo';

                    // La URL sigue al hilo abierto para que recargar o compartir el enlace lleve al
                    // mismo sitio. `replaceState` y no `pushState`: cada clic en la lista no debería
                    // añadir una parada al botón de atrás.
                    history.replaceState({}, '', `${urls.bandeja}?c=${encodeURIComponent(phone)}`);

                    await this.refrescar();
                    this.$nextTick(() => this.abajo());
                },

                nuevo() {
                    this.activePhone = null;
                    this.activePaused = false;
                    this.thread = [];
                    this.destino = '';
                    this.vista = 'hilo';
                    history.replaceState({}, '', urls.bandeja);
                    this.$nextTick(() => this.$refs.cuerpo?.focus());
                },

                usar(texto) {
                    this.borrador = texto;
                    this.rapidas = false;
                    this.$nextTick(() => this.$refs.cuerpo?.focus());
                },

                async refrescar() {
                    try {
                        const url = urls.sondeo + (this.activePhone ? '?c=' + encodeURIComponent(this.activePhone) : '');
                        const res = await fetch(url, { headers: { Accept: 'application/json' } });
                        if (!res.ok) return;

                        const datos = await res.json();
                        const crecio = datos.thread.length > this.thread.length;
                        const abajo = this.pegadoAbajo();

                        this.conversations = datos.conversations;
                        this.thread = datos.thread;
                        this.activePaused = datos.active_paused;
                        this.linea = datos.line;
                        this.bot = datos.bot;
                        if (!this.activePhone) this.activePhone = datos.active_phone;

                        // El QR ya no sirve para nada en cuanto la línea está dentro.
                        if (datos.line.connected) this.qr = null;

                        // Solo se auto-desplaza si el usuario ya estaba abajo: no le robamos la
                        // posición mientras lee mensajes antiguos.
                        if (crecio && abajo) this.$nextTick(() => this.abajo());
                    } catch {
                        // Un fallo de red puntual no debe romper la bandeja: se reintenta al próximo ciclo.
                    }
                },

                pegadoAbajo() {
                    const el = this.$refs.lienzo;
                    return el ? el.scrollHeight - el.scrollTop - el.clientHeight < 120 : true;
                },

                abajo() {
                    const el = this.$refs.lienzo;
                    if (el) el.scrollTop = el.scrollHeight;
                },
            };
        }
    </script>
</x-layouts.admin>
