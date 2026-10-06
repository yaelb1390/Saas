{{--
    Alta y edición de una regla, en un solo parcial: son los mismos campos y las mismas reglas de
    Zernio (tope de plantillas, botón sin dirección, etc.), y dos copias acabarían diciendo cosas
    distintas — mismo criterio que `partials/social-automation-fields.blade.php`.

    `$rule` es la regla que se edita, o null al crear una nueva.

    La barra lateral (pasos numerados + tarjeta de ayuda) es solo una guía visual: el formulario
    sigue siendo una sola página, no un asistente que se navega paso a paso. Los números 1/2/3 de
    ahí coinciden con los de las tarjetas de la derecha para que se lean como el mismo recorrido.
--}}
<x-layouts.admin :back="route('panel.social-commerce.index')" :back-label="'Social Commerce'"
                 :title="$rule ? 'Editar regla' : 'Nueva regla'" :heading="$rule ? 'Editar regla' : 'Nueva regla'"
                 subheading="Palabra clave → producto → precio → plantilla" :wide="true">
    @if ($aviso)
        <div class="mb-5 rounded-xl border border-amber-200 bg-amber-50 p-4">
            <p class="text-sm font-medium text-amber-900">{{ $aviso }}</p>
        </div>
    @endif

    <div class="grid grid-cols-1 gap-5 lg:grid-cols-[17rem_minmax(0,1fr)]">
        {{-- --------------------------------------------------------- Barra lateral (solo guía) --}}
        <aside class="space-y-4 lg:sticky lg:top-4 lg:self-start">
            <div class="bmos-card bmos-card-pad">
                <div class="flex items-center gap-2.5">
                    <span class="grid h-9 w-9 shrink-0 place-items-center rounded-xl bg-indigo-50 text-indigo-600">
                        <x-icono name="bag" class="h-5 w-5" />
                    </span>
                    <div>
                        <p class="font-semibold text-slate-800">Social Commerce</p>
                        <p class="text-xs text-slate-400">Automatiza tus ventas en redes sociales</p>
                    </div>
                </div>
            </div>

            <div class="bmos-card bmos-card-pad space-y-3">
                <div class="flex items-start gap-2.5">
                    <span class="bmos-paso-num shrink-0">1</span>
                    <div>
                        <p class="text-sm font-semibold text-slate-800">Información básica</p>
                        <p class="text-xs text-slate-400">Define los detalles de la regla.</p>
                    </div>
                </div>
                <div class="flex items-start gap-2.5">
                    <span class="bmos-paso-num shrink-0">2</span>
                    <div>
                        <p class="text-sm font-semibold text-slate-800">¿Qué contesta?</p>
                        <p class="text-xs text-slate-400">Configura cuándo se aplicará.</p>
                    </div>
                </div>
                <div class="flex items-start gap-2.5">
                    <span class="bmos-paso-num shrink-0">3</span>
                    <div>
                        <p class="text-sm font-semibold text-slate-800">Acciones</p>
                        <p class="text-xs text-slate-400">Elige qué se hará al cumplirse.</p>
                    </div>
                </div>
            </div>

            <div class="rounded-2xl border border-indigo-100 bg-indigo-50/60 p-4">
                <div class="flex items-center gap-2 text-indigo-700">
                    <x-icono name="spark" class="h-4 w-4" />
                    <p class="text-sm font-semibold">¿Cómo funciona?</p>
                </div>
                <p class="mt-1.5 text-xs leading-relaxed text-indigo-800">
                    Las reglas te permiten automatizar respuestas en tus publicaciones y comentarios
                    de Instagram: cuando alguien escribe la palabra clave, se le contesta solo.
                </p>
            </div>
        </aside>

        {{-- --------------------------------------------------------------------- Formulario --}}
        <form method="POST" action="{{ $rule ? route('panel.social-commerce.update', $rule) : route('panel.social-commerce.store') }}"
              x-data="{
                  cuenta: @js(old('zernio_account_id', $rule?->zernio_account_id ?? ($cuentas[0]['id'] ?? ''))),
                  origen: @js(old('origen_precio', $rule?->esManual() ? 'manual' : 'producto')),
                  disparador: @js(old('trigger', $rule?->trigger ?? 'comment')),
                  get enHistoria() { return this.disparador === 'story_reply'; },
                  modo: @js(old('match_mode', $rule?->match_mode ?? 'word')),
                  palabras: @js(old('keywords', $rule?->keywords ?? [])),
                  nuevaPalabra: '',
                  agregarPalabra() {
                      const p = this.nuevaPalabra.trim();
                      if (p && ! this.palabras.includes(p)) { this.palabras.push(p); }
                      this.nuevaPalabra = '';
                  },
                  dmPlantillas: @js(old('dm_templates', $rule?->dmTemplates?->pluck('body')->all() ?: [''])),
                  publicaPlantillas: @js(old('public_templates', $rule?->publicTemplates?->pluck('body')->all() ?: [])),
                  maxPlantillas: 6,
                  conBoton: @js(filled(old('button_title', $rule?->button_title))),
                  get publicacionesDeLaCuenta() { return @js($publicaciones).filter((p) => p.accountId === this.cuenta); },
              }">
            @csrf
            @if ($rule) @method('PUT') @endif

            {{-- ---------------------------------------------------------------- 1 --}}
            <div class="bmos-card bmos-card-pad">
                <div class="flex items-start gap-2.5">
                    <span class="bmos-paso-num shrink-0">1</span>
                    <div>
                        <p class="bmos-paso-titulo mb-0">Información básica</p>
                        <p class="-mt-2 text-xs text-slate-400">Configura el nombre, la cuenta y el precio de la regla.</p>
                    </div>
                </div>

                <div class="mt-3 grid grid-cols-1 gap-x-5 gap-y-3 lg:grid-cols-2">
                    <div class="space-y-3">
                        <div>
                            <label class="bmos-field-label">Nombre <span class="text-rose-500">*</span></label>
                            <input type="text" name="name" class="bmos-input" required maxlength="80"
                                   value="{{ old('name', $rule?->name) }}" placeholder="Precio de la camisa Nike">
                            <p class="mt-1 text-xs text-slate-400">Este será el nombre interno de tu regla.</p>
                        </div>

                        <div>
                            <label class="bmos-field-label inline-flex items-center gap-1.5">
                                <x-icono name="cash" class="h-3.5 w-3.5 text-slate-400" />
                                ¿De dónde sale el precio? <span class="text-rose-500">*</span>
                            </label>

                            @if ($rule === null)
                                <div class="mb-2 flex gap-2">
                                    <button type="button" class="bmos-btn"
                                            :class="origen === 'producto' ? 'bmos-btn-primary' : 'bmos-btn-ghost'"
                                            @click="origen = 'producto'">
                                        <x-icono name="cube" class="h-4 w-4" /> Producto del inventario
                                    </button>
                                    <button type="button" class="bmos-btn"
                                            :class="origen === 'manual' ? 'bmos-btn-primary' : 'bmos-btn-ghost'"
                                            @click="origen = 'manual'">
                                        <x-icono name="cash" class="h-4 w-4" /> Precio manual
                                    </button>
                                </div>
                                <input type="hidden" name="origen_precio" :value="origen">

                                <div x-show="origen === 'producto'">
                                    <select name="product_id" class="bmos-input" :required="origen === 'producto'">
                                        <option value="">Elige un producto</option>
                                        @foreach ($productos as $p)
                                            <option value="{{ $p->id }}" @selected((string) old('product_id') === (string) $p->id)>
                                                {{ $p->name }} ({{ $p->sku }}) — {{ number_format((float) $p->price, 2) }}
                                            </option>
                                        @endforeach
                                    </select>
                                    <p class="mt-1 text-xs text-slate-400">El precio de la plantilla sale de este producto, no de lo que escribas: si lo cambias en Inventario, la respuesta se actualiza sola la próxima vez que se sincronice.</p>
                                </div>

                                <div x-show="origen === 'manual'" class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                                    <input type="text" name="manual_name" class="bmos-input" maxlength="120"
                                           :required="origen === 'manual'"
                                           value="{{ old('manual_name') }}" placeholder="Tenis Jordan talla 42">
                                    <input type="number" name="manual_price" class="bmos-input" step="0.01" min="0.01"
                                           :required="origen === 'manual'"
                                           value="{{ old('manual_price') }}" placeholder="2500.00">
                                    <p class="col-span-full text-xs text-slate-400">Para algo que vendes por Instagram pero no está en Inventario. El precio se queda tal cual lo escribas — no se actualiza solo.</p>
                                </div>
                            @elseif ($rule->esManual())
                                <input type="hidden" name="manual_name" value="{{ $rule->manual_name }}">
                                <input type="hidden" name="manual_price" value="{{ $rule->manual_price }}">
                                <p class="rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-700">
                                    {{ $rule->manual_name }} — {{ number_format((float) $rule->manual_price, 2) }} (precio manual)
                                </p>
                                <p class="mt-1 text-xs text-slate-400">Para cambiar el nombre o el precio, crea una regla nueva y borra esta.</p>
                            @else
                                <input type="hidden" name="product_id" value="{{ $rule->product_id }}">
                                <p class="rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-700">
                                    {{ $rule->product?->name ?? 'Producto borrado' }}
                                </p>
                                <p class="mt-1 text-xs text-slate-400">Para cambiar el producto, crea una regla nueva y borra esta.</p>
                            @endif
                        </div>

                        <div>
                            <label class="bmos-field-label inline-flex items-center gap-1.5">
                                <x-icono name="tag" class="h-3.5 w-3.5 text-slate-400" />
                                Palabras que la disparan <span class="text-rose-500">*</span>
                            </label>
                            <div class="flex flex-wrap items-center gap-1.5 rounded-xl border border-slate-200 p-2">
                                <template x-for="(p, i) in palabras" :key="i">
                                    <span class="bmos-clave">
                                        <input type="hidden" name="keywords[]" :value="p">
                                        <span x-text="p"></span>
                                        <button type="button" class="ml-1 text-slate-400 hover:text-rose-500" @click="palabras.splice(i, 1)">✕</button>
                                    </span>
                                </template>
                                <input type="text" x-model="nuevaPalabra" @keydown.enter.prevent="agregarPalabra()" @blur="agregarPalabra()"
                                       class="min-w-[8rem] flex-1 border-0 p-1 text-sm focus:ring-0" placeholder="precio, cuánto, vale… y Enter">
                            </div>
                            <p class="mt-1 text-xs text-slate-400">Escribe una palabra y pulsa Enter. Se busca en lo que escribe el seguidor.</p>
                        </div>

                        <div>
                            <label class="bmos-field-label inline-flex items-center gap-1.5">
                                <x-icono name="target" class="h-3.5 w-3.5 text-slate-400" />
                                ¿Cómo se busca la palabra?
                            </label>
                            <select name="match_mode" class="bmos-input" x-model="modo">
                                @foreach ($coincidencias as $c)
                                    <option value="{{ $c->value }}" @selected(old('match_mode', $rule?->match_mode ?: 'word') === $c->value)>
                                        {{ $c->label() }} — {{ $c->hint() }}
                                    </option>
                                @endforeach
                            </select>

                            <label x-show="modo === 'word'" x-cloak
                                   class="mt-2 flex cursor-pointer items-start gap-2.5 rounded-xl border border-slate-200 p-3 text-sm text-slate-600">
                                <input type="checkbox" name="typo_tolerance" value="1" class="mt-0.5 rounded border-slate-300"
                                       @checked(old('typo_tolerance', $rule?->typo_tolerance))>
                                <span>Aceptarla <b>aunque la escriban mal</b> («infomacion», «cuato»…)</span>
                            </label>
                        </div>
                    </div>

                    <div class="space-y-3">
                        <div>
                            <label class="bmos-field-label inline-flex items-center gap-1.5">
                                <x-icono name="users" class="h-3.5 w-3.5 text-slate-400" />
                                Cuenta <span class="text-rose-500">*</span>
                            </label>
                            @if ($rule === null)
                                <select name="zernio_account_id" class="bmos-input" required x-model="cuenta">
                                    @foreach ($cuentas as $c)
                                        <option value="{{ $c['id'] }}">{{ $c['name'] }} · {{ ucfirst($c['platform']) }}</option>
                                    @endforeach
                                </select>
                            @else
                                <input type="hidden" name="zernio_account_id" value="{{ $rule->zernio_account_id }}">
                                <p class="rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-700">
                                    {{ collect($cuentas)->firstWhere('id', $rule->zernio_account_id)['name'] ?? 'La cuenta con la que se creó' }}
                                </p>
                                <p class="mt-1 text-xs text-slate-400">No se puede mover a otra cuenta.</p>
                            @endif
                        </div>

                        @if ($rule === null)
                            <div>
                                <label class="bmos-field-label inline-flex items-center gap-1.5">
                                    <x-icono name="reloj" class="h-3.5 w-3.5 text-slate-400" />
                                    ¿Cuándo salta?
                                </label>
                                <select name="trigger" class="bmos-input" x-model="disparador">
                                    <option value="comment">Cuando comenten una publicación</option>
                                    <option value="story_reply">Cuando respondan una historia</option>
                                </select>
                            </div>

                            <div x-show="! enHistoria" x-cloak>
                                <label class="bmos-field-label inline-flex items-center gap-1.5">
                                    <x-icono name="foto" class="h-3.5 w-3.5 text-slate-400" />
                                    ¿En qué publicación?
                                </label>
                                <select name="post" class="bmos-input">
                                    <option value="">En todas mis publicaciones (también las futuras)</option>
                                    <template x-for="p in publicacionesDeLaCuenta" :key="p.platformPostId">
                                        <option :value="p.postId + '|' + p.platformPostId" x-text="'Solo en: ' + p.title"></option>
                                    </template>
                                </select>
                            </div>
                        @else
                            <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
                                <p class="bmos-field-label mb-0.5">¿Cuándo salta?</p>
                                <p class="text-sm text-slate-700">
                                    {{ $rule->trigger === 'story_reply' ? 'Cuando respondan una historia' : 'Cuando comenten una publicación' }}
                                    @if ($rule->trigger === 'comment')
                                        — {{ $rule->zernio_post_id ? 'en una publicación concreta' : 'en cualquier publicación' }}
                                    @endif
                                </p>
                                <p class="mt-1 text-xs text-slate-400">Esto no se puede cambiar después de crearla.</p>
                            </div>
                        @endif
                    </div>
                </div>
            </div>

            {{-- ---------------------------------------------------------------- 2 --}}
            <div class="bmos-card bmos-card-pad mt-4">
                <div class="mb-3 flex items-start gap-2.5">
                    <span class="bmos-paso-num shrink-0">2</span>
                    <div>
                        <p class="bmos-paso-titulo mb-0 inline-flex items-center gap-1.5">
                            <x-icono name="chat" class="h-4 w-4 text-indigo-500" /> ¿Qué contesta?
                        </p>
                        <p class="-mt-1 text-xs text-slate-400">Define la respuesta que se enviará cuando se cumplan las condiciones.</p>
                    </div>
                </div>

                <div class="mb-4 rounded-xl border border-indigo-100 bg-indigo-50/60 p-3">
                    <p class="mb-2 text-xs font-semibold text-indigo-800">Variables disponibles</p>
                    <div class="flex flex-wrap gap-1.5">
                        @foreach ($variables as $v)
                            <span class="inline-flex items-center rounded-full border border-indigo-200 bg-white/70 px-2.5 py-1 font-mono text-xs text-indigo-700">
                                {{ '{'.$v.'}' }}
                            </span>
                        @endforeach
                    </div>
                    <p class="mt-2 text-xs text-indigo-800">Se reemplazan con la información real elegida arriba al guardar.</p>
                </div>

                <div class="space-y-2">
                    <label class="bmos-field-label">Respuestas públicas (bajo el comentario)</label>
                    <template x-for="(v, i) in publicaPlantillas" :key="'pub'+i">
                        <div class="flex items-start gap-2">
                            <textarea :name="'public_templates[' + i + ']'" rows="2" class="bmos-input" maxlength="500"
                                      x-model="publicaPlantillas[i]" placeholder="¡Hola! 👋 Te escribimos por privado."></textarea>
                            <button type="button" class="bmos-btn bmos-btn-ghost mt-1 px-2 text-rose-500"
                                    @click="publicaPlantillas.splice(i, 1)">✕</button>
                        </div>
                    </template>
                    <button type="button" class="bmos-btn bmos-btn-ghost text-xs" x-show="publicaPlantillas.length < maxPlantillas"
                            @click="publicaPlantillas.push('')">+ Otra respuesta pública</button>
                </div>

                <div class="mt-4 space-y-2">
                    <label class="bmos-field-label">Mensajes privados <span class="text-rose-500">*</span></label>
                    <template x-for="(v, i) in dmPlantillas" :key="'dm'+i">
                        <div class="flex items-start gap-2">
                            <textarea :name="'dm_templates[' + i + ']'" rows="3" class="bmos-input" required
                                      :maxlength="conBoton ? 640 : 1000" x-model="dmPlantillas[i]"
                                      placeholder="¡Hola! 👋 La {producto} cuesta {precio}. Si te interesa, escríbenos: {url_whatsapp}"></textarea>
                            <button type="button" class="bmos-btn bmos-btn-ghost mt-1 px-2 text-rose-500"
                                    x-show="dmPlantillas.length > 1" @click="dmPlantillas.splice(i, 1)">✕</button>
                        </div>
                    </template>
                    <button type="button" class="bmos-btn bmos-btn-ghost text-xs" x-show="dmPlantillas.length < maxPlantillas"
                            @click="dmPlantillas.push('')">+ Otro mensaje privado</button>
                    <p class="text-xs text-slate-400">
                        Con dos o más, Zernio elige una al azar cada vez: ayuda a que no parezca un robot repitiendo el mismo texto.
                    </p>
                </div>

                <div class="mt-4 rounded-xl border border-slate-200 p-3">
                    <label class="flex cursor-pointer items-center gap-2 text-sm font-medium text-slate-700">
                        <input type="checkbox" x-model="conBoton" class="rounded border-slate-300">
                        Añadir un botón al mensaje privado
                    </label>
                    <div x-show="conBoton" x-cloak class="mt-2 grid grid-cols-1 gap-2 sm:grid-cols-[10rem_minmax(0,1fr)]">
                        <input type="text" name="button_title" class="bmos-input" maxlength="20"
                               value="{{ old('button_title', $rule?->button_title) }}" placeholder="Escribir por WhatsApp">
                        <input type="url" name="button_url" class="bmos-input"
                               value="{{ old('button_url', $rule?->button_url) }}" placeholder="{{'{url_whatsapp}'}} o un enlace fijo">
                    </div>
                </div>
            </div>

            {{-- ---------------------------------------------------------------- 3 --}}
            <div class="bmos-card bmos-card-pad mt-4">
                <div class="mb-3 flex items-start gap-2.5">
                    <span class="bmos-paso-num shrink-0">3</span>
                    <div>
                        <p class="bmos-paso-titulo mb-0">Acciones</p>
                        <p class="-mt-1 text-xs text-slate-400">¿A quién y desde cuándo?</p>
                    </div>
                </div>

                @php $espera = old('dm_delay', $rule?->dm_delay_seconds ?? 0); @endphp
                <div class="mb-2 rounded-xl border border-slate-200 p-3">
                    <label class="bmos-field-label">¿Cuánto espera antes de contestar?</label>
                    <select name="dm_delay" class="bmos-input">
                        <option value="0" @selected((int) $espera === 0)>Al instante</option>
                        <option value="45" @selected((int) $espera === 45)>Casi un minuto</option>
                        <option value="180" @selected((int) $espera === 180)>Unos 3 minutos</option>
                        <option value="600" @selected((int) $espera === 600)>Unos 10 minutos</option>
                    </select>
                </div>

                <div class="space-y-2">
                    <label x-show="! enHistoria" x-cloak
                           class="flex cursor-pointer items-start gap-2.5 rounded-xl border border-slate-200 p-3 text-sm text-slate-600">
                        <input type="checkbox" name="also_in_dms" value="1" class="mt-0.5 rounded border-slate-300"
                               @checked(old('also_in_dms', $rule?->also_in_dms))>
                        <span>Responder también a quien escriba esa palabra <b>por privado</b></span>
                    </label>

                    <label class="flex cursor-pointer items-start gap-2.5 rounded-xl border border-slate-200 p-3 text-sm text-slate-600">
                        <input type="checkbox" name="follow_gate" value="1" class="mt-0.5 rounded border-slate-300"
                               @checked(old('follow_gate', $rule?->follow_gate))>
                        <span>Enviarlo <b>solo a quien te siga</b></span>
                    </label>
                </div>
            </div>

            <div class="mt-5 flex justify-end gap-2">
                <a href="{{ route('panel.social-commerce.index') }}" class="bmos-btn bmos-btn-ghost">Cancelar</a>
                <button type="submit" class="bmos-btn bmos-btn-primary">{{ $rule ? 'Guardar' : 'Crear regla' }}</button>
            </div>
        </form>
    </div>
</x-layouts.admin>
