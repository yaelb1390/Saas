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

                {{-- Redactar --}}
                <div class="bmos-card mt-5 overflow-hidden" x-data="redactor()">
                    <div class="border-b border-slate-100 p-5">
                        <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Publicar</p>
                        <p class="mt-0.5 font-semibold text-slate-800">Nueva publicación</p>
                    </div>

                    <form method="POST" action="{{ route('panel.social.publish') }}" class="p-5"
                          @submit="preparando = true">
                        @csrf

                        <div class="grid grid-cols-1 gap-5 lg:grid-cols-[minmax(0,1fr)_18rem]">
                            <div class="space-y-4">
                                <div>
                                    <label class="bmos-field-label">¿Qué quieres contar?</label>
                                    <textarea name="content" rows="5" class="bmos-input" required
                                              maxlength="5000" x-model="texto"
                                              placeholder="Hoy tenemos batida de guineo a RD$150. Te la llevamos a la casa."></textarea>
                                    <p class="mt-1 text-xs text-slate-400"><span x-text="texto.length"></span> / 5000</p>
                                </div>

                                <div>
                                    <label class="bmos-field-label">¿Dónde se publica?</label>
                                    @if ($cuentas === [])
                                        <p class="text-sm text-amber-600">Conecta al menos una red antes de publicar.</p>
                                    @else
                                        <div class="flex flex-wrap gap-2">
                                            @foreach ($cuentas as $cuenta)
                                                @php $red = SocialPlatform::tryFrom((string) $cuenta['platform']); @endphp
                                                {{-- Las que hay que reconectar no se ofrecen: elegirlas daría
                                                     un «publicado» que no publica nada. --}}
                                                <label class="bmos-red {{ $cuenta['necesita_reconectar'] ? 'opacity-50' : 'cursor-pointer' }}"
                                                       style="--tono: {{ $red?->color() ?? '#94a3b8' }}">
                                                    {{-- Plataforma e id viajan juntos: Zernio necesita los dos y el
                                                         servidor los separa, para que el navegador no decida
                                                         qué cuenta pertenece a qué red. --}}
                                                    <input type="checkbox" name="accounts[]" class="rounded border-slate-300"
                                                           x-model="destinos"
                                                           @disabled($cuenta['necesita_reconectar'])
                                                           value="{{ $cuenta['platform'] }}|{{ $cuenta['id'] }}">
                                                    <span class="bmos-red-punto"></span>
                                                    <span class="truncate">{{ $cuenta['name'] }}</span>
                                                </label>
                                            @endforeach
                                        </div>
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
                                    <input type="file" accept="image/jpeg,image/png,image/webp,video/mp4"
                                           @change="subir($event)" class="bmos-input">
                                    <input type="hidden" name="media_url" x-model="mediaUrl">
                                    <input type="hidden" name="media_type" x-model="mediaTipo">
                                    <p x-show="faltaFoto.length > 0" x-cloak
                                       class="mt-1.5 rounded-lg border border-amber-200 bg-amber-50 px-2.5 py-1.5 text-xs text-amber-800">
                                        <b x-text="faltaFoto.join(' y ')"></b>
                                        <span x-text="faltaFoto.length === 1 ? 'no publica' : 'no publican'"></span>
                                        solo texto: añade una foto o un vídeo.
                                    </p>
                                    <p x-show="subiendo" x-cloak class="mt-1 text-xs text-slate-500">Subiendo...</p>
                                    <p x-show="mediaUrl" x-cloak class="mt-1 text-xs font-medium text-emerald-600">Imagen lista.</p>
                                    <p x-show="errorMedia" x-cloak x-text="errorMedia" class="mt-1 text-xs text-rose-600"></p>
                                </div>
                            </div>

                            {{-- Cuándo y el botón, apartados a un lado: son la decisión final, no parte
                                 de redactar. --}}
                            <div class="space-y-3 rounded-2xl bg-slate-50 p-4">
                                <p class="text-sm font-medium text-slate-700">¿Cuándo?</p>
                                <label class="flex items-center gap-2 text-sm text-slate-600">
                                    <input type="checkbox" x-model="programar" class="rounded border-slate-300">
                                    Programar para más tarde
                                </label>
                                <input x-show="programar" x-cloak type="datetime-local" name="scheduled_for"
                                       class="bmos-input" :required="programar">
                                <p x-show="programar" x-cloak class="text-xs text-slate-400">
                                    La hora es la tuya: si pones las 8, sale a las 8 de aquí.
                                </p>

                                {{-- Apagado mientras falte la foto que la red exige: dejar pulsar un
                                     botón cuyo resultado ya se sabe que es un rechazo no es una
                                     libertad, es una pérdida de tiempo con la oferta escrita. --}}
                                <button type="submit" :disabled="subiendo || preparando || faltaFoto.length > 0"
                                        class="bmos-btn bmos-btn-primary w-full justify-center disabled:cursor-not-allowed disabled:opacity-50">
                                    <span x-text="programar ? 'Programar' : 'Publicar ahora'"></span>
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            @endcan

            {{-- Lo publicado --}}
            <div class="bmos-card mt-5 overflow-hidden">
                <div class="border-b border-slate-100 p-5">
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Historial</p>
                    <p class="mt-0.5 font-semibold text-slate-800">Últimas publicaciones</p>
                </div>

                @forelse ($publicaciones as $post)
                    @php
                        $estado = (string) ($post['status'] ?? '');
                        $color = match ($estado) {
                            'published' => 'badge-green',
                            'scheduled' => 'badge-blue',
                            'publishing' => 'badge-amber',
                            'failed' => 'badge-red',
                            'partial' => 'badge-amber',
                            default => 'badge-gray',
                        };
                        $etiqueta = match ($estado) {
                            'published' => 'Publicado',
                            'scheduled' => 'Programado',
                            'publishing' => 'Publicando',
                            'failed' => 'Falló',
                            'partial' => 'Publicado a medias',
                            'draft' => 'Borrador',
                            default => $estado ?: '—',
                        };
                    @endphp
                    <div class="flex items-start justify-between gap-4 border-b border-slate-50 p-5 last:border-0">
                        <div class="min-w-0">
                            <p class="line-clamp-2 text-sm text-slate-700">{{ $post['content'] ?? '' }}</p>
                            @if (filled($post['platformPostUrl'] ?? null))
                                <a href="{{ $post['platformPostUrl'] }}" target="_blank" rel="noopener"
                                   class="mt-1 inline-block text-xs font-medium text-indigo-600 hover:underline">Verlo en la red →</a>
                            @endif
                        </div>
                        <span class="bmos-badge shrink-0 {{ $color }}">{{ $etiqueta }}</span>
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

                    /* Las redes elegidas, para poder avisar antes de pulsar. */
                    destinos: [],

                    /* Las que no publican texto suelto. La lista la manda el servidor desde la
                       enumeración, para que no haya dos versiones de la regla. */
                    exigenFoto: @js($redesQueExigenFoto),

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
                    async subir(evento) {
                        const archivo = evento.target.files[0];
                        if (! archivo) return;

                        this.subiendo = true;
                        this.errorMedia = '';
                        this.mediaUrl = '';

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

                            const subida = await fetch(datos.uploadUrl, {
                                method: 'PUT',
                                headers: { 'Content-Type': archivo.type },
                                body: archivo,
                            });

                            if (! subida.ok) throw new Error('No se pudo subir el archivo.');

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
