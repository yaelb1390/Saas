{{--
    El botón de impresión global: 🖨️ Imprimir.

    Al pulsarlo sigue los siete pasos del spec: detecta el documento (`document-type` + `sale-id`, ya
    los trae quien lo usa), resuelve la plantilla y la impresora asignada al módulo, MUESTRA VISTA
    PREVIA, deja elegir la cantidad de copias, imprime al confirmar —por Bluetooth si la impresora es
    Bluetooth, si no por el diálogo del navegador— y registra el resultado en el historial.

    NO DEPENDE DE QUE EL CENTRO DE IMPRESIÓN ESTÉ ABIERTO: se usa en cualquier página —el recibo de
    una venta, más adelante un préstamo o una factura—, y por eso reconecta la impresora Bluetooth por
    su cuenta si hace falta (`reconectarGuardado`, en bluetooth.js): una conexión Bluetooth no
    sobrevive a cambiar de página.

    Uso:  <x-panel.print-button document-type="sale_ticket" module="sales" :sale-id="$sale->id" />
--}}
@props(['documentType', 'module' => null, 'saleId' => null, 'label' => '🖨️ Imprimir', 'class' => 'bmos-btn bmos-btn-primary'])

<div x-data="botonImprimir({
        documentType: @js($documentType),
        module: @js($module),
        saleId: @js($saleId),
        renderUrl: '{{ route('panel.printing.render') }}',
        jobUrl: '{{ route('panel.printing.jobs.store') }}',
        csrf: '{{ csrf_token() }}',
     })" x-init="init()" class="inline-block">
    <button type="button" @click="abrirVistaPrevia()" :disabled="cargando" {{ $attributes->merge(['class' => $class]) }}>
        <span x-show="!cargando">{{ $label }}</span>
        <span x-show="cargando" x-cloak>Preparando…</span>
    </button>

    {{-- El paso 4 del spec: vista previa antes de imprimir, con la cantidad de copias justo al
         lado —no hace falta un campo aparte que nadie ve hasta que ya se imprimió—. --}}
    <div x-show="abierto" x-cloak class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-900/50 p-4 py-8"
         @keydown.escape.window="abierto = false">
        <div @click.outside="abierto = false" x-transition class="w-full max-w-sm rounded-2xl bg-white p-5 shadow-2xl">
            <div class="mb-3 flex items-center justify-between">
                <h3 class="text-lg font-semibold text-slate-800">Vista previa</h3>
                <button type="button" @click="abierto = false" class="text-slate-400 hover:text-slate-600">✕</button>
            </div>

            <div class="rounded-xl bg-slate-100 p-3">
                <div class="bmos-doc-preview-frame" x-html="previewHtml" x-ref="preview"></div>
            </div>

            <p x-show="impresoraNombre" x-cloak class="mt-2 text-xs text-slate-500">
                Impresora: <span class="font-medium" x-text="impresoraNombre"></span>
            </p>
            <p x-show="!impresoraNombre" x-cloak class="mt-2 text-xs text-slate-500">
                Sin impresora asignada: se abre el diálogo del navegador.
            </p>

            <div class="mt-3 flex items-center justify-between gap-3">
                <label class="text-sm text-slate-600" for="copias-{{ $documentType }}">Copias</label>
                <input id="copias-{{ $documentType }}" type="number" x-model.number="copies" min="1" max="20" class="bmos-input w-20 text-center">
            </div>

            <div class="mt-4 flex justify-end gap-2">
                <button type="button" @click="abierto = false" class="bmos-btn bmos-btn-ghost">Cancelar</button>
                <button type="button" @click="confirmarEImprimir()" :disabled="imprimiendo" class="bmos-btn bmos-btn-primary">
                    <span x-show="!imprimiendo">Imprimir</span>
                    <span x-show="imprimiendo" x-cloak>Imprimiendo…</span>
                </button>
            </div>
        </div>
    </div>

    <script>
        function botonImprimir(cfg) {
            return {
                cfg,
                copies: 1,
                cargando: false,
                imprimiendo: false,
                abierto: false,
                previewHtml: '',
                impresoraNombre: '',
                _datos: null,

                async init() {
                    await Promise.all([window.loadPrintingBluetooth?.(), window.loadPrintingCodes?.()]);
                },

                async post(url, data) {
                    const res = await fetch(url, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': cfg.csrf, Accept: 'application/json' },
                        body: JSON.stringify(data),
                    });
                    if (!res.ok) throw new Error((await res.json().catch(() => null))?.message || 'No se pudo completar la operación.');

                    return res.json();
                },

                /** Pasos 1-4: detecta el documento, resuelve plantilla e impresora, y lo enseña. */
                async abrirVistaPrevia() {
                    this.cargando = true;

                    try {
                        this._datos = await this.post(cfg.renderUrl, {
                            document_type: cfg.documentType,
                            module: cfg.module,
                            sale_id: cfg.saleId,
                        });

                        this.previewHtml = this._datos.html;
                        this.impresoraNombre = this._datos.printer?.name ?? '';
                        this.abierto = true;

                        this.$nextTick(async () => {
                            if (window.BmosPrintingCodes) await window.BmosPrintingCodes.dibujarCodigos(this.$refs.preview);
                        });
                    } catch (e) {
                        window.avisoRapido?.(e.message, 'error');
                    } finally {
                        this.cargando = false;
                    }
                },

                /** Pasos 5-7: copias, imprimir de verdad y registrar en el historial. */
                async confirmarEImprimir() {
                    this.imprimiendo = true;
                    let estado = 'printed';
                    let errorMsg = null;
                    const datos = this._datos;

                    try {
                        const impresora = datos.printer;

                        if (impresora?.connection_type === 'bluetooth' && window.BmosBluetooth) {
                            const conexion = await window.BmosBluetooth.reconectarGuardado(impresora.bt_device_id);

                            if (conexion) {
                                for (let i = 0; i < this.copies; i++) {
                                    await window.BmosBluetooth.imprimirEscPos(conexion.characteristic, datos.escpos_base64);
                                }
                            } else {
                                // No se pudo reconectar en silencio (nunca se emparejó desde este
                                // navegador, o el usuario la desconectó). Se avisa y se cae al
                                // diálogo del navegador, que siempre funciona.
                                window.avisoRapido?.(`No se pudo conectar con «${impresora.name}» por Bluetooth. Ábrela primero desde el Centro de Impresión.`, 'error');
                                await this._imprimirPorNavegador(datos.html);
                            }
                        } else {
                            await this._imprimirPorNavegador(datos.html);
                        }

                        this.abierto = false;
                    } catch (e) {
                        estado = 'error';
                        errorMsg = String(e?.message || e).slice(0, 250);
                        window.avisoRapido?.('No se pudo imprimir: ' + errorMsg, 'error');
                    }

                    await this.post(cfg.jobUrl, {
                        document_type: cfg.documentType,
                        printer_id: datos?.printer?.id ?? null,
                        template_id: datos?.template_id ?? null,
                        copies: this.copies,
                        status: estado,
                        error_message: errorMsg,
                    }).catch(() => {});

                    this.imprimiendo = false;
                },

                async _imprimirPorNavegador(html) {
                    let contenedor = document.getElementById('bmos-print-doc');
                    if (!contenedor) {
                        contenedor = document.createElement('div');
                        contenedor.id = 'bmos-print-doc';
                        document.body.appendChild(contenedor);
                    }
                    contenedor.innerHTML = '<div class="bmos-doc-preview-frame">' + html + '</div>';

                    if (window.BmosPrintingCodes) await window.BmosPrintingCodes.dibujarCodigos(contenedor);

                    await new Promise((r) => setTimeout(r, 80));
                    window.print();
                },
            };
        }
    </script>
</div>
