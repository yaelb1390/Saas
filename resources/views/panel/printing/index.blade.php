{{--
    El Centro de Impresión: impresoras, Bluetooth, plantillas, impresora por módulo e historial.

    Una sola pantalla con pestañas —como Gastos o WhatsApp—, no cinco rutas: son aspectos del mismo
    centro. La «Configuración de impresora» del spec vive DENTRO de cada impresora (su modal de
    editar, en la pestaña «Buscar impresoras») y no como pestaña propia: son datos de la misma ficha,
    y separarlos solo obligaría a abrir dos sitios para configurar una impresora.
--}}
<x-layouts.admin title="Centro de Impresión" heading="Centro de Impresión"
                 subheading="Impresoras, plantillas y el historial de lo que se ha impreso">
    <div x-data="centroDeImpresion({
            printerStatusUrl: (id) => '{{ url('panel/impresion/impresoras') }}/' + id + '/estado',
            defaultUrl: '{{ route('panel.printing.printers.default') }}',
            renderUrl: '{{ route('panel.printing.render') }}',
            jobUrl: '{{ route('panel.printing.jobs.store') }}',
            csrf: '{{ csrf_token() }}',
            miPredeterminadaId: {{ $miPredeterminada?->id ?? 'null' }},
         })" x-init="init()">

        @if ($esPrimeraVez)
            {{-- El onboarding: la primera vez que se entra sin ninguna impresora registrada. --}}
            <div class="bmos-card bmos-card-pad mb-6 text-center">
                <x-icono name="printer" class="mx-auto h-10 w-10 text-indigo-400" />
                <h2 class="mt-3 text-lg font-bold text-slate-800">Configura tu primera impresora</h2>
                <p class="mx-auto mt-1 max-w-md text-sm text-slate-500">
                    Elige cómo se conecta y empieza a imprimir tickets, facturas y reportes desde BMIA.
                </p>
                <div class="mx-auto mt-5 grid max-w-2xl gap-3 sm:grid-cols-3">
                    <button type="button" @click="tab = 'bluetooth'"
                            class="rounded-xl border-2 border-blue-200 bg-blue-50 p-4 text-left transition hover:border-blue-300">
                        <x-icono name="bluetooth" class="h-6 w-6 text-blue-600" />
                        <p class="mt-2 font-semibold text-blue-900">Buscar Bluetooth</p>
                        <p class="mt-0.5 text-xs text-blue-700">Térmicas inalámbricas de caja</p>
                    </button>
                    <button type="button" @click="tab = 'buscar'; abrirNuevaConTipo('network')"
                            class="rounded-xl border-2 border-emerald-200 bg-emerald-50 p-4 text-left transition hover:border-emerald-300">
                        <x-icono name="wifi" class="h-6 w-6 text-emerald-600" />
                        <p class="mt-2 font-semibold text-emerald-900">Buscar impresora de red</p>
                        <p class="mt-0.5 text-xs text-emerald-700">Se registra con su IP</p>
                    </button>
                    <button type="button" @click="tab = 'buscar'; abrirNuevaConTipo('browser')"
                            class="rounded-xl border-2 border-amber-200 bg-amber-50 p-4 text-left transition hover:border-amber-300">
                        <x-icono name="sliders" class="h-6 w-6 text-amber-600" />
                        <p class="mt-2 font-semibold text-amber-900">Configurar manualmente</p>
                        <p class="mt-0.5 text-xs text-amber-700">USB o la del sistema</p>
                    </button>
                </div>
            </div>
        @endif

        <div class="bmos-pestanas mb-4">
            <button type="button" @click="tab = 'buscar'" :class="tab === 'buscar' && 'is-activa'" class="bmos-pestana">Buscar impresoras</button>
            <button type="button" @click="tab = 'bluetooth'" :class="tab === 'bluetooth' && 'is-activa'" class="bmos-pestana">Bluetooth</button>
            @can('printing.manage')
                <button type="button" @click="tab = 'plantillas'" :class="tab === 'plantillas' && 'is-activa'" class="bmos-pestana">Plantillas</button>
                <button type="button" @click="tab = 'modulos'" :class="tab === 'modulos' && 'is-activa'" class="bmos-pestana">Impresora por módulo</button>
                <button type="button" @click="tab = 'historial'" :class="tab === 'historial' && 'is-activa'" class="bmos-pestana">Historial</button>
            @endcan
        </div>

        <div x-show="tab === 'buscar'">
            @include('panel.printing.partials.tab-buscar')
        </div>

        <div x-show="tab === 'bluetooth'" x-cloak>
            @include('panel.printing.partials.tab-bluetooth')
        </div>

        @can('printing.manage')
            <div x-show="tab === 'plantillas'" x-cloak>
                @include('panel.printing.partials.tab-plantillas')
            </div>

            <div x-show="tab === 'modulos'" x-cloak>
                @include('panel.printing.partials.tab-modulos')
            </div>

            <div x-show="tab === 'historial'" x-cloak>
                @include('panel.printing.partials.tab-historial')
            </div>
        @endcan

        {{-- El documento real, oculto hasta que se imprime de verdad (ver .bmos-print-doc en app.css). --}}
        <div id="bmos-print-doc"><div class="bmos-doc-preview-frame" x-ref="printFrame"></div></div>
    </div>

    <script>
        /**
         * El estado de toda la pantalla. Las acciones que solo CAMBIAN datos (alta, edición, borrado
         * de impresoras y plantillas) van por formularios normales —recargan la página, como el
         * resto del panel—; lo que aquí se hace con JavaScript es lo que de verdad lo necesita:
         * hablar con Bluetooth, dibujar la vista previa en vivo y disparar una impresión real.
         */
        function centroDeImpresion(cfg) {
            return {
                tab: 'buscar',
                cfg,

                // ---- Bluetooth (ver también tab-bluetooth.blade.php) ----
                bt: {
                    soportado: false,
                    motivo: null,
                    buscando: false,
                    dispositivos: [], // [{name, deviceId, device, conectando, conectado, bateria}]
                },

                async init() {
                    // Bajo demanda: ver `window.loadPrintingBluetooth` en resources/js/app.js.
                    await window.loadPrintingBluetooth();
                    // Async: no basta con que la API exista (Brave la trae pero la bloquea por
                    // privacidad), hay que preguntarle de verdad si hay un adaptador disponible.
                    this.bt.motivo = await window.BmosBluetooth.motivoNoDisponible();
                    this.bt.soportado = this.bt.motivo === null;
                },

                /**
                 * Abre el modal de alta manual (el que pinta el componente «create-modal») y, si se pide,
                 * precarga algún campo antes de mostrarlo. Se apoya en la estructura que ya se conoce
                 * del componente —un botón disparador seguido del panel del modal— y no en un id que
                 * el componente no expone.
                 */
                abrirModalNuevaImpresora(rellenar) {
                    const raiz = document.querySelector('#printer-create-wrap')?.firstElementChild;
                    if (!raiz) return;

                    const disparador = raiz.querySelector(':scope > button');
                    const form = raiz.querySelector('form');
                    rellenar?.(form);
                    disparador?.click();
                },

                abrirNuevaConTipo(tipo) {
                    this.$nextTick(() => this.abrirModalNuevaImpresora((form) => {
                        const sel = form?.querySelector('select[name="connection_type"]');
                        if (sel) { sel.value = tipo; sel.dispatchEvent(new Event('input')); }
                    }));
                },

                async buscarBluetooth() {
                    if (!this.bt.soportado) return;
                    await window.loadPrintingBluetooth();
                    this.bt.buscando = true;

                    try {
                        const resultado = await window.BmosBluetooth.elegirDispositivo();

                        // El usuario cerró el selector sin elegir nada: no es un error que avisar.
                        // Cualquier OTRO fallo (adaptador bloqueado, sin Bluetooth…) ya viene con
                        // mensaje claro y se muestra abajo — ver bluetooth.js, elegirDispositivo().
                        if (!resultado.cancelado) {
                            const { device, name, deviceId } = resultado;
                            // Si ya estaba en la lista (se volvió a elegir el mismo), no se duplica.
                            if (!this.bt.dispositivos.some((d) => d.deviceId === deviceId)) {
                                this.bt.dispositivos.push({ name, deviceId, device, conectando: false, conectado: false, bateria: null, characteristic: null });
                            }
                        }
                    } catch (e) {
                        window.avisoRapido?.(e?.message || 'No se pudo abrir el selector de Bluetooth.', 'error');
                    } finally {
                        this.bt.buscando = false;
                    }
                },

                async conectarBluetooth(item) {
                    item.conectando = true;
                    try {
                        const { characteristic, batteryLevel } = await window.BmosBluetooth.conectar(item.device);
                        item.characteristic = characteristic;
                        item.conectado = true;
                        item.bateria = batteryLevel;
                        window.avisoRapido?.(`«${item.name}» conectada.`, 'ok');
                    } catch (e) {
                        window.avisoRapido?.(e?.message || 'No se pudo conectar.', 'error');
                    } finally {
                        item.conectando = false;
                    }
                },

                olvidarBluetooth(item) {
                    window.BmosBluetooth.desconectar(item.device);
                    if (item.device?.forget) item.device.forget().catch(() => {});
                    this.bt.dispositivos = this.bt.dispositivos.filter((d) => d.deviceId !== item.deviceId);
                },

                registrarDesdeBluetooth(item) {
                    // Precarga el alta manual con lo que ya se sabe del dispositivo emparejado; el
                    // resto (tamaño de papel, márgenes…) lo completa la persona.
                    this.tab = 'buscar';
                    this.$nextTick(() => this.abrirModalNuevaImpresora((form) => {
                        if (!form) return;
                        form.querySelector('[name="name"]').value = item.name;
                        const sel = form.querySelector('[name="connection_type"]');
                        sel.value = 'bluetooth';
                        sel.dispatchEvent(new Event('input'));
                        form.querySelector('[name="bt_device_id"]').value = item.deviceId;
                    }));
                },

                // ---- Estado / predeterminada / prueba de impresión (formularios normales por fetch) ----

                async post(url, data) {
                    const res = await fetch(url, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.cfg.csrf, Accept: 'application/json' },
                        body: JSON.stringify(data),
                    });
                    if (!res.ok) throw new Error('No se pudo completar la acción.');

                    return res.json().catch(() => ({}));
                },

                async marcarEstado(printerId, status) {
                    try {
                        await this.post(this.cfg.printerStatusUrl(printerId), { status });
                        location.reload();
                    } catch (e) {
                        window.avisoRapido?.(e.message, 'error');
                    }
                },

                async marcarPredeterminada(printerId) {
                    try {
                        await this.post(this.cfg.defaultUrl, { printer_id: printerId });
                        location.reload();
                    } catch (e) {
                        window.avisoRapido?.(e.message, 'error');
                    }
                },

                /**
                 * Arma el documento (HTML + ESC/POS) en el servidor y lo manda a salir de verdad: por
                 * Bluetooth si la impresora es Bluetooth y está conectada en esta sesión, o por el
                 * diálogo del navegador en cualquier otro caso.
                 */
                async imprimir({ documentType, printerId = null, saleId = null, templateId = null, copies = 1, btItem = null }) {
                    await Promise.all([window.loadPrintingBluetooth(), window.loadPrintingCodes()]);

                    const datos = await this.post(this.cfg.renderUrl, {
                        document_type: documentType,
                        template_id: templateId,
                        sale_id: saleId,
                    });

                    let estado = 'printed';
                    let errorMsg = null;

                    try {
                        if (btItem?.conectado && btItem?.characteristic) {
                            for (let i = 0; i < copies; i++) {
                                await window.BmosBluetooth.imprimirEscPos(btItem.characteristic, datos.escpos_base64);
                            }
                        } else {
                            const frame = this.$refs.printFrame;
                            frame.innerHTML = datos.html;
                            await window.BmosPrintingCodes.dibujarCodigos(frame);
                            await new Promise((r) => setTimeout(r, 80));
                            window.print();
                        }
                    } catch (e) {
                        estado = 'error';
                        errorMsg = String(e?.message || e).slice(0, 250);
                    }

                    await this.post(this.cfg.jobUrl, {
                        document_type: documentType,
                        printer_id: printerId,
                        template_id: datos.template_id,
                        copies,
                        status: estado,
                        error_message: errorMsg,
                    }).catch(() => {});

                    if (estado === 'error') window.avisoRapido?.('No se pudo imprimir: ' + errorMsg, 'error');
                },

                pruebaDeImpresion(printer) {
                    const btItem = printer.connection_type === 'bluetooth'
                        ? this.bt.dispositivos.find((d) => d.deviceId === printer.bt_device_id)
                        : null;

                    this.imprimir({ documentType: 'test_page', printerId: printer.id, btItem });
                },
            };
        }
    </script>

    @include('partials.toast')
</x-layouts.admin>
