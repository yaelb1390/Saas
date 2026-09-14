{{--
    Plantillas de impresión: qué lleva cada tipo de documento, con vista previa en vivo.

    La vista previa NO se re-implementa en JavaScript: cada cambio dispara (con una pequeña espera,
    para no golpear el servidor en cada tecla) el mismo endpoint que arma la impresión real
    (`panel.printing.render`). Así lo que se ve aquí es EXACTAMENTE lo que sale al imprimir — nunca
    una maqueta que diverge del renderizador de verdad.
--}}
<div x-data="editorDePlantillas({
        renderUrl: '{{ route('panel.printing.render') }}',
        csrf: '{{ csrf_token() }}',
        documentTypeGroups: @js($documentTypeGroups),
     })" x-init="init()">

    <div class="bmos-card overflow-hidden">
        <div class="flex items-center justify-between border-b border-slate-100 p-4">
            <p class="font-semibold text-slate-800">Plantillas</p>
            <button type="button" @click="nueva()" class="bmos-btn bmos-btn-primary">+ Nueva plantilla</button>
        </div>

        @if ($templates->isEmpty())
            <p class="bmos-empty">Todavía no hay plantillas propias — se usa el diseño de fábrica de cada documento.</p>
        @else
            <div class="overflow-x-auto">
                <table class="bmos-table bmos-tabla-tarjetas">
                    <thead><tr><th>Documento</th><th>Nombre</th><th>Papel</th><th>Por defecto</th><th class="text-right">Acciones</th></tr></thead>
                    <tbody>
                        @foreach ($templates as $template)
                            <tr>
                                <td data-rotulo="Documento">{{ \App\Modules\Printing\Support\DocumentType::label($template->document_type) }}</td>
                                <td data-rotulo="Nombre" class="font-medium text-slate-700">{{ $template->name }}</td>
                                <td data-rotulo="Papel">{{ \App\Modules\Printing\Support\PaperSize::label($template->paper_size) }}</td>
                                <td data-rotulo="Por defecto">
                                    @if ($template->is_default)<span class="bmos-badge badge-blue">Sí</span>@else — @endif
                                </td>
                                <td class="text-right">
                                    <button type="button" @click='editar(@json($template))' class="bmos-btn bmos-btn-ghost text-xs">Editar</button>
                                    <x-panel.confirm-action :action="route('panel.printing.templates.destroy', $template)"
                                        title="¿Borrar «{{ $template->name }}»?"
                                        message="Ese tipo de documento vuelve a usar el diseño de fábrica."
                                        confirm="Borrar" tooltip="Borrar"
                                        class="bmos-btn bmos-btn-ghost text-xs text-rose-600">Borrar</x-panel.confirm-action>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    {{-- El editor: formulario a la izquierda, vista previa en vivo a la derecha. --}}
    <div x-show="abierto" x-cloak class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-900/50 p-4 py-8"
         @keydown.escape.window="abierto = false">
        <div @click.outside="abierto = false" x-transition class="grid w-full max-w-5xl gap-4 rounded-2xl bg-white p-6 shadow-2xl lg:grid-cols-2">
            <div>
                <div class="mb-3 flex items-center justify-between">
                    <h3 class="text-lg font-semibold text-slate-800">Editar plantilla</h3>
                    <button type="button" @click="abierto = false" class="text-slate-400 hover:text-slate-600">✕</button>
                </div>

                <form method="POST" :action="accionUrl" class="space-y-3" @submit="guardando = true">
                    @csrf
                    <template x-if="editandoId"><input type="hidden" name="_method" value="PUT"></template>

                    <div>
                        <label class="bmos-field-label">Tipo de documento</label>
                        <select x-model="form.document_type" @change="refrescar()" class="bmos-input" name="document_type" required>
                            <template x-for="(tipos, categoria) in cfg.documentTypeGroups" :key="categoria">
                                <optgroup :label="categoriaLabel(categoria)">
                                    <template x-for="(etiqueta, clave) in tipos" :key="clave">
                                        <option :value="clave" x-text="etiqueta"></option>
                                    </template>
                                </optgroup>
                            </template>
                        </select>
                    </div>

                    <div><label class="bmos-field-label">Nombre de la plantilla</label>
                        <input type="text" name="name" x-model="form.name" class="bmos-input" required></div>

                    <div><label class="bmos-field-label">Tamaño de papel</label>
                        <select name="paper_size" x-model="form.paper_size" @change="refrescar()" class="bmos-input">
                            @foreach (\App\Modules\Printing\Support\PaperSize::options() as $clave => $etiqueta)
                                <option value="{{ $clave }}">{{ $etiqueta }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="grid grid-cols-2 gap-3 rounded-lg border border-slate-100 p-3">
                        <label class="flex items-center gap-2 text-sm">
                            <input type="checkbox" name="layout[logo][enabled]" value="1" x-model="form.layout.logo.enabled" @change="refrescar()" class="rounded border-slate-300 text-indigo-600">
                            Logo
                        </label>
                        <select name="layout[logo][size]" x-model="form.layout.logo.size" @change="refrescar()" class="bmos-input" x-show="form.layout.logo.enabled">
                            <option value="sm">Pequeño</option><option value="md">Mediano</option><option value="lg">Grande</option>
                        </select>
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="layout[show_company_name]" value="1" x-model="form.layout.show_company_name" @change="refrescar()" class="rounded border-slate-300 text-indigo-600"> Nombre de la empresa</label>
                        <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="layout[show_phone]" value="1" x-model="form.layout.show_phone" @change="refrescar()" class="rounded border-slate-300 text-indigo-600"> Teléfono</label>
                        <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="layout[show_address]" value="1" x-model="form.layout.show_address" @change="refrescar()" class="rounded border-slate-300 text-indigo-600"> Dirección</label>
                        <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="layout[show_rnc]" value="1" x-model="form.layout.show_rnc" @change="refrescar()" class="rounded border-slate-300 text-indigo-600"> RNC</label>
                        <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="layout[show_ncf]" value="1" x-model="form.layout.show_ncf" @change="refrescar()" class="rounded border-slate-300 text-indigo-600"> NCF / e-CF</label>
                    </div>

                    <div><label class="bmos-field-label">Encabezado (texto libre)</label>
                        <input type="text" name="layout[header_text]" x-model="form.layout.header_text" @input.debounce.400ms="refrescar()" class="bmos-input"></div>
                    <div><label class="bmos-field-label">Pie de página</label>
                        <input type="text" name="layout[footer_text]" x-model="form.layout.footer_text" @input.debounce.400ms="refrescar()" class="bmos-input"></div>

                    <div class="grid grid-cols-2 gap-3">
                        <div><label class="bmos-field-label">Tamaño de letra</label>
                            <select name="layout[font_size]" x-model="form.layout.font_size" @change="refrescar()" class="bmos-input">
                                <option value="sm">Pequeña</option><option value="md">Mediana</option><option value="lg">Grande</option>
                            </select>
                        </div>
                        <div><label class="bmos-field-label">Alinear encabezado</label>
                            <select name="layout[align_header]" x-model="form.layout.align_header" @change="refrescar()" class="bmos-input">
                                <option value="left">Izquierda</option><option value="center">Centro</option><option value="right">Derecha</option>
                            </select>
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="layout[qr][enabled]" value="1" x-model="form.layout.qr.enabled" @change="refrescar()" class="rounded border-slate-300 text-indigo-600"> Código QR</label>
                        <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="layout[barcode][enabled]" value="1" x-model="form.layout.barcode.enabled" @change="refrescar()" class="rounded border-slate-300 text-indigo-600"> Código de barras</label>
                    </div>
                    <input type="hidden" name="layout[qr][source]" value="reference">
                    <input type="hidden" name="layout[barcode][source]" value="reference">

                    {{-- Textos libres adicionales: lo que el spec llama «agregar textos». --}}
                    <div>
                        <div class="flex items-center justify-between">
                            <label class="bmos-field-label mb-0">Campos adicionales</label>
                            <button type="button" @click="form.layout.extra_fields.push({label:'',value:''}); refrescar()" class="text-xs font-medium text-indigo-600">+ Agregar</button>
                        </div>
                        <template x-for="(campo, i) in form.layout.extra_fields" :key="i">
                            <div class="mt-1 flex gap-2">
                                <input type="text" :name="'layout[extra_fields][' + i + '][label]'" x-model="campo.label" @input.debounce.400ms="refrescar()" placeholder="Etiqueta" class="bmos-input">
                                <input type="text" :name="'layout[extra_fields][' + i + '][value]'" x-model="campo.value" @input.debounce.400ms="refrescar()" placeholder="Valor" class="bmos-input">
                                <button type="button" @click="form.layout.extra_fields.splice(i, 1); refrescar()" class="text-slate-400 hover:text-rose-600">&times;</button>
                            </div>
                        </template>
                    </div>

                    <label class="flex items-center gap-2 text-sm">
                        <input type="hidden" name="is_default" value="0">
                        <input type="checkbox" name="is_default" value="1" x-model="form.is_default" class="rounded border-slate-300 text-indigo-600">
                        Usar esta como predeterminada para este tipo de documento
                    </label>

                    <div class="flex justify-end gap-2 pt-2">
                        <button type="button" @click="abierto = false" class="bmos-btn bmos-btn-ghost">Cancelar</button>
                        <button type="submit" class="bmos-btn bmos-btn-primary" :disabled="guardando">Guardar plantilla</button>
                    </div>
                </form>
            </div>

            {{-- La vista previa: se re-renderiza sola —ver refrescar()— cada vez que algo cambia a la
                 izquierda. Con datos de muestra: no hace falta una venta real para diseñar un ticket. --}}
            <div class="rounded-xl bg-slate-100 p-4">
                <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">Vista previa</p>
                <div class="bmos-doc-preview-frame" x-html="previewHtml" x-ref="preview"></div>
                <p x-show="cargandoPreview" x-cloak class="mt-2 text-center text-xs text-slate-400">Actualizando…</p>
            </div>
        </div>
    </div>
</div>

<script>
    function editorDePlantillas(cfg) {
        return {
            cfg,
            abierto: false,
            editandoId: null,
            guardando: false,
            cargandoPreview: false,
            previewHtml: '',
            form: {},

            init() {
                this.form = this.blanco();
            },

            blanco(documentType) {
                const primerGrupo = Object.values(this.cfg.documentTypeGroups)[0] || {};
                const tipo = documentType || Object.keys(primerGrupo)[0] || 'receipt';

                return {
                    document_type: tipo,
                    name: '',
                    paper_size: '80mm',
                    is_default: false,
                    layout: {
                        logo: { enabled: true, size: 'md' },
                        header_text: '', footer_text: '',
                        show_company_name: true, show_phone: true, show_address: true, show_rnc: false, show_ncf: false,
                        font_size: 'md', align_header: 'center', align_totals: 'right',
                        qr: { enabled: false, source: 'reference' }, barcode: { enabled: false, source: 'reference' },
                        extra_fields: [],
                    },
                };
            },

            categoriaLabel(clave) {
                const etiquetas = { ticket: 'Tickets', invoice: 'Facturas', report: 'Reportes', document: 'Documentos' };
                return etiquetas[clave] || clave;
            },

            get accionUrl() {
                return this.editandoId
                    ? '{{ url('panel/impresion/plantillas') }}/' + this.editandoId
                    : '{{ route('panel.printing.templates.store') }}';
            },

            nueva() {
                this.editandoId = null;
                this.form = this.blanco();
                this.abierto = true;
                this.refrescar();
            },

            editar(template) {
                this.editandoId = template.id;
                this.form = {
                    document_type: template.document_type,
                    name: template.name,
                    paper_size: template.paper_size,
                    is_default: !!template.is_default,
                    layout: { ...this.blanco().layout, ...template.layout },
                };
                this.abierto = true;
                this.refrescar();
            },

            _temporizador: null,
            refrescar() {
                clearTimeout(this._temporizador);
                this.cargandoPreview = true;
                this._temporizador = setTimeout(() => this._pedirPreview(), 300);
            },

            async _pedirPreview() {
                try {
                    const res = await fetch(this.cfg.renderUrl, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.cfg.csrf, Accept: 'application/json' },
                        body: JSON.stringify({
                            document_type: this.form.document_type,
                            template_id: this.editandoId,
                            layout: this.form.layout,
                            paper_size: this.form.paper_size,
                        }),
                    });
                    const datos = await res.json();
                    this.previewHtml = datos.html;
                    this.$nextTick(async () => {
                        await window.loadPrintingCodes();
                        window.BmosPrintingCodes.dibujarCodigos(this.$refs.preview);
                    });
                } catch {
                    // Un fallo de vista previa no debe impedir seguir editando ni guardar.
                } finally {
                    this.cargandoPreview = false;
                }
            },
        };
    }
</script>
