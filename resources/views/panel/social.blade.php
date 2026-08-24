@use('App\Modules\Social\Enums\SocialPlatform')

{{--
    Redes sociales: publicar en todas a la vez desde el panel.

    La pantalla NO guarda copia de nada. Las cuentas y las publicaciones se preguntan cada vez, y esa
    es la decisión de fondo: duplicarlas aquí crearía dos verdades que se separan en cuanto alguien
    publique desde el móvil o borre una foto desde la propia app de Instagram, y entonces el panel
    mentiría sin que nadie pudiera saber cuál de los dos tiene razón.
--}}
<x-layouts.admin title="Redes sociales" heading="Redes sociales"
                 subheading="Publica en todas tus redes a la vez, ahora o programado">
    <div class="mx-auto max-w-5xl">
        @if ($aviso)
            <div class="mb-5 flex items-start gap-3 rounded-2xl border border-amber-200 bg-amber-50 p-4">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="mt-0.5 h-5 w-5 shrink-0 text-amber-600">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z"/>
                </svg>
                <p class="text-sm font-medium text-amber-900">{{ $aviso }}</p>
            </div>
        @endif

        @if (! $configurado)
            {{-- Sin clave no hay nada que enseñar, así que la pantalla es solo esto. --}}
            <div class="bmos-card overflow-hidden">
                <div class="border-b border-slate-100 bg-gradient-to-br from-indigo-50 to-violet-50 p-6">
                    <span class="grid h-11 w-11 place-items-center rounded-xl bg-white text-indigo-600 shadow-sm ring-1 ring-indigo-100">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" class="h-5 w-5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M13.19 8.688a4.5 4.5 0 0 1 1.242 7.244l-4.5 4.5a4.5 4.5 0 0 1-6.364-6.364l1.757-1.757m13.35-.622 1.757-1.757a4.5 4.5 0 0 0-6.364-6.364l-4.5 4.5a4.5 4.5 0 0 0 1.242 7.244"/>
                        </svg>
                    </span>
                    <p class="mt-3 text-lg font-semibold text-slate-800">Conecta tu cuenta de Zernio</p>
                    <p class="mt-1 max-w-xl text-sm leading-relaxed text-slate-600">
                        Tus redes se conectan a través de <b>Zernio</b>, que es quien publica en Instagram,
                        Facebook y las demás. Necesitas una cuenta suya y su clave; se crea en
                        <a href="https://zernio.com" target="_blank" rel="noopener" class="font-medium text-indigo-600 hover:underline">zernio.com</a>.
                    </p>
                </div>

                @can('social.connect')
                    <form method="POST" action="{{ route('panel.social.key') }}" class="p-6">
                        @csrf
                        @method('PUT')
                        <label class="bmos-field-label">Clave de Zernio</label>
                        <div class="flex flex-wrap items-start gap-2">
                            <input type="password" name="api_key" class="bmos-input min-w-0 flex-1 font-mono"
                                   placeholder="sk_..." autocomplete="off" required>
                            <button type="submit" class="bmos-btn bmos-btn-primary shrink-0">Guardar clave</button>
                        </div>
                        <p class="mt-2 text-xs text-slate-400">
                            Empieza por «sk_». Se guarda cifrada y no se vuelve a mostrar.
                        </p>
                    </form>
                @else
                    <p class="p-6 text-sm text-slate-500">Pídele a tu encargado que la configure.</p>
                @endcan
            </div>
        @else
            {{-- Cuentas conectadas --}}
            <div class="bmos-card overflow-hidden">
                <div class="border-b border-slate-100 p-5">
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Tus cuentas</p>
                    <p class="mt-0.5 font-semibold text-slate-800">Las redes donde este negocio puede publicar</p>
                </div>

                @if ($cuentas === [])
                    <p class="bmos-empty">
                        Todavía no has conectado ninguna red. Elige una abajo y autorízala.
                    </p>
                @else
                    <div class="grid grid-cols-1 gap-3 p-5 sm:grid-cols-2">
                        @foreach ($cuentas as $cuenta)
                            @php $red = SocialPlatform::tryFrom((string) $cuenta['platform']); @endphp
                            <div class="bmos-cuenta bmos-marca {{ $cuenta['necesita_reconectar'] ? 'is-caducada' : '' }}"
                                 style="--tono: {{ $red?->color() ?? '#94a3b8' }}">
                                @if (filled($cuenta['avatar']))
                                    <img src="{{ $cuenta['avatar'] }}" alt="" class="h-11 w-11 shrink-0 rounded-full object-cover ring-2 ring-white">
                                @else
                                    <span class="h-11 w-11 shrink-0 rounded-full" style="background: {{ $red?->color() ?? '#94a3b8' }}"></span>
                                @endif
                                <div class="min-w-0 flex-1">
                                    <p class="truncate font-semibold text-slate-800">{{ $cuenta['name'] }}</p>
                                    <p class="truncate text-xs text-slate-500">
                                        {{ $red?->label() ?? $cuenta['platform'] }}
                                        @if (filled($cuenta['username'])) · &#64;{{ $cuenta['username'] }} @endif
                                    </p>
                                    {{-- Una cuenta caducada no da error al publicar: se traga el post y
                                         no sale nada. Decirlo aquí evita que el dueño lo descubra
                                         días después preguntándose por qué nadie vio su oferta. --}}
                                    @if ($cuenta['necesita_reconectar'])
                                        <p class="mt-0.5 text-xs font-semibold text-amber-700">Hay que volver a conectarla: no publicará.</p>
                                    @endif
                                </div>
                                @if ($cuenta['followers'] !== null && ! $cuenta['necesita_reconectar'])
                                    <div class="shrink-0 text-right">
                                        <p class="font-semibold text-slate-800">{{ number_format((int) $cuenta['followers']) }}</p>
                                        <p class="text-[11px] text-slate-400">seguidores</p>
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endif

                @can('social.connect')
                    <div class="border-t border-slate-100 bg-slate-50/60 p-5">
                        <p class="mb-2.5 text-sm font-medium text-slate-700">Conectar otra red</p>
                        <div class="flex flex-wrap gap-2">
                            @foreach ($plataformas as $red)
                                <form method="POST" action="{{ route('panel.social.connect') }}">
                                    @csrf
                                    <input type="hidden" name="platform" value="{{ $red->value }}">
                                    <button type="submit" class="bmos-red" style="--tono: {{ $red->color() }}">
                                        <span class="bmos-red-punto"></span>
                                        {{ $red->label() }}
                                    </button>
                                </form>
                            @endforeach
                        </div>
                    </div>
                @endcan
            </div>

            @can('social.publish')
                {{-- Respuestas automáticas. Va antes de redactar porque una vez montada trabaja sola,
                     y publicar es lo que hay que hacer cada día. --}}
                <a href="{{ route('panel.social.automations') }}"
                   class="bmos-card mt-5 flex items-center gap-4 p-5 transition hover:border-indigo-300 hover:shadow-md">
                    <span class="grid h-11 w-11 shrink-0 place-items-center rounded-xl bg-indigo-50 text-indigo-600">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" class="h-5 w-5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09ZM18.259 8.715 18 9.75l-.259-1.035a3.375 3.375 0 0 0-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 0 0 2.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 0 0 2.456 2.456L21.75 6l-1.035.259a3.375 3.375 0 0 0-2.456 2.456Z"/>
                        </svg>
                    </span>
                    <div class="min-w-0 flex-1">
                        <p class="font-semibold text-slate-800">Respuestas automáticas</p>
                        <p class="text-sm text-slate-500">
                            Que quien comente «precio» reciba el catálogo por privado, sin que tú estés mirando.
                        </p>
                    </div>
                    <span class="shrink-0 text-sm font-semibold text-indigo-600">Configurar →</span>
                </a>

                {{-- Bienvenida a quien escribe.

                     OJO CON LO QUE ESTO NO ES, porque es lo primero que se pregunta todo el mundo:
                     no saluda a quien te sigue. Instagram no lo permite —seguir no abre la ventana
                     de mensajería, la abre la persona al escribir— y la API ni siquiera avisa de los
                     seguidores nuevos. Se dice aquí, en la pantalla, para no prometerlo. --}}
                {{-- `$bienvenida` es null mientras falte aplicar la migración: no se pinta el bloque y el
                     resto de la pantalla —conectar cuentas, publicar— sigue funcionando. --}}
                @if ($bienvenida)
                @can('social.publish')
                    <div class="bmos-card mt-5 overflow-hidden"
                         x-data="{ encendida: {{ $bienvenida->is_active ? 'true' : 'false' }}, variantes: @js(array_values($bienvenida->variations ?? [])) }">
                        <form method="POST" action="{{ route('panel.social.welcome') }}">
                            @csrf
                            @method('PUT')

                            <div class="flex flex-wrap items-start justify-between gap-3 border-b border-slate-100 p-5">
                                <div class="min-w-0">
                                    <p class="font-semibold text-slate-800">Bienvenida automática</p>
                                    <p class="mt-0.5 text-sm text-slate-500">
                                        Cuando alguien te escriba por primera vez por Instagram o Facebook,
                                        recibe tu mensaje al momento, aunque sea de madrugada.
                                    </p>
                                </div>
                                <label class="flex shrink-0 cursor-pointer items-center gap-2 text-sm font-medium text-slate-700">
                                    <input type="checkbox" name="is_active" value="1" class="rounded border-slate-300"
                                           x-model="encendida">
                                    Encendida
                                </label>
                            </div>

                            <div class="p-5">
                                <label class="bmos-field-label">El mensaje</label>
                                <textarea name="message" rows="3" class="bmos-input" maxlength="900"
                                          placeholder="¡Gracias por escribirnos! Somos La Batidera. Dinos qué necesitas y te ayudamos.">{{ old('message', $bienvenida->message) }}</textarea>
                                @error('message')
                                    <p class="mt-1 text-xs text-rose-600">{{ $message }}</p>
                                @enderror

                                {{-- Variaciones. Una bienvenida es, por definición, el mensaje que más
                                     se repite de todos: si sale siempre igual, es el primero que
                                     Instagram marca. --}}
                                <template x-for="(v, i) in variantes" :key="i">
                                    <div class="mt-2 flex items-start gap-2">
                                        <span class="mt-2.5 text-xs font-semibold text-slate-400">o</span>
                                        <textarea :name="`variations[${i}]`" rows="2" class="bmos-input"
                                                  maxlength="900" x-model="variantes[i]"></textarea>
                                        <button type="button" class="bmos-btn bmos-btn-ghost mt-1 px-2 text-rose-500"
                                                @click="variantes.splice(i, 1)" title="Quitar">✕</button>
                                    </div>
                                </template>

                                <button type="button" class="bmos-btn bmos-btn-ghost mt-2 text-xs"
                                        x-show="variantes.length < {{ $maxVariaciones }}"
                                        @click="variantes.push('')">
                                    + Otra forma de decirlo
                                </button>

                                <p class="mt-2 text-xs text-slate-400">
                                    @if ($bienvenida->sent_count > 0)
                                        Enviada {{ number_format($bienvenida->sent_count) }}
                                        {{ $bienvenida->sent_count === 1 ? 'vez' : 'veces' }}@if ($bienvenida->last_sent_at), la última {{ $bienvenida->last_sent_at->diffForHumans() }}@endif.
                                    @else
                                        Cada persona la recibe una sola vez, por mucho que te escriba.
                                    @endif
                                </p>

                                <div class="mt-3 rounded-xl border border-slate-200 bg-slate-50 p-3 text-xs text-slate-500">
                                    <b class="text-slate-700">No se le manda a quien te sigue.</b>
                                    Instagram solo deja escribirle a alguien que te escribió, comentó o
                                    respondió una historia. Para llegar a quien te acaba de seguir, lo que
                                    funciona es una
                                    <a href="{{ route('panel.social.automations') }}" class="font-semibold text-indigo-600 hover:underline">respuesta automática</a>
                                    en tus publicaciones o historias.
                                </div>

                                <button type="submit" class="bmos-btn bmos-btn-primary mt-4">Guardar</button>
                            </div>
                        </form>
                    </div>
                @endcan
                @endif

                {{-- Redactar --}}
                <div class="bmos-card mt-5 overflow-hidden" x-data="redactor()">
                    {{-- Un rótulo con icono, no dos líneas de texto sueltas.

                         Esta tarjeta cae la cuarta de una columna de rectángulos blancos
                         —automatizaciones, bienvenida, publicar, historial— y sin nada que la
                         distinga hay que leerlas todas para dar con la única que se usa cada día. --}}
                    <div class="flex items-center gap-3 border-b border-slate-100 bg-gradient-to-br from-indigo-50 via-white to-violet-50 p-5">
                        <span class="grid h-11 w-11 shrink-0 place-items-center rounded-xl bg-white text-indigo-600 shadow-sm ring-1 ring-indigo-100">
                            <x-icono name="megafono" class="h-5 w-5" />
                        </span>
                        <div class="min-w-0">
                            <p class="text-xs font-semibold uppercase tracking-wide text-indigo-400">Publicar</p>
                            <p class="font-semibold text-slate-800">Nueva publicación</p>
                        </div>
                    </div>

                    <form method="POST" action="{{ route('panel.social.publish') }}" class="p-5"
                          @submit="preparando = true">
                        @csrf

                        <div class="grid grid-cols-1 gap-5 lg:grid-cols-[minmax(0,1fr)_18rem]">
                            <div class="space-y-4">
                                <div>
                                    <label class="bmos-field-label">¿Qué quieres contar?</label>
                                    {{-- El campo y su contador, dentro de UNA sola caja.

                                         Antes eran dos piezas sueltas: el cuadro con su borde y, debajo y al
                                         aire, el contador con su barrita. Suelto, el contador parecía medir la
                                         pantalla entera en vez del texto que tiene justo encima. --}}
                                    <div class="bmos-redactar">
                                        <textarea name="content" rows="5" class="bmos-redactar-campo" required
                                                  maxlength="5000" x-model="texto"
                                                  placeholder="Hoy tenemos batida de guineo a RD$150. Te la llevamos a la casa."></textarea>
                                        {{-- El contador cambia de color al acercarse al tope de la red MÁS CORTA de
                                             las elegidas, no al de 5000. Instagram corta en 2.200 y X en 280: un
                                             contador que dice «1.800 / 5000» en verde mientras el texto ya no cabe en
                                             Instagram no está informando, está tranquilizando en falso. --}}
                                        <div class="bmos-redactar-pie">
                                            <p class="text-xs tabular-nums"
                                               :class="pasaDelTope ? 'font-semibold text-rose-600' : 'text-slate-400'">
                                                <span x-text="texto.length"></span> / <span x-text="topeActual"></span>
                                                <span x-show="redDelTope" x-cloak x-text="'· tope de ' + redDelTope"></span>
                                            </p>
                                            <div class="bmos-medidor">
                                                <div class="bmos-medidor-barra"
                                                     :class="pasaDelTope ? 'is-pasado' : (texto.length / topeActual > 0.85 ? 'is-cerca' : '')"
                                                     :style="'width: ' + Math.min(100, (texto.length / topeActual) * 100) + '%'"></div>
                                            </div>
                                        </div>
                                    </div>

                                    {{-- Los asteriscos de las negritas.
                                         Ninguna red social entiende Markdown: lo que se escribe como **negrita**
                                         sale con los asteriscos a la vista de los seguidores. Es fácil de escribir
                                         sin querer cuando el texto lo redacta una IA, y solo se descubre mirando la
                                         publicación ya hecha. --}}
                                    <p x-show="tieneMarcado" x-cloak
                                       class="mt-1.5 rounded-lg border border-amber-200 bg-amber-50 px-2.5 py-1.5 text-xs text-amber-800">
                                        Las redes no entienden <b>**negritas**</b>: esos asteriscos se van a ver tal cual.
                                        <button type="button" @click="quitarMarcado()" class="ml-1 font-semibold underline">Quitarlos</button>
                                    </p>
                                </div>

                                <div>
                                    <label class="bmos-field-label">¿Dónde se publica?</label>
                                    @if ($cuentas === [])
                                        <p class="text-sm text-amber-600">Conecta al menos una red antes de publicar.</p>
                                    @else
                                        <div class="flex flex-wrap gap-2">
                                            @foreach ($cuentas as $cuenta)
                                                @php $red = SocialPlatform::tryFrom((string) $cuenta['platform']); @endphp
                                                {{-- La ficha ENTERA es el interruptor, y encendida se tiñe del
                                                     color de la red. La casilla del navegador sigue estando ahí,
                                                     solo que invisible: es la que viaja en el formulario y la que
                                                     recibe el tabulador, así que borrarla dejaría esta elección
                                                     fuera del alcance de quien no usa ratón.

                                                     Las que hay que reconectar salen apagadas y no se dejan
                                                     marcar: elegirlas daría un «publicado» que no publica nada. --}}
                                                <label class="bmos-chip {{ $cuenta['necesita_reconectar'] ? 'is-caducada' : '' }}"
                                                       style="--tono: {{ $red?->color() ?? '#94a3b8' }}">
                                                    {{-- Plataforma e id viajan juntos: Zernio necesita los dos y el
                                                         servidor los separa, para que el navegador no decida
                                                         qué cuenta pertenece a qué red. --}}
                                                    <input type="checkbox" name="accounts[]" class="bmos-chip-caja"
                                                           x-model="destinos"
                                                           @disabled($cuenta['necesita_reconectar'])
                                                           value="{{ $cuenta['platform'] }}|{{ $cuenta['id'] }}">
                                                    @if (filled($cuenta['avatar']))
                                                        <img src="{{ $cuenta['avatar'] }}" alt="" class="bmos-chip-foto">
                                                    @else
                                                        <span class="bmos-chip-foto bmos-chip-punto"></span>
                                                    @endif
                                                    <span class="truncate">{{ $cuenta['name'] }}</span>
                                                    <span class="bmos-chip-marca">
                                                        <x-icono name="check" class="h-3 w-3" stroke-width="3" />
                                                    </span>
                                                </label>
                                            @endforeach
                                        </div>
                                        <p class="mt-2 text-xs text-slate-400">Toca las que quieras: sale en todas a la vez.</p>
                                    @endif
                                </div>

                                {{-- La imagen se sube DIRECTAMENTE del navegador a Zernio: en producción el
                                     disco es de solo lectura y no hay librerías de imagen, así que hacerla
                                     pasar por aquí sería código que solo funciona en local. --}}
                                <div>
                                    {{-- «Opcional» solo mientras no haya una red de imagen elegida:
                                         con Instagram marcado deja de serlo, y el rótulo lo dice. --}}
                                    <label class="bmos-field-label">
                                        Foto o vídeo
                                        <span x-show="faltaFoto.length === 0">(opcional)</span>
                                        <span x-show="faltaFoto.length > 0" x-cloak class="text-rose-500">*</span>
                                    </label>
                                    {{-- Zona para soltar, en vez del control desnudo del navegador.
                                         El de antes solo decía «Sin archivos seleccionados» y NO enseñaba qué se
                                         iba a publicar; publicar la foto equivocada en una cuenta con dos mil
                                         seguidores no se deshace. --}}
                                    <div x-show="!mediaUrl && !subiendo" x-cloak
                                         class="bmos-soltar" :class="arrastrando && 'is-encima'"
                                         @dragover.prevent="arrastrando = true"
                                         @dragleave.prevent="arrastrando = false"
                                         @drop.prevent="arrastrando = false; subirArchivo($event.dataTransfer.files[0])">
                                        <input type="file" accept="image/jpeg,image/png,image/webp,video/mp4"
                                               @change="subir($event)">
                                        <span class="bmos-soltar-icono">
                                            <x-icono name="foto" class="h-5 w-5" />
                                        </span>
                                        {{-- El rótulo cambia mientras se arrastra. Es la diferencia entre
                                             «aquí hay algo que se puede pulsar» y «suelta AQUÍ»: sin ese
                                             cambio, quien viene arrastrando una foto no sabe si el sitio la
                                             va a recoger hasta que la suelta. --}}
                                        <span class="bmos-soltar-texto">
                                            <b x-show="!arrastrando">Arrastra tu foto o vídeo aquí</b>
                                            <b x-show="arrastrando" x-cloak>Suéltalo y empieza a subir</b>
                                            <span class="bmos-soltar-pista">o toca para elegirlo de tu equipo</span>
                                        </span>
                                        <span class="bmos-soltar-formatos">
                                            <span class="bmos-soltar-formato">JPG</span>
                                            <span class="bmos-soltar-formato">PNG</span>
                                            <span class="bmos-soltar-formato">WEBP</span>
                                            <span class="bmos-soltar-formato">MP4</span>
                                        </span>
                                    </div>

                                    {{-- Con un vídeo esto tarda. Antes solo ponía «Subiendo...» y no se
                                         distinguía una subida lenta de una colgada; por eso van juntos el
                                         nombre del archivo, el porcentaje y la barra. --}}
                                    <div x-show="subiendo" x-cloak class="bmos-subiendo">
                                        <span class="bmos-subiendo-icono">
                                            <x-icono name="subir" class="h-4 w-4" />
                                        </span>
                                        <div class="min-w-0 flex-1">
                                            <div class="flex items-baseline justify-between gap-2">
                                                <p class="truncate text-sm font-medium text-slate-700"
                                                   x-text="nombreArchivo || 'Subiendo el archivo'"></p>
                                                <p class="shrink-0 text-xs font-semibold tabular-nums text-indigo-600">
                                                    <span x-text="progreso"></span>%
                                                </p>
                                            </div>
                                            <div class="bmos-progreso mt-2">
                                                <div class="bmos-progreso-barra" :style="'width: ' + progreso + '%'"></div>
                                            </div>
                                        </div>
                                    </div>

                                    <div x-show="mediaUrl && !subiendo" x-cloak class="bmos-media">
                                        <span class="bmos-media-marco">
                                            <template x-if="mediaTipo === 'video'">
                                                <video class="bmos-media-foto" :src="mediaUrl" muted></video>
                                            </template>
                                            <template x-if="mediaTipo !== 'video'">
                                                <img class="bmos-media-foto" :src="mediaUrl" alt="">
                                            </template>
                                            {{-- Un vídeo parado se ve igual que una foto. El rótulo dice cuál
                                                 de los dos es, que es justo lo que decide si Instagram lo
                                                 publica como reel o como imagen. --}}
                                            <span x-show="mediaTipo === 'video'" x-cloak class="bmos-media-tipo">Vídeo</span>
                                        </span>
                                        <div class="min-w-0 flex-1">
                                            <p class="truncate text-sm font-medium text-slate-700" x-text="nombreArchivo || 'Archivo listo'"></p>
                                            <span class="bmos-media-listo">
                                                <x-icono name="check" class="h-3 w-3" stroke-width="3" />
                                                Listo para publicar
                                            </span>
                                        </div>
                                        <button type="button" @click="quitarMedia()" class="bmos-media-quitar" title="Quitar">
                                            <x-icono name="cerrar" class="h-4 w-4" />
                                        </button>
                                    </div>

                                    <input type="hidden" name="media_url" x-model="mediaUrl">
                                    <input type="hidden" name="media_type" x-model="mediaTipo">
                                    <p x-show="faltaFoto.length > 0" x-cloak
                                       class="mt-1.5 rounded-lg border border-amber-200 bg-amber-50 px-2.5 py-1.5 text-xs text-amber-800">
                                        <b x-text="faltaFoto.join(' y ')"></b>
                                        <span x-text="faltaFoto.length === 1 ? 'no publica' : 'no publican'"></span>
                                        solo texto: añade una foto o un vídeo.
                                    </p>
                                    <p x-show="errorMedia" x-cloak x-text="errorMedia" class="mt-1 text-xs text-rose-600"></p>
                                </div>
                            </div>

                            {{-- Cuándo y el botón, apartados a un lado: son la decisión final, no parte
                                 de redactar. --}}
                            <div class="space-y-4">
                            {{-- Cómo va a quedar. Es la pieza que evita el error más caro de esta pantalla:
                                 publicar algo que no se ve como uno creía. Y de paso enseña, sin explicar
                                 nada, que los asteriscos de las negritas salen tal cual. --}}
                            <div x-show="texto.length > 0 || mediaUrl" x-cloak class="bmos-previa">
                                <div class="bmos-previa-cabecera">
                                    <span class="bmos-previa-avatar"></span>
                                    <div class="min-w-0">
                                        <p class="truncate text-sm font-semibold text-slate-800"
                                           x-text="nombreDestino || 'Tu cuenta'"></p>
                                        <p class="text-xs text-slate-400" x-text="programar ? 'Programado' : 'Ahora'"></p>
                                    </div>
                                </div>
                                <template x-if="mediaUrl && mediaTipo !== 'video'">
                                    <img class="bmos-previa-foto" :src="mediaUrl" alt="">
                                </template>
                                <template x-if="mediaUrl && mediaTipo === 'video'">
                                    <video class="bmos-previa-foto" :src="mediaUrl" muted controls></video>
                                </template>
                                <p class="bmos-previa-texto" x-text="texto || 'Escribe algo y aparecerá aquí.'"></p>
                            </div>

                            <div class="bmos-decidir">
                                <p class="flex items-center gap-2 text-sm font-semibold text-slate-700">
                                    <x-icono name="reloj" class="h-4 w-4 text-slate-400" />
                                    ¿Cuándo?
                                </p>
                                {{-- Dos botones en vez de una casilla suelta.

                                     «Ahora» y «programado» son dos caminos distintos, y una casilla solo
                                     enseña uno: el otro está implícito en no marcarla. Con los dos a la
                                     vista se ve de un golpe cuál está elegido, que es lo que hay que saber
                                     antes de pulsar un botón que publica de verdad. --}}
                                <div class="bmos-segmento mt-2.5">
                                    <button type="button" class="bmos-segmento-op" :class="!programar && 'is-activa'"
                                            @click="programar = false">Ahora</button>
                                    <button type="button" class="bmos-segmento-op" :class="programar && 'is-activa'"
                                            @click="programar = true">Programar</button>
                                </div>
                                <input x-show="programar" x-cloak type="datetime-local" name="scheduled_for"
                                       class="bmos-input mt-2.5" :required="programar">
                                <p x-show="programar" x-cloak class="mt-1.5 text-xs text-slate-400">
                                    La hora es la tuya: si pones las 8, sale a las 8 de aquí.
                                </p>

                                {{-- Apagado mientras falte la foto que la red exige: dejar pulsar un
                                     botón cuyo resultado ya se sabe que es un rechazo no es una
                                     libertad, es una pérdida de tiempo con la oferta escrita. --}}
                                <button type="submit" :disabled="subiendo || preparando || faltaFoto.length > 0 || pasaDelTope"
                                        class="bmos-btn bmos-btn-primary mt-3 w-full justify-center disabled:cursor-not-allowed disabled:opacity-50">
                                    <x-icono name="enviar" class="h-4 w-4" x-show="!preparando" />
                                    <span x-show="!preparando" x-text="programar ? 'Programar' : 'Publicar ahora'"></span>
                                    <span x-show="preparando" x-cloak>Enviando…</span>
                                </button>

                                {{-- Por qué está apagado el botón. Un botón gris sin explicación se lee como
                                     una avería del sistema, no como algo que falta por hacer. --}}
                                <p x-show="pasaDelTope" x-cloak class="mt-2 text-xs font-medium text-rose-600">
                                    El texto no cabe en <span x-text="redDelTope"></span>.
                                </p>
                            </div>
                            </div>
                        </div>
                    </form>
                </div>
            @endcan

            {{-- Lo publicado.
                 Cada fila enseña la FOTO —que en Instagram es la publicación, y el texto solo el
                 pie— y el estado de CADA destino por separado. Antes llevaba una sola etiqueta para
                 todo, y «publicado a medias» no decía cuál de las mitades había fallado. --}}
            <div class="bmos-card mt-5 overflow-hidden"
                 x-data="{
                     filtro: 'todas',
                     abierta: null,
                 }">
                <div class="border-b border-slate-100 p-5">
                    <div class="flex flex-wrap items-end justify-between gap-3">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Historial</p>
                            <p class="mt-0.5 font-semibold text-slate-800">Últimas publicaciones</p>
                        </div>

                        @if ($publicaciones !== [])
                            @php
                                // Se cuentan aquí y no en el navegador: la cifra tiene que estar bien
                                // desde el primer instante, antes de que Alpine arranque.
                                $porEstado = collect($publicaciones)->groupBy('estado')->map->count();
                                $pestanas = [
                                    'todas' => 'Todas ('.count($publicaciones).')',
                                    'scheduled' => 'Programadas ('.($porEstado['scheduled'] ?? 0).')',
                                    'published' => 'Publicadas ('.($porEstado['published'] ?? 0).')',
                                    'failed' => 'Fallidas ('.($porEstado['failed'] ?? 0).')',
                                ];
                            @endphp
                            <div class="bmos-pestanas">
                                @foreach ($pestanas as $clave => $rotulo)
                                    {{-- Las que están a cero se ofrecen igual: que no haya ninguna fallida
                                         es justamente lo que se quiere poder comprobar de un vistazo. --}}
                                    <button type="button" @click="filtro = '{{ $clave }}'"
                                            :class="filtro === '{{ $clave }}' && 'is-activa'"
                                            class="bmos-pestana">{{ $rotulo }}</button>
                                @endforeach
                            </div>
                        @endif
                    </div>
                </div>

                @forelse ($publicaciones as $post)
                    <div class="bmos-post" data-estado="{{ $post['estado'] }}"
                         x-show="filtro === 'todas' || filtro === '{{ $post['estado'] }}'" x-cloak>

                        {{-- La miniatura. Un vídeo se marca: no es lo mismo revisar una foto que un reel. --}}
                        <div>
                            @if (filled($post['foto']))
                                <div class="relative">
                                    @if ($post['es_video'])
                                        {{-- Un vídeo NO se pinta con <img>: un .mp4 ahí sale como imagen rota,
                                             que es exactamente lo que pasaba. Con <video> y `preload="metadata"`
                                             el navegador baja solo la cabecera y enseña el primer fotograma, sin
                                             descargar el archivo entero por una miniatura de 56 píxeles. --}}
                                        <video src="{{ $post['foto'] }}" class="bmos-post-foto"
                                               preload="metadata" muted playsinline></video>
                                        <span class="absolute bottom-1 right-1 rounded bg-slate-900/70 px-1 text-[9px] font-bold text-white">VÍDEO</span>
                                    @else
                                        {{-- Si la imagen no carga —el enlace de la red caduca— se esconde y queda
                                             el hueco del mismo tamaño. Un cuadro gris roto se lee como que la
                                             publicación falló, y no falló: es la miniatura la que no está. --}}
                                        <img src="{{ $post['foto'] }}" alt="" class="bmos-post-foto" loading="lazy"
                                             onerror="this.closest('.relative').querySelector('[data-sinfoto]').hidden = false; this.remove();">
                                        @if ($post['medias'] > 1)
                                            <span class="absolute bottom-1 right-1 rounded bg-slate-900/70 px-1 text-[9px] font-bold text-white">+{{ $post['medias'] - 1 }}</span>
                                        @endif
                                    @endif

                                    <div data-sinfoto hidden class="bmos-post-foto bmos-post-sinfoto">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="width:1.4rem;height:1.4rem"><path stroke-linecap="round" stroke-linejoin="round" d="m2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909M18 9.75h.008v.008H18V9.75Zm.75 0a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0Z"/></svg>
                                    </div>
                                </div>
                            @else
                                <div class="bmos-post-foto bmos-post-sinfoto">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="width:1.4rem;height:1.4rem"><path stroke-linecap="round" stroke-linejoin="round" d="M12 7.5h1.5m-1.5 3h1.5m-7.5 3h7.5m-7.5 3h7.5m3-9h3.375c.621 0 1.125.504 1.125 1.125V18a2.25 2.25 0 0 1-2.25 2.25M16.5 7.5V18a2.25 2.25 0 0 0 2.25 2.25M16.5 7.5V4.875c0-.621-.504-1.125-1.125-1.125H4.125C3.504 3.75 3 4.254 3 4.875V18a2.25 2.25 0 0 0 2.25 2.25h13.5M6 7.5h3v3H6v-3Z"/></svg>
                                </div>
                            @endif
                        </div>

                        <div class="min-w-0">
                            {{-- El texto TAL CUAL se publica, con sus saltos de línea y sus asteriscos si
                                 los lleva: pintarlo en negrita aquí sería enseñar algo distinto de lo que
                                 ve el seguidor. --}}
                            <p class="bmos-post-texto"
                               :class="abierta !== '{{ $post['id'] }}' && 'is-corto'"
                            >{{ $post['texto'] !== '' ? $post['texto'] : 'Sin texto' }}</p>

                            @if (mb_strlen($post['texto']) > 160)
                                <button type="button"
                                        @click="abierta = abierta === '{{ $post['id'] }}' ? null : '{{ $post['id'] }}'"
                                        class="mt-1 text-xs font-semibold text-indigo-600 hover:underline"
                                        x-text="abierta === '{{ $post['id'] }}' ? 'Ver menos' : 'Ver todo'"></button>
                            @endif

                            <div class="bmos-post-meta mt-2">
                                @foreach ($post['destinos'] as $destino)
                                    @php $rotulo = ($destino['cuenta'] ?? null) ?: $destino['red']; @endphp

                                    {{-- Enlace solo si ya salió. Un enlace a algo que aún no existe
                                         lleva a un 404, y eso se lee como que la publicación falló. --}}
                                    @if (filled($destino['url']))
                                        <a href="{{ $destino['url'] }}" target="_blank" rel="noopener"
                                           class="bmos-destino" style="--tono: {{ $destino['color'] }}"
                                           title="Ver en {{ $destino['red'] }}">
                                            <span class="bmos-destino-punto"></span>
                                            <span class="truncate">{{ $rotulo }}</span>
                                            <span class="bmos-destino-estado" data-estado="{{ $destino['estado'] }}">{{ $destino['etiqueta'] }}</span>
                                        </a>
                                    @else
                                        <span class="bmos-destino" style="--tono: {{ $destino['color'] }}">
                                            <span class="bmos-destino-punto"></span>
                                            <span class="truncate">{{ $rotulo }}</span>
                                            <span class="bmos-destino-estado" data-estado="{{ $destino['estado'] }}">{{ $destino['etiqueta'] }}</span>
                                        </span>
                                    @endif
                                @endforeach

                                @if ($post['publicado'] !== null)
                                    <span title="{{ $post['publicado']->format('d/m/Y H:i') }}">
                                        {{ $post['publicado']->diffForHumans() }}
                                    </span>
                                @elseif ($post['sale_el'] !== null)
                                    {{-- La hora EXACTA a la que sale, más lo que falta.
                                         Las dos cosas, y no solo «en 15 horas»: quien viene a decidir si le da
                                         tiempo a pararla necesita saber si sale antes o después de que él cierre,
                                         y eso con un relativo hay que calcularlo de cabeza. La hora ya viene
                                         convertida a la zona del negocio; en UTC leería las diez de la noche una
                                         que sale a las seis de la tarde. --}}
                                    <span class="font-medium text-indigo-600">
                                        Sale el {{ $post['sale_el']->translatedFormat('j M') }} a las {{ $post['sale_el']->format('H:i') }}
                                        <span class="font-normal text-slate-400">({{ $post['sale_el']->diffForHumans() }})</span>
                                    </span>
                                @elseif ($post['creado'] !== null)
                                    <span title="{{ $post['creado']->format('d/m/Y H:i') }}">
                                        Creada {{ $post['creado']->diffForHumans() }}
                                    </span>
                                @endif
                            </div>

                            @foreach ($post['destinos'] as $destino)
                                @if (filled($destino['motivo']))
                                    {{-- El motivo del fallo. «Falló» a secas no dice qué hacer después. --}}
                                    <p class="mt-1.5 rounded-lg border border-rose-200 bg-rose-50 px-2.5 py-1.5 text-xs text-rose-700">
                                        {{ $destino['red'] }}: {{ $destino['motivo'] }}
                                    </p>
                                @elseif ($destino['intentos'] > 1)
                                    {{-- Que hiciera falta más de un intento no es un detalle técnico: avisa de
                                         que esa cuenta da guerra, antes del día en que el último intento
                                         tampoco funcione. --}}
                                    <p class="mt-1.5 text-xs text-amber-600">
                                        {{ $destino['red'] }} necesitó {{ $destino['intentos'] }} intentos.
                                    </p>
                                @endif
                            @endforeach
                        </div>

                        <div class="bmos-post-lado flex shrink-0 items-start gap-2">
                            <span class="bmos-badge {{ $post['tono'] }}">{{ $post['etiqueta'] }}</span>

                            @can('social.publish')
                                @if ($post['se_puede_cancelar'])
                                    <x-panel.confirm-action
                                        :action="route('panel.social.posts.cancel', $post['id'])"
                                        title="¿Cancelar esta publicación?"
                                        message="No va a salir. El texto y las fotos se pierden: si la quieres, hay que volver a escribirla."
                                        confirm="Cancelar publicación"
                                        tooltip="Cancelar"
                                        class="rounded-lg p-1.5 text-slate-400 hover:bg-rose-50 hover:text-rose-600">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" style="width:1.05rem;height:1.05rem"><path stroke-linecap="round" stroke-linejoin="round" d="M9.75 9.75l4.5 4.5m0-4.5-4.5 4.5M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/></svg>
                                    </x-panel.confirm-action>
                                @endif
                            @endcan
                        </div>
                    </div>
                @empty
                    <p class="bmos-empty">Todavía no has publicado nada desde aquí.</p>
                @endforelse
            </div>

            @can('social.connect')
                <details class="mt-5 text-sm text-slate-500">
                    <summary class="cursor-pointer">Cambiar o quitar la clave de Zernio</summary>
                    <form method="POST" action="{{ route('panel.social.key') }}" class="bmos-card bmos-card-pad mt-3">
                        @csrf
                        @method('PUT')
                        <input type="password" name="api_key" class="bmos-input font-mono"
                               placeholder="sk_... (vacío para desconectar)" autocomplete="off">
                        <p class="mt-1 text-xs text-slate-400">
                            Si la dejas vacía, el sistema deja de publicar en tus redes. Las cuentas
                            siguen conectadas en Zernio.
                        </p>
                        <button type="submit" class="bmos-btn bmos-btn-ghost mt-3">Guardar</button>
                    </form>
                </details>
            @endcan
        @endif
    </div>

    @if ($configurado)
        <script>
            function redactor() {
                return {
                    texto: @js(old('content', '')),
                    programar: false,
                    subiendo: false,
                    preparando: false,
                    mediaUrl: '',
                    mediaTipo: 'image',
                    errorMedia: '',
                    nombreArchivo: '',
                    arrastrando: false,
                    progreso: 0,

                    /*
                     * Cuánto texto admite cada red.
                     *
                     * Escrito aquí porque es un límite de la red, no del sistema: nada del servidor
                     * lo comprueba ni podría. Son los topes públicos de cada plataforma.
                     */
                    topes: {
                        instagram: 2200, threads: 500, twitter: 280,
                        tiktok: 2200, facebook: 63206, linkedin: 3000,
                        youtube: 5000, pinterest: 500,
                    },

                    /* id de cuenta → nombre, para que la vista previa diga en qué cuenta va a salir
                       y no un «Tu cuenta» genérico. */
                    cuentas: @js(collect($cuentas)->mapWithKeys(fn (array $c): array => [(string) $c['id'] => (string) $c['name']])->all()),

                    /* Las redes elegidas, para poder avisar antes de pulsar. */
                    destinos: [],

                    /* Las que no publican texto suelto. La lista la manda el servidor desde la
                       enumeración, para que no haya dos versiones de la regla. */
                    exigenFoto: @js($redesQueExigenFoto),

                    /* Las redes elegidas, ya sin el id de cuenta. */
                    get redesElegidas() {
                        return [...new Set(this.destinos.map((d) => d.split('|')[0]))];
                    },

                    /*
                     * El tope de la red MÁS CORTA de las elegidas.
                     *
                     * Manda la más estricta porque el texto es el mismo para todas: si se publica a
                     * la vez en Instagram y en X, lo que no cabe en X no cabe, y avisar del tope de
                     * Instagram sería avisar del límite equivocado.
                     */
                    get topeActual() {
                        const topes = this.redesElegidas.map((r) => this.topes[r]).filter(Boolean);

                        return topes.length ? Math.min(...topes) : 5000;
                    },

                    /** Cuál es esa red, para poder nombrarla en vez de decir «el tope». */
                    get redDelTope() {
                        const conTope = this.redesElegidas
                            .filter((r) => this.topes[r])
                            .sort((a, b) => this.topes[a] - this.topes[b]);

                        if (!conTope.length) return '';

                        return conTope[0].charAt(0).toUpperCase() + conTope[0].slice(1);
                    },

                    get pasaDelTope() {
                        return this.texto.length > this.topeActual;
                    },

                    /** El nombre de la cuenta elegida, para la vista previa. */
                    get nombreDestino() {
                        if (this.destinos.length === 0) return '';
                        if (this.destinos.length > 1) return this.destinos.length + ' cuentas';

                        const id = this.destinos[0].split('|')[1];

                        return (this.cuentas[id] ?? '').toString();
                    },

                    /*
                     * ¿Lleva marcas de Markdown?
                     *
                     * Ninguna red las entiende: **negrita** sale con los asteriscos a la vista. Pasa
                     * sobre todo cuando el texto lo redacta una IA, y solo se descubre mirando la
                     * publicación ya hecha, cuando ya la vio gente.
                     */
                    get tieneMarcado() {
                        return /\*\*[^*]+\*\*|__[^_]+__/.test(this.texto);
                    },

                    quitarMarcado() {
                        this.texto = this.texto.replace(/\*\*([^*]+)\*\*/g, '$1').replace(/__([^_]+)__/g, '$1');
                    },

                    quitarMedia() {
                        this.mediaUrl = '';
                        this.nombreArchivo = '';
                        this.progreso = 0;
                        this.errorMedia = '';
                    },

                    /* Instagram rechaza el texto sin foto con un 400. Decirlo mientras redacta
                       evita escribir la oferta entera para que la rechacen al final. */
                    get faltaFoto() {
                        if (this.mediaUrl) return [];

                        return [...new Set(this.destinos
                            .map((d) => d.split('|')[0])
                            .filter((red) => this.exigenFoto[red])
                            .map((red) => this.exigenFoto[red]))];
                    },

                    /**
                     * La foto va del navegador a Zernio SIN pasar por nuestro servidor: se pide
                     * permiso, se sube al enlace que devuelve, y al formulario solo viaja la
                     * dirección pública resultante.
                     */
                    subir(evento) {
                        return this.subirArchivo(evento.target.files[0]);
                    },

                    async subirArchivo(archivo) {
                        if (! archivo) return;

                        this.subiendo = true;
                        this.errorMedia = '';
                        this.mediaUrl = '';
                        this.progreso = 0;
                        this.nombreArchivo = archivo.name;

                        try {
                            const permiso = await fetch('{{ route('panel.social.presign') }}', {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json',
                                    Accept: 'application/json',
                                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                                },
                                body: JSON.stringify({ file_name: archivo.name, content_type: archivo.type }),
                            });

                            const datos = await permiso.json();
                            if (! permiso.ok) throw new Error(datos.message ?? 'No se pudo preparar la subida.');

                            /*
                             * Con XHR y no con fetch, por una sola razón: fetch NO informa de cuánto
                             * lleva subido. Un vídeo de 40 MB por una conexión de barrio tarda
                             * minutos, y sin barra de progreso no hay forma de distinguir «va por la
                             * mitad» de «se colgó» —así que se cierra la pestaña y se pierde todo—.
                             */
                            await new Promise((listo, fallo) => {
                                const peticion = new XMLHttpRequest();
                                peticion.open('PUT', datos.uploadUrl);
                                peticion.setRequestHeader('Content-Type', archivo.type);

                                peticion.upload.onprogress = (e) => {
                                    if (e.lengthComputable) {
                                        this.progreso = Math.round((e.loaded / e.total) * 100);
                                    }
                                };

                                peticion.onload = () => (peticion.status >= 200 && peticion.status < 300)
                                    ? listo()
                                    : fallo(new Error('No se pudo subir el archivo.'));
                                peticion.onerror = () => fallo(new Error('Se cortó la conexión al subir el archivo.'));
                                peticion.send(archivo);
                            });

                            this.mediaUrl = datos.publicUrl;
                            this.mediaTipo = archivo.type.startsWith('video/') ? 'video' : 'image';
                        } catch (e) {
                            this.errorMedia = e.message;
                        } finally {
                            this.subiendo = false;
                        }
                    },
                };
            }
        </script>
    @endif
</x-layouts.admin>
