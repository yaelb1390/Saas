@use('App\Modules\Social\Enums\SocialPlatform')
@use('App\Modules\Social\Enums\KeywordMatch')

{{--
    Los campos de una respuesta automática, compartidos por el alta y la edición.

    Van en un parcial y no duplicados porque son las reglas que la API impone de verdad —el tope del
    mensaje, el botón que exige enlace, las palabras que exige el modo privado— y dos copias acabarían
    diciendo cosas distintas.

    Se presenta como TRES PASOS y no como una pila de campos: «cuándo salta», «qué contesta» y «a
    quién». Un formulario largo sin costuras se lee como un trámite; en pasos se lee como un camino, y
    aquí cada paso responde a una pregunta distinta del dueño.

    Y lleva VISTA PREVIA del mensaje privado. No es adorno: esto redacta lo que va a recibir un cliente
    de verdad, y hasta ahora se escribía a ciegas dentro de un cuadro de texto que no se parece en nada
    a un mensaje de Instagram.

    `$a` es la automatización que se edita, o null al crear una nueva.
--}}
<div x-data="{
        conBoton: {{ filled($a['buttons'][0]['title'] ?? null) ? 'true' : 'false' }},
        dm: @js($a['dmMessage'] ?? old('dm_message', '')),
        publica: @js($a['commentReply'] ?? old('comment_reply', '')),
        etiqueta: @js($a['buttons'][0]['title'] ?? old('button_title', '')),
        cuenta: @js($a['accountId'] ?? old('account_id', $cuentas[0]['id'] ?? '')),

        /* Versiones alternativas. Zernio admite 5 además de la principal —o sea 6 textos— y
           manda una al azar cada vez. El tope es suyo: una sexta le hace rechazar todo. */
        maxVariantes: 5,
        dmVariantes: @js(array_values($a['dmMessageVariations'] ?? old('dm_variations', []))),
        publicaVariantes: @js(array_values($a['commentReplyVariations'] ?? old('reply_variations', []))),

        get tope() { return this.conBoton ? 640 : 1000; },

        /* Cuántos textos distintos pueden salir. Es el número que responde a la pregunta de
           verdad: «¿esto se va a ver como un robot?». Las vacías no cuentan. */
        get cuantosDm() { return 1 + this.dmVariantes.filter((t) => t.trim()).length; },
        get cuantasPublicas() {
            return (this.publica.trim() ? 1 : 0) + this.publicaVariantes.filter((t) => t.trim()).length;
        },
        /* Las publicaciones son de una cuenta concreta: ofrecer las de otra dejaría elegir algo
           imposible, y la API lo rechazaría al guardar sin decir por qué. */
        get publicacionesDeLaCuenta() {
            return @js($publicaciones).filter((p) => p.accountId === this.cuenta);
        },
     }">

    {{-- ---------------------------------------------------------------- 1 --}}
    <div class="bmos-paso">
        <span class="bmos-paso-num">1</span>
        <p class="bmos-paso-titulo">¿Cuándo tiene que saltar?</p>

        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <div>
                <label class="bmos-field-label">Nombre <span class="text-rose-500">*</span></label>
                <input type="text" name="name" class="bmos-input" required maxlength="80"
                       value="{{ $a['name'] ?? old('name') }}" placeholder="Precio de las batidas">
                <p class="mt-1 text-xs text-slate-400">Solo para que tú la reconozcas.</p>
            </div>
            <div>
                <label class="bmos-field-label">Cuenta <span class="text-rose-500">*</span></label>
                <select name="account_id" class="bmos-input" required x-model="cuenta">
                    @foreach ($cuentas as $cuenta)
                        <option value="{{ $cuenta['id'] }}">
                            {{ $cuenta['name'] }} · {{ SocialPlatform::tryFrom($cuenta['platform'])?->label() ?? $cuenta['platform'] }}
                        </option>
                    @endforeach
                </select>
            </div>
        </div>

        {{-- En qué publicación.

             «En todas» es lo que quiere la mayoría y por eso va primero y es lo de por omisión: la
             automatización sigue funcionando en las fotos que se suban mañana. Elegir una concreta
             sirve para una promoción puntual —«comenta SORTEO en esta foto»— y ahí sí importa que no
             conteste en las demás. --}}
        <div class="mt-3">
            <label class="bmos-field-label">¿En qué publicación?</label>
            <select name="post" class="bmos-input">
                <option value="">En todas mis publicaciones (también las futuras)</option>
                <template x-for="p in publicacionesDeLaCuenta" :key="p.platformPostId">
                    <option :value="p.postId + '|' + p.platformPostId"
                            :selected="p.platformPostId === @js($a['postId'] ?? '')"
                            x-text="'Solo en: ' + p.title"></option>
                </template>
            </select>

            <div class="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-1">
                <p class="text-xs text-slate-400" x-show="publicacionesDeLaCuenta.length === 0" x-cloak>
                    No tenemos ninguna publicación de esta cuenta todavía.
                </p>
                <p class="text-xs text-slate-400" x-show="publicacionesDeLaCuenta.length > 0" x-cloak>
                    <span x-text="publicacionesDeLaCuenta.length"></span> publicaciones disponibles.
                </p>
            </div>
        </div>

        <div class="mt-3">
            <label class="bmos-field-label">Palabras que la disparan <span class="text-rose-500">*</span></label>
            <input type="text" name="keywords" class="bmos-input" required maxlength="500"
                   value="{{ implode(', ', $a['keywords'] ?? []) ?: old('keywords') }}"
                   placeholder="precio, cuánto, info">
            <p class="mt-1 text-xs text-slate-400">Separadas por comas. Se busca en lo que escribe el seguidor.</p>
        </div>

        <div class="mt-3">
            <label class="bmos-field-label">¿Cómo se busca la palabra?</label>
            <select name="match_mode" class="bmos-input">
                @foreach ($coincidencias as $modo)
                    <option value="{{ $modo->value }}" @selected(($a['matchMode'] ?? null) === $modo->value || ($a === null && $modo === KeywordMatch::POR_OMISION))>
                        {{ $modo->label() }} — {{ $modo->hint() }}
                    </option>
                @endforeach
            </select>
        </div>
    </div>

    {{-- ---------------------------------------------------------------- 2 --}}
    <div class="bmos-paso">
        <span class="bmos-paso-num">2</span>
        <p class="bmos-paso-titulo">¿Qué contesta?</p>

        <div class="grid grid-cols-1 gap-4 lg:grid-cols-[minmax(0,1fr)_17rem]">
            <div class="space-y-3">
                <div>
                    <label class="bmos-field-label">Respuesta pública al comentario</label>
                    <input type="text" name="comment_reply" class="bmos-input" maxlength="500" x-model="publica"
                           value="{{ $a['commentReply'] ?? old('comment_reply') }}"
                           placeholder="¡Te acabo de escribir por privado! 💬">
                    <p class="mt-1 text-xs text-slate-400">
                        Opcional, pero conviene: la ve todo el que pase por el comentario, no solo quien escribió.
                    </p>

                    {{-- Versiones alternativas de la respuesta pública.
                         Es la que MÁS se nota: queda escrita bajo cada comentario, una debajo de
                         otra, y diez respuestas calcadas se ven a simple vista antes de que
                         Instagram diga nada. --}}
                    <template x-for="(v, i) in publicaVariantes" :key="i">
                        <div class="mt-2 flex items-center gap-2">
                            <span class="text-xs font-semibold text-slate-400">o</span>
                            <input type="text" :name="'reply_variations[' + i + ']'" class="bmos-input"
                                   maxlength="500" x-model="publicaVariantes[i]"
                                   placeholder="Ya te escribí al privado 😉">
                            <button type="button" class="bmos-btn bmos-btn-ghost px-2 text-rose-500"
                                    @click="publicaVariantes.splice(i, 1)" title="Quitar esta versión">✕</button>
                        </div>
                    </template>

                    <button type="button" class="bmos-btn bmos-btn-ghost mt-2 text-xs"
                            x-show="publicaVariantes.length < maxVariantes"
                            @click="publicaVariantes.push('')">
                        + Otra respuesta pública
                    </button>
                </div>

                <div>
                    <label class="bmos-field-label">Mensaje privado <span class="text-rose-500">*</span></label>
                    <textarea name="dm_message" rows="4" class="bmos-input" required x-model="dm"
                              :maxlength="tope"
                              placeholder="¡Hola! La batida de guineo está a RD$150 y te la llevamos a la casa.">{{ $a['dmMessage'] ?? old('dm_message') }}</textarea>
                    {{-- El tope lo impone Zernio y BAJA a 640 cuando hay botón: si el contador no lo
                         reflejara, el cliente escribiría un mensaje que el servidor rechaza al
                         guardar, sin decirle que la culpa fue del botón. --}}
                    <p class="mt-1 text-xs" :class="dm.length > tope - 60 ? 'text-amber-600 font-medium' : 'text-slate-400'">
                        <span x-text="dm.length"></span> / <span x-text="tope"></span>
                        <span x-show="conBoton" x-cloak>· el botón reduce el máximo</span>
                    </p>

                    {{-- Versiones alternativas del privado. --}}
                    <template x-for="(v, i) in dmVariantes" :key="i">
                        <div class="mt-2 flex items-start gap-2">
                            <span class="mt-2.5 text-xs font-semibold text-slate-400">o</span>
                            <textarea :name="'dm_variations[' + i + ']'" rows="2" class="bmos-input"
                                      :maxlength="tope" x-model="dmVariantes[i]"
                                      placeholder="¡Hola! Te cuento: la batida de guineo va a RD$150 y te la mandamos."></textarea>
                            <button type="button" class="bmos-btn bmos-btn-ghost mt-1 px-2 text-rose-500"
                                    @click="dmVariantes.splice(i, 1)" title="Quitar esta versión">✕</button>
                        </div>
                    </template>

                    <button type="button" class="bmos-btn bmos-btn-ghost mt-2 text-xs"
                            x-show="dmVariantes.length < maxVariantes"
                            @click="dmVariantes.push('')">
                        + Otro mensaje privado
                    </button>
                    <p class="mt-1 text-xs text-slate-400" x-show="dmVariantes.length >= maxVariantes" x-cloak>
                        Cinco alternativas es el máximo que admite el servicio.
                    </p>
                </div>

                {{-- Por qué existe todo lo de arriba.
                     Un texto idéntico repetido cientos de veces es EXACTAMENTE lo que Instagram
                     busca para limitar una cuenta por spam. Decirlo aquí, junto a los campos,
                     evita que alguien deje una sola versión pensando que da igual. --}}
                <div class="rounded-xl border p-3 text-sm"
                     :class="cuantosDm > 1 ? 'border-emerald-200 bg-emerald-50/60' : 'border-amber-200 bg-amber-50'">
                    <p class="font-medium" :class="cuantosDm > 1 ? 'text-emerald-800' : 'text-amber-900'">
                        <template x-if="cuantosDm > 1">
                            <span>
                                Rotan <b x-text="cuantosDm"></b> mensajes privados<span x-show="cuantasPublicas > 1">
                                y <b x-text="cuantasPublicas"></b> respuestas públicas</span>.
                            </span>
                        </template>
                        <template x-if="cuantosDm === 1">
                            <span>Siempre saldrá el mismo texto.</span>
                        </template>
                    </p>
                    <p class="mt-1 text-xs" :class="cuantosDm > 1 ? 'text-emerald-700' : 'text-amber-800'">
                        <span x-show="cuantosDm === 1">
                            Instagram limita las cuentas que mandan el mismo mensaje una y otra vez.
                            Añade dos o tres versiones y se elige una al azar cada vez.
                        </span>
                        <span x-show="cuantosDm > 1">
                            Se elige una al azar en cada comentario, y el privado y la respuesta
                            pública se sortean por separado.
                        </span>
                    </p>
                </div>

                {{-- El botón es lo que convierte el comentario en venta. Sin él, el cliente recibe un
                     texto y ahí termina el camino. --}}
                <div class="rounded-xl border border-slate-200 p-3">
                    <label class="flex cursor-pointer items-center gap-2 text-sm font-medium text-slate-700">
                        <input type="checkbox" x-model="conBoton" class="rounded border-slate-300">
                        Añadir un botón al mensaje
                    </label>
                    <div x-show="conBoton" x-cloak class="mt-2 grid grid-cols-1 gap-2 sm:grid-cols-[10rem_minmax(0,1fr)]">
                        <input type="text" name="button_title" class="bmos-input" maxlength="20" x-model="etiqueta"
                               value="{{ $a['buttons'][0]['title'] ?? old('button_title') }}" placeholder="Pedir ahora">
                        <input type="url" name="button_url" class="bmos-input"
                               value="{{ $a['buttons'][0]['url'] ?? old('button_url') }}" placeholder="https://wa.me/18095551234">
                    </div>
                    <p x-show="conBoton" x-cloak class="mt-1 text-xs text-slate-400">
                        Texto corto (20 caracteres) y a dónde lleva. Lo típico: tu WhatsApp o tu menú.
                    </p>
                </div>
            </div>

            {{-- Vista previa: lo que va a recibir el seguidor, tal cual. --}}
            <div class="bmos-previa">
                <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-400">Así lo verá</p>

                <div x-show="publica" x-cloak class="mb-3">
                    <p class="mb-1 text-[11px] text-slate-400">En el comentario, a la vista de todos:</p>
                    <p class="rounded-lg bg-white/70 px-2.5 py-1.5 text-xs text-slate-600" x-text="publica"></p>
                </div>

                <p class="mb-1 text-[11px] text-slate-400">
                    Por privado<span x-show="cuantosDm > 1" x-cloak>, una de estas <span x-text="cuantosDm"></span></span>:
                </p>
                <div class="bmos-previa-burbuja" x-text="dm || 'Escribe el mensaje y aparecerá aquí.'"
                     :class="dm ? '' : 'text-slate-400 italic'"></div>

                {{-- Las alternativas también se ven: el sentido de escribirlas es compararlas
                     entre sí, y en cuadros de texto sueltos no se parecen a lo que llega. --}}
                <template x-for="(v, i) in dmVariantes" :key="i">
                    <div class="bmos-previa-burbuja mt-1.5" x-show="v.trim()" x-cloak x-text="v"></div>
                </template>
                <div x-show="conBoton && etiqueta" x-cloak class="bmos-previa-boton" x-text="etiqueta"></div>
            </div>
        </div>
    </div>

    {{-- ---------------------------------------------------------------- 3 --}}
    <div class="bmos-paso">
        <span class="bmos-paso-num">3</span>
        <p class="bmos-paso-titulo">¿A quién y desde cuándo?</p>

        {{-- Cuánto espera antes de contestar.

             Contestar en el mismo segundo, siempre, se reconoce tan fácil como el texto repetido:
             ninguna persona responde en cuatro décimas a las tres de la madrugada.

             Se ofrecen unas pocas opciones y no un campo de segundos: nadie va a calcular que 900
             son quince minutos, y un número libre solo invita a poner cosas raras.

             La respuesta pública sale con el privado y no lleva su propio control: Zernio nunca la
             publica antes de mandarlo, así que la retrasa sola hasta aquí. --}}
        @php $espera = $a['dmDelaySeconds'] ?? (int) old('dm_delay', 0); @endphp
        <div class="mb-2 rounded-xl border border-slate-200 p-3">
            <label class="bmos-field-label">¿Cuánto espera antes de contestar?</label>
            <select name="dm_delay" class="bmos-input">
                <option value="0" @selected($espera === 0)>Al instante</option>
                <option value="45" @selected($espera === 45)>Casi un minuto</option>
                <option value="180" @selected($espera === 180)>Unos 3 minutos</option>
                <option value="600" @selected($espera === 600)>Unos 10 minutos</option>
                <option value="1800" @selected($espera === 1800)>Media hora</option>
            </select>
            <p class="mt-1.5 text-xs text-slate-500">
                Esperar un poco parece más humano y ayuda a que no la marquen como spam.
                <b>El comentario se registra al momento</b>: solo se retrasa el envío, no se pierde nadie.
            </p>
            <p class="mt-1 text-xs text-slate-400">
                La espera es siempre la misma, no cambia sola. Lo que de verdad rompe el patrón
                son las varias versiones del mensaje de arriba.
            </p>
        </div>

        <div class="space-y-2">
            <label class="flex cursor-pointer items-start gap-2.5 rounded-xl border border-slate-200 p-3 text-sm text-slate-600 transition hover:border-slate-300">
                <input type="checkbox" name="also_in_dms" value="1" class="mt-0.5 rounded border-slate-300"
                       @checked($a['alsoMatchInDms'] ?? old('also_in_dms'))>
                <span>
                    Responder también a quien escriba esa palabra <b>por privado</b>
                    <span class="block text-xs text-slate-400">Mucha gente manda «precio» por mensaje en vez de comentar.</span>
                </span>
            </label>

            <label class="flex cursor-pointer items-start gap-2.5 rounded-xl border border-slate-200 p-3 text-sm text-slate-600 transition hover:border-slate-300">
                <input type="checkbox" name="follow_gate" value="1" class="mt-0.5 rounded border-slate-300"
                       @checked($a['followGate'] ?? old('follow_gate'))>
                <span>
                    Enviarlo <b>solo a quien te siga</b>
                    <span class="block text-xs text-slate-400">A quien no te siga se le pide que te siga primero.</span>
                </span>
            </label>

            <label class="flex cursor-pointer items-start gap-2.5 rounded-xl border border-emerald-200 bg-emerald-50/50 p-3 text-sm text-slate-700 transition hover:border-emerald-300">
                <input type="checkbox" name="is_active" value="1" class="mt-0.5 rounded border-slate-300"
                       @checked($a['isActive'] ?? true)>
                <span>
                    <b>Encendida</b>
                    <span class="block text-xs text-slate-500">Apagada se guarda pero no contesta a nadie.</span>
                </span>
            </label>
        </div>
    </div>
</div>
