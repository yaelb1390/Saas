{{--
    Alta y edición de una regla, en un solo parcial: son los mismos campos y las mismas reglas de
    Zernio (tope de plantillas, botón sin dirección, etc.), y dos copias acabarían diciendo cosas
    distintas — mismo criterio que `partials/social-automation-fields.blade.php`.

    `$rule` es la regla que se edita, o null al crear una nueva.
--}}
<x-layouts.admin :title="$rule ? 'Editar regla' : 'Nueva regla'" :heading="$rule ? 'Editar regla' : 'Nueva regla'"
                 subheading="Palabra clave → producto → precio → plantilla">
    <div class="mx-auto max-w-3xl">
        <a href="{{ route('panel.social-commerce.index') }}" class="mb-4 inline-block text-sm text-indigo-600 hover:underline">
            ← Volver a Social Commerce
        </a>

        @if ($aviso)
            <div class="mb-5 rounded-xl border border-amber-200 bg-amber-50 p-4">
                <p class="text-sm font-medium text-amber-900">{{ $aviso }}</p>
            </div>
        @endif

        <form method="POST" action="{{ $rule ? route('panel.social-commerce.update', $rule) : route('panel.social-commerce.store') }}"
              x-data="{
                  cuenta: @js(old('zernio_account_id', $rule?->zernio_account_id ?? ($cuentas[0]['id'] ?? ''))),
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
            <div class="bmos-paso">
                <span class="bmos-paso-num">1</span>
                <p class="bmos-paso-titulo">¿Cuándo tiene que saltar?</p>

                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <div>
                        <label class="bmos-field-label">Nombre <span class="text-rose-500">*</span></label>
                        <input type="text" name="name" class="bmos-input" required maxlength="80"
                               value="{{ old('name', $rule?->name) }}" placeholder="Precio de la camisa Nike">
                    </div>
                    <div>
                        <label class="bmos-field-label">Cuenta <span class="text-rose-500">*</span></label>
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
                </div>

                <div class="mt-3">
                    <label class="bmos-field-label">Producto <span class="text-rose-500">*</span></label>
                    @if ($rule === null)
                        <select name="product_id" class="bmos-input" required>
                            <option value="">Elige un producto</option>
                            @foreach ($productos as $p)
                                <option value="{{ $p->id }}" @selected((string) old('product_id') === (string) $p->id)>
                                    {{ $p->name }} ({{ $p->sku }}) — {{ number_format((float) $p->price, 2) }}
                                </option>
                            @endforeach
                        </select>
                        <p class="mt-1 text-xs text-slate-400">El precio de la plantilla sale de este producto, no de lo que escribas: si lo cambias en Inventario, la respuesta se actualiza sola la próxima vez que se sincronice.</p>
                    @else
                        <input type="hidden" name="product_id" value="{{ $rule->product_id }}">
                        <p class="rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-700">
                            {{ $rule->product?->name ?? 'Producto borrado' }}
                        </p>
                        <p class="mt-1 text-xs text-slate-400">Para cambiar el producto, crea una regla nueva y borra esta.</p>
                    @endif
                </div>

                @if ($rule === null)
                    <div class="mt-3">
                        <label class="bmos-field-label">¿Cuándo salta?</label>
                        <select name="trigger" class="bmos-input" x-model="disparador">
                            <option value="comment">Cuando comenten una publicación</option>
                            <option value="story_reply">Cuando respondan una historia</option>
                        </select>
                    </div>

                    <div class="mt-3" x-show="! enHistoria" x-cloak>
                        <label class="bmos-field-label">¿En qué publicación?</label>
                        <select name="post" class="bmos-input">
                            <option value="">En todas mis publicaciones (también las futuras)</option>
                            <template x-for="p in publicacionesDeLaCuenta" :key="p.platformPostId">
                                <option :value="p.postId + '|' + p.platformPostId" x-text="'Solo en: ' + p.title"></option>
                            </template>
                        </select>
                    </div>
                @else
                    <div class="mt-3 rounded-xl border border-slate-200 bg-slate-50 p-3">
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

                <div class="mt-3">
                    <label class="bmos-field-label">Palabras que la disparan <span class="text-rose-500">*</span></label>
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

                <div class="mt-3">
                    <label class="bmos-field-label">¿Cómo se busca la palabra?</label>
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

            {{-- ---------------------------------------------------------------- 2 --}}
            <div class="bmos-paso">
                <span class="bmos-paso-num">2</span>
                <p class="bmos-paso-titulo">¿Qué contesta?</p>

                <p class="mb-3 rounded-xl border border-indigo-100 bg-indigo-50/60 p-3 text-xs text-indigo-800">
                    Variables disponibles:
                    @foreach ($variables as $v)
                        <code class="rounded bg-white/70 px-1">{{'{'.$v.'}'}}</code>
                    @endforeach
                    — se rellenan con el producto elegido arriba al guardar.
                </p>

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
            <div class="bmos-paso">
                <span class="bmos-paso-num">3</span>
                <p class="bmos-paso-titulo">¿A quién y desde cuándo?</p>

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
