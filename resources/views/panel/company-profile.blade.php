<x-layouts.admin title="Mi empresa" heading="Mi empresa"
                 subheading="Lo que sale impreso en tus recibos y facturas">
    <div class="mx-auto grid max-w-5xl grid-cols-1 gap-5 lg:grid-cols-3">

        <div class="lg:col-span-2">
            <form method="POST" action="{{ route('panel.company-profile.update') }}" enctype="multipart/form-data"
                  x-data="logoEmpresa()" @submit="retenerEnvio($event)">
                @csrf @method('PUT')

                <div class="bmos-card bmos-card-pad">
                    <p class="font-semibold text-slate-800">Datos</p>
                    <p class="mt-1 text-xs text-slate-500">
                        Lo que dejes vacío simplemente no se imprime.
                    </p>

                    <div class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <div>
                            <label class="bmos-field-label">Nombre comercial <span class="text-rose-500">*</span></label>
                            <input type="text" name="name" value="{{ old('name', $company->name) }}" class="bmos-input" required>
                            <p class="mt-1 text-xs text-slate-400">El que conoce tu cliente.</p>
                        </div>
                        <div>
                            <label class="bmos-field-label">Razón social</label>
                            <input type="text" name="legal_name" value="{{ old('legal_name', $company->legal_name) }}" class="bmos-input"
                                   placeholder="Mi Negocio, SRL">
                            <p class="mt-1 text-xs text-slate-400">El nombre registrado. Si lo pones, es el que sale en los documentos.</p>
                        </div>
                        <div>
                            <label class="bmos-field-label">RNC o cédula</label>
                            <input type="text" name="tax_id" value="{{ old('tax_id', $company->tax_id) }}" class="bmos-input"
                                   placeholder="131000001">
                        </div>
                        <div>
                            <label class="bmos-field-label">Teléfono</label>
                            <input type="text" name="phone" value="{{ old('phone', $company->phone) }}" class="bmos-input"
                                   placeholder="(809) 555-0000">
                        </div>
                        <div class="sm:col-span-2">
                            <label class="bmos-field-label">Dirección</label>
                            <input type="text" name="address" value="{{ old('address', $company->address) }}" class="bmos-input"
                                   placeholder="Calle Duarte 45, Santiago">
                        </div>
                        <div class="sm:col-span-2">
                            <label class="bmos-field-label">Correo</label>
                            <input type="email" name="email" value="{{ old('email', $company->email) }}" class="bmos-input">
                        </div>
                    </div>
                </div>

                <div class="mt-5 bmos-card bmos-card-pad">
                    <p class="font-semibold text-slate-800">Logo</p>
                    <p class="mt-1 text-xs text-slate-500">
                        Sale arriba en los recibos. PNG con fondo transparente es lo que mejor queda.
                    </p>

                    <div class="mt-4 flex flex-wrap items-center gap-4">
                        <div class="flex h-24 w-40 shrink-0 items-center justify-center rounded-xl border border-dashed border-slate-300 bg-slate-50 p-2">
                            <template x-if="previa">
                                <img :src="previa" alt="Logo" class="max-h-full max-w-full object-contain">
                            </template>
                            <template x-if="!previa">
                                @if ($company->hasLogo())
                                    <img src="{{ $company->logoUrl() }}" alt="Logo actual" class="max-h-full max-w-full object-contain">
                                @else
                                    <span class="text-xs text-slate-400">Sin logo</span>
                                @endif
                            </template>
                        </div>

                        <div class="min-w-0 flex-1">
                            <input type="file" name="logo" accept="image/png,image/jpeg" @change="elegir($event)"
                                   class="block w-full text-sm text-slate-600 file:mr-3 file:rounded-lg file:border-0 file:bg-indigo-50 file:px-4 file:py-2 file:text-sm file:font-semibold file:text-indigo-700">
                            <p class="mt-2 text-xs text-slate-400" x-show="!aviso">
                                Se ajusta solo antes de subirlo, así que no hace falta que lo prepares.
                            </p>
                            <p class="mt-2 text-xs font-medium text-indigo-600" x-show="aviso" x-cloak x-text="aviso"></p>
                        </div>
                    </div>
                </div>

                {{-- Funciones opcionales. No son módulos: el módulo es lo que se contrata y lo decide
                     el plan; esto es lo que el cliente elige usar de lo que ya tiene. --}}
                <div class="mt-5 bmos-card bmos-card-pad">
                    <p class="font-semibold text-slate-800">¿Qué usa tu negocio?</p>
                    <p class="mt-1 text-xs text-slate-500">
                        Lo que apagues desaparece del menú. No se borra nada: al volver a encenderlo
                        está todo donde lo dejaste.
                    </p>

                    <label class="mt-4 flex cursor-pointer items-start gap-3 rounded-lg border border-slate-200 p-3 transition hover:bg-slate-50">
                        <input type="checkbox" name="features[option_groups]" value="1"
                               @checked(old('features.option_groups', $company->usesFeature('option_groups')))
                               class="mt-0.5 rounded border-slate-300 text-indigo-600">
                        <span class="min-w-0">
                            <span class="block text-sm font-medium text-slate-800">Tamaños y sabores</span>
                            <span class="block text-xs text-slate-500">
                                Para heladerías, batidas y comida rápida: al vender, el terminal pregunta
                                el tamaño, el sabor o los extras. Si vendes productos sueltos —un colmado,
                                una ferretería—, déjalo apagado.
                            </span>
                        </span>
                    </label>
                </div>

                <div class="mt-5 flex justify-end">
                    <button type="submit" class="bmos-btn bmos-btn-primary" x-bind:disabled="ajustando">
                        <span x-text="ajustando ? 'Ajustando el logo...' : 'Guardar'"></span>
                    </button>
                </div>
            </form>

            {{-- «Quitar el logo» va FUERA del formulario de arriba, y no por gusto: el componente de
                 confirmación pinta su propio <form>, y un <form> dentro de otro es HTML inválido. El
                 navegador desmonta el interior y de paso rompe el envío del exterior: con esto dentro,
                 las casillas de «¿Qué usa tu negocio?» no llegaban al servidor y parecía que el
                 interruptor no funcionaba. --}}
            @if ($company->hasLogo())
                <div class="mt-3 flex justify-start">
                    <x-panel.confirm-action
                        :action="route('panel.company-profile.logo.destroy')"
                        title="¿Quitar el logo?"
                        message="Los recibos volverán a salir solo con el nombre de la empresa."
                        note="Puedes volver a subirlo cuando quieras."
                        confirm="Quitar el logo"
                        class="bmos-btn bmos-btn-ghost text-rose-600">
                        Quitar el logo
                    </x-panel.confirm-action>
                </div>
            @endif
        </div>

        {{-- Vista previa del recibo. Es lo que de verdad se está editando: ver el resultado al lado
             evita el viaje de guardar, ir a una venta, imprimir y volver. --}}
        <div>
            <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-400">Así saldrá en el recibo</p>
            <div class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
                <div class="text-center">
                    @if ($company->hasLogo())
                        <img src="{{ $company->logoUrl() }}" alt="" class="mx-auto mb-2 max-h-16 max-w-[70%] object-contain">
                    @endif
                    <p class="font-bold text-slate-800">{{ $company->nombreParaDocumentos() }}</p>
                    @if ($company->tax_id)
                        <p class="text-xs text-slate-500">RNC: {{ $company->tax_id }}</p>
                    @endif
                    @if ($company->address)
                        <p class="text-xs text-slate-500">{{ $company->address }}</p>
                    @endif
                    @if ($company->phone)
                        <p class="text-xs text-slate-500">Tel: {{ $company->phone }}</p>
                    @endif
                </div>
                <div class="my-3 border-t border-dashed border-slate-300"></div>
                <p class="text-center text-xs font-semibold text-slate-600">RECIBO DE VENTA</p>
                <p class="text-center text-xs text-slate-400">V-000000</p>
                <div class="my-3 border-t border-dashed border-slate-300"></div>
                <div class="flex justify-between text-xs text-slate-500"><span>1 Producto de ejemplo</span><span>100.00</span></div>
                <div class="mt-3 flex justify-between border-t border-slate-200 pt-2 text-sm font-bold text-slate-800">
                    <span>TOTAL</span><span>DOP 100.00</span>
                </div>
            </div>

            @unless ($company->address && $company->phone)
                <p class="mt-3 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800">
                    Te faltan datos por rellenar. Un recibo con dirección y teléfono es el que el cliente
                    usa para volver a encontrarte.
                </p>
            @endunless
        </div>
    </div>

    <script>
        /**
         * Ajusta el logo en el NAVEGADOR antes de subirlo.
         *
         * En producción no hay GD ni Imagick, así que redimensionar en el servidor sería escribir
         * código que solo corre en local. Aquí, además, es donde está el archivo: se sube ya pequeño
         * en vez de mandar cinco megas para tirarlos después.
         *
         * Se conserva el PNG con su transparencia: aplanarlo a blanco dejaría un recuadro visible
         * sobre el papel del recibo.
         */
        function logoEmpresa() {
            return {
                previa: null,
                aviso: '',
                ajustando: false,
                _ajustado: null,

                async elegir(e) {
                    const archivo = e.target.files?.[0];
                    if (!archivo) { this.previa = null; this.aviso = ''; return; }

                    this.previa = URL.createObjectURL(archivo);
                    this.aviso = '';
                    this._ajustado = null;

                    try {
                        const nuevo = await this.encoger(archivo);
                        if (nuevo && nuevo.size < archivo.size) {
                            this._ajustado = nuevo;
                            this.aviso = `Se ajustó de ${this.kb(archivo.size)} a ${this.kb(nuevo.size)}.`;
                        }
                    } catch (err) {
                        // Si el navegador no puede con la imagen se sube tal cual y la valida el
                        // servidor: es preferible un aviso de «pesa demasiado» a no poder subir nada.
                        this._ajustado = null;
                    }
                },

                kb(bytes) { return Math.round(bytes / 1024) + ' KB'; },

                encoger(archivo) {
                    return new Promise((resolve, reject) => {
                        const img = new Image();
                        img.onerror = reject;
                        img.onload = () => {
                            const MAX_ANCHO = 600, MAX_ALTO = 300;
                            const escala = Math.min(1, MAX_ANCHO / img.width, MAX_ALTO / img.height);

                            // Ya es pequeño: no se toca. Reprocesarlo solo perdería nitidez.
                            if (escala >= 1) return resolve(null);

                            const lienzo = document.createElement('canvas');
                            lienzo.width = Math.round(img.width * escala);
                            lienzo.height = Math.round(img.height * escala);
                            lienzo.getContext('2d').drawImage(img, 0, 0, lienzo.width, lienzo.height);

                            const png = archivo.type === 'image/png';
                            lienzo.toBlob(
                                (b) => resolve(b ? new File([b], archivo.name, { type: b.type }) : null),
                                png ? 'image/png' : 'image/jpeg',
                                png ? undefined : 0.88,
                            );
                        };
                        img.src = URL.createObjectURL(archivo);
                    });
                },

                /** Sustituye el archivo por el ajustado justo antes de enviar. */
                retenerEnvio(e) {
                    if (!this._ajustado) return;

                    const input = e.target.querySelector('input[type="file"][name="logo"]');
                    if (!input) return;

                    const dt = new DataTransfer();
                    dt.items.add(this._ajustado);
                    input.files = dt.files;
                    this._ajustado = null;
                },
            };
        }
    </script>
</x-layouts.admin>
