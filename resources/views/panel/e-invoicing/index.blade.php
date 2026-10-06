{{--
    Facturación Electrónica (e-CF) — resumen de la empresa activa.

    Diseño con daisyUI (clases `d-`, tema «bmia» limitado a este contenedor: ver app.css). Arriba lo
    que se viene a mirar —estado e indicadores—; debajo, pestañas en vez de una columna larga.

    Las pestañas ocultan con x-show, nunca con x-if: todos los formularios siguen en la página. La
    pestaña abierta sale del #ancla (los pasos y los redirects ya apuntan a #datos, #proveedor…) y,
    si volvió un error de validación, de la pestaña del formulario que falló.

    Esta pantalla no llama a la DGII: enseña lo último registrado. Ver docs/FACTURACION_ELECTRONICA.md.
--}}
@php
    $pestanaConError = match (true) {
        $errors->hasAny(['psfe', 'certificate', 'password']) => 'firma',
        $errors->hasAny(['ecf_type', 'range_from', 'range_to', 'authorized_at', 'expires_at']) => 'secuencias',
        $errors->any() => 'configuracion',
        default => null,
    };
    $pestanas = [
        'resumen' => 'Resumen',
        'configuracion' => 'Configuración',
        'firma' => 'Proveedor y firma',
        'secuencias' => 'Secuencias',
        'tecnico' => 'Técnico',
    ];
    // Cada ancla de la página, en qué pestaña vive.
    $anclas = [
        'datos' => 'configuracion', 'emision' => 'configuracion',
        'proveedor' => 'firma', 'certificado' => 'firma',
        'secuencias' => 'secuencias',
    ] + array_combine(array_keys($pestanas), array_keys($pestanas));
@endphp
<x-layouts.admin title="Facturación Electrónica" heading="Facturación Electrónica"
                 subheading="Comprobantes fiscales electrónicos (e-CF) ante la DGII">
    <div data-theme="bmia" class="bmos-ecf mx-auto max-w-5xl space-y-4"
         x-data="{
             tab: 'resumen',
             anclas: @js($anclas),
             init() {
                 const abrir = () => {
                     const ancla = location.hash.slice(1);
                     const pestana = this.anclas[ancla];
                     if (! pestana) return;
                     this.tab = pestana;
                     this.$nextTick(() => document.getElementById(ancla)?.scrollIntoView({ block: 'start' }));
                 };
                 this.tab = @js($pestanaConError) ?? this.anclas[location.hash.slice(1)] ?? 'resumen';
                 if (! @js($pestanaConError)) abrir();
                 window.addEventListener('hashchange', abrir);
             },
             ir(pestana) {
                 this.tab = pestana;
                 history.replaceState(null, '', '#' + pestana);
             },
         }">

        {{-- Aviso fijo. No se puede cerrar a propósito: es lo único que no debe malinterpretarse. --}}
        <div role="alert" class="d-alert d-alert-soft d-alert-info text-sm">
            <span><b>Esto no es una autorización de la DGII.</b>
            BMIA prepara y valida tus comprobantes electrónicos, pero solo la DGII autoriza a una empresa a emitir e-CF,
            después de su proceso de certificación.</span>
        </div>

        @if ($migracionPendiente)
            <div role="alert" class="d-alert d-alert-soft d-alert-warning text-sm">
                <span>La base de datos todavía no tiene las tablas de facturación electrónica. El administrador de la
                plataforma debe aplicar las migraciones pendientes.</span>
            </div>
        @endif

        @if ($ajustes && ! $ajustes->environment->isFiscal())
            <div role="alert" class="d-alert d-alert-soft d-alert-warning text-sm">
                <span>Ambiente: <b>{{ $ajustes->environment->label() }}</b>. Nada de lo que se emita aquí tiene validez fiscal.</span>
            </div>
        @endif

        {{-- Cabecera: de un vistazo, cómo está la empresa y qué ha pasado con sus documentos. --}}
        <div class="bmos-card bmos-card-pad">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="min-w-0">
                    <p class="truncate text-lg font-semibold text-slate-800">{{ $ajustes?->legal_name ?: $empresa->name }}</p>
                    <p class="text-sm text-slate-500">
                        RNC {{ $ajustes?->tax_id ?: 'sin configurar' }}
                        · {{ $ajustes?->environment->label() ?? '—' }}
                        @if ($ajustes && $modoDisponible) · {{ $ajustes->emissionMode()->label() }} @endif
                    </p>
                </div>
                @if ($ajustes)
                    <x-panel.ecf-badge :tono="$ajustes->status->badge()" grande>{{ $ajustes->status->label() }}</x-panel.ecf-badge>
                @endif
            </div>

            <div class="d-stats d-stats-vertical mt-4 w-full border border-slate-200 sm:d-stats-horizontal">
                <div class="d-stat">
                    <div class="d-stat-title">Pendientes</div>
                    <div class="d-stat-value text-warning">{{ $contadores['pendientes'] }}</div>
                    <div class="d-stat-desc">por enviar o en espera de la DGII</div>
                </div>
                <div class="d-stat">
                    <div class="d-stat-title">Aceptados</div>
                    <div class="d-stat-value text-success">{{ $contadores['aceptados'] }}</div>
                    <div class="d-stat-desc">en el ambiente actual y anteriores</div>
                </div>
                <div class="d-stat">
                    <div class="d-stat-title">Rechazados</div>
                    <div class="d-stat-value text-error">{{ $contadores['rechazados'] }}</div>
                    <div class="d-stat-desc">revisa el motivo en cada documento</div>
                </div>
                @if ($cifras)
                    <div class="d-stat">
                        <div class="d-stat-title">Últimos 30 días</div>
                        <div class="d-stat-value">{{ $cifras['totales']['documentos'] }}</div>
                        <div class="d-stat-desc">
                            {{ $cifras['totales']['aceptacion'] !== null ? $cifras['totales']['aceptacion'].' % de aceptación' : 'sin documentos resueltos' }}
                        </div>
                    </div>
                @endif
            </div>

            <div class="mt-4 flex flex-wrap gap-2">
                <a href="{{ route('panel.e-invoicing.documents') }}" class="d-btn d-btn-sm d-btn-primary d-btn-soft">Ver documentos</a>
                <a href="{{ route('panel.e-invoicing.received') }}" class="d-btn d-btn-sm d-btn-soft">Recibidos</a>
                <a href="{{ route('panel.e-invoicing.diagnostics') }}" class="d-btn d-btn-sm d-btn-soft">Diagnóstico</a>
                @can('ecf.audit')
                    <a href="{{ route('panel.e-invoicing.audit') }}" class="d-btn d-btn-sm d-btn-soft">Auditoría</a>
                @endcan
            </div>
        </div>

        {{-- Pestañas. Botones, no enlaces: el ancla la escribe `ir()` sin saltar la página. --}}
        {{-- Con peso propio: icono, negrita y la activa rellena del índigo de marca. Las de daisyUI
             (`d-tabs`) quedaban demasiado discretas sobre la tarjeta blanca y no se veía dónde se estaba. --}}
        @php
            $iconosPestana = ['resumen' => 'chart', 'configuracion' => 'sliders', 'firma' => 'shield', 'secuencias' => 'receipt', 'tecnico' => 'wrench'];
        @endphp
        <div role="tablist" class="flex gap-1.5 overflow-x-auto rounded-2xl border border-slate-200 bg-white p-1.5 shadow-sm">
            @foreach ($pestanas as $clave => $nombre)
                <button type="button" role="tab"
                        class="flex shrink-0 items-center gap-2 rounded-xl px-4 py-2.5 text-sm font-semibold transition"
                        :class="tab === @js($clave)
                            ? 'bg-indigo-600 text-white shadow-md shadow-indigo-600/30'
                            : 'text-slate-600 hover:bg-indigo-50 hover:text-indigo-700'"
                        :aria-selected="tab === @js($clave)"
                        @click="ir(@js($clave))">
                    <x-icono :name="$iconosPestana[$clave]" class="h-5 w-5" />
                    {{ $nombre }}
                </button>
            @endforeach
        </div>

        {{-- ── Resumen ───────────────────────────────────────────────────────────────── --}}
        <div x-show="tab === 'resumen'" class="space-y-4">
        @if ($pasos !== [])
            @php $hechos = collect($pasos)->where('done', true)->count(); $siguiente = collect($pasos)->firstWhere('done', false); @endphp
            <div class="bmos-card bmos-card-pad">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <p class="font-semibold text-slate-800">Pasos para emitir e-CF</p>
                    <p class="text-xs text-slate-500">{{ $hechos }} de {{ count($pasos) }}</p>
                </div>
                <progress class="d-progress d-progress-success mt-2 w-full" value="{{ $hechos }}" max="{{ count($pasos) }}"></progress>

                <ul class="d-steps d-steps-vertical mt-4 w-full text-sm lg:d-steps-horizontal">
                    @foreach ($pasos as $p)
                        @php
                            $enlace = match ($p['anchor']) {
                                'diagnostico' => route('panel.e-invoicing.diagnostics'),
                                'documentos' => route('panel.e-invoicing.documents'),
                                default => '#'.$p['anchor'],
                            };
                            $esSiguiente = $siguiente && $siguiente['n'] === $p['n'];
                        @endphp
                        <li class="d-step {{ $p['done'] ? 'd-step-success' : ($esSiguiente ? 'd-step-primary' : '') }}"
                            data-content="{{ $p['done'] ? '✓' : $p['n'] }}">
                            <a href="{{ $enlace }}" class="text-left lg:text-center {{ $p['done'] ? 'text-slate-500' : 'font-medium text-slate-800 hover:text-indigo-600' }}">{{ $p['title'] }}</a>
                        </li>
                    @endforeach
                </ul>

                @if ($siguiente)
                    <div class="d-alert d-alert-soft d-alert-info mt-4 text-sm">
                        <span><b>Siguiente: {{ $siguiente['title'] }}.</b> {{ $siguiente['detail'] }}</span>
                    </div>
                @endif
            </div>
        @endif

        <div class="bmos-card bmos-card-pad">
            <p class="font-semibold text-slate-800">Estado</p>
            <p class="mt-1 text-xs text-slate-500">Cómo está tu empresa para emitir comprobantes electrónicos.</p>

            <dl class="mt-4 divide-y divide-slate-100 text-sm">
                <div class="flex items-center justify-between gap-3 py-2">
                    <dt class="text-slate-500">Estado</dt>
                    <dd>
                        @if ($ajustes)
                            <span class="bmos-badge {{ $ajustes->status->badge() }}">{{ $ajustes->status->label() }}</span>
                        @else
                            <span class="bmos-badge badge-gray">No disponible</span>
                        @endif
                    </dd>
                </div>
                <div class="flex items-center justify-between gap-3 py-2">
                    <dt class="text-slate-500">Empresa</dt>
                    <dd class="text-right text-slate-800">{{ $ajustes?->legal_name ?: $empresa->name }}</dd>
                </div>
                <div class="flex items-center justify-between gap-3 py-2">
                    <dt class="text-slate-500">RNC</dt>
                    <dd class="text-slate-800">{{ $ajustes?->tax_id ?: 'Sin configurar' }}</dd>
                </div>
                <div class="flex items-center justify-between gap-3 py-2">
                    <dt class="text-slate-500">Ambiente</dt>
                    <dd class="text-slate-800">{{ $ajustes?->environment->label() ?? '—' }}</dd>
                </div>
                <div class="flex items-center justify-between gap-3 py-2">
                    <dt class="text-slate-500">Usuario administrador e-CF</dt>
                    <dd class="text-slate-800">{{ $ajustes?->ecf_admin_user ?: 'Sin configurar' }}</dd>
                </div>
                @php
                    $estadoCert = $certificado?->status();
                    $etiquetaCert = match ($estadoCert) {
                        'vigente' => ['Vigente hasta '.$certificado->valid_to->format('d/m/Y'), 'badge-green'],
                        'por_vencer' => ['Vence el '.$certificado->valid_to->format('d/m/Y'), 'badge-amber'],
                        'vencido' => ['Vencido', 'badge-red'],
                        'aun_no_valido' => ['Aún no es válido', 'badge-amber'],
                        default => ['No configurado', 'badge-gray'],
                    };
                @endphp
                <div class="flex items-center justify-between gap-3 py-2">
                    <dt class="text-slate-500">Certificado digital</dt>
                    <dd><span class="bmos-badge {{ $etiquetaCert[1] }}">{{ $etiquetaCert[0] }}</span></dd>
                </div>
                <div class="flex items-center justify-between gap-3 py-2">
                    <dt class="text-slate-500">Última comunicación con la DGII</dt>
                    <dd class="text-slate-800">{{ $ajustes?->last_dgii_contact_at?->diffForHumans() ?? 'Ninguna todavía' }}</dd>
                </div>
                <div class="flex items-center justify-between gap-3 py-2">
                    <dt class="text-slate-500">Documentos pendientes · aceptados · rechazados</dt>
                    <dd class="text-slate-800">
                        {{ $contadores['pendientes'] }} · {{ $contadores['aceptados'] }} · {{ $contadores['rechazados'] }}
                        <a href="{{ route('panel.e-invoicing.documents') }}" class="ml-2 font-medium text-indigo-600 hover:text-indigo-700">Ver documentos</a>
                        <a href="{{ route('panel.e-invoicing.received') }}" class="ml-2 font-medium text-indigo-600 hover:text-indigo-700">Recibidos</a>
                        <a href="{{ route('panel.e-invoicing.diagnostics') }}" class="ml-2 font-medium text-indigo-600 hover:text-indigo-700">Diagnóstico</a>
                        @can('ecf.audit')
                            <a href="{{ route('panel.e-invoicing.audit') }}" class="ml-2 font-medium text-indigo-600 hover:text-indigo-700">Auditoría</a>
                        @endcan
                    </dd>
                </div>
            </dl>
        </div>

        @if ($cifras && $cifras['totales']['documentos'] > 0)
            <div class="bmos-card bmos-card-pad">
                <p class="font-semibold text-slate-800">Emisión de los últimos 30 días</p>
                <p class="mt-1 text-xs text-slate-500">Ambiente {{ $ajustes->environment->label() }}. Rechazados no suman importes.</p>

                <dl class="mt-4 grid grid-cols-2 gap-3 text-sm sm:grid-cols-4">
                    <div><dt class="text-slate-500">Documentos</dt><dd class="text-lg font-semibold text-slate-800">{{ $cifras['totales']['documentos'] }}</dd></div>
                    <div><dt class="text-slate-500">Aceptación</dt><dd class="text-lg font-semibold text-slate-800">{{ $cifras['totales']['aceptacion'] !== null ? $cifras['totales']['aceptacion'].' %' : '—' }}</dd></div>
                    <div><dt class="text-slate-500">Total facturado</dt><dd class="text-lg font-semibold text-slate-800">{{ number_format((float) $cifras['totales']['total'], 2) }}</dd></div>
                    <div><dt class="text-slate-500">ITBIS</dt><dd class="text-lg font-semibold text-slate-800">{{ number_format((float) $cifras['totales']['itbis'], 2) }}</dd></div>
                </dl>

                <div class="mt-4" x-data="ecfEmisionChart(@js($cifras['dias']), @js($cifras['aceptados']), @js($cifras['rechazados']), @js($cifras['pendientes']))">
                    <div style="height:220px"><canvas x-ref="canvas"></canvas></div>
                </div>

                @if ($cifras['tipos'] !== [])
                    <table class="bmos-table mt-4 text-sm">
                        <thead><tr><th>Tipo</th><th class="text-right">Documentos</th><th class="text-right">Total</th><th class="text-right">ITBIS</th></tr></thead>
                        <tbody>
                            @foreach ($cifras['tipos'] as $t)
                                <tr>
                                    <td>{{ $t['tipo'] }}</td>
                                    <td class="text-right">{{ $t['documentos'] }}</td>
                                    <td class="text-right">{{ number_format((float) $t['total'], 2) }}</td>
                                    <td class="text-right">{{ number_format((float) $t['itbis'], 2) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>

            <script>
                // Barras apiladas por día: aceptados, rechazados y pendientes (Chart.js bajo demanda).
                function ecfEmisionChart(dias, aceptados, rechazados, pendientes) {
                    return {
                        chart: null,
                        async init() {
                            const Chart = await window.loadChart();
                            this.chart = new Chart(this.$refs.canvas.getContext('2d'), {
                                type: 'bar',
                                data: {
                                    labels: dias,
                                    datasets: [
                                        { label: 'Aceptados', data: aceptados, backgroundColor: '#10b981', borderRadius: 3, maxBarThickness: 22 },
                                        { label: 'Rechazados', data: rechazados, backgroundColor: '#f43f5e', borderRadius: 3, maxBarThickness: 22 },
                                        { label: 'Pendientes', data: pendientes, backgroundColor: '#f59e0b', borderRadius: 3, maxBarThickness: 22 },
                                    ],
                                },
                                options: {
                                    responsive: true,
                                    maintainAspectRatio: false,
                                    interaction: { intersect: false, mode: 'index' },
                                    plugins: { legend: { position: 'bottom', labels: { color: '#64748b', boxWidth: 10, font: { size: 11 } } } },
                                    scales: {
                                        x: { stacked: true, grid: { display: false }, ticks: { color: '#94a3b8', maxTicksLimit: 10 } },
                                        y: { stacked: true, beginAtZero: true, ticks: { precision: 0, color: '#94a3b8' }, grid: { color: '#eef0f6' } },
                                    },
                                },
                            });
                        },
                        destroy() { if (this.chart) this.chart.destroy(); },
                    };
                }
            </script>
        @endif
        </div>

        {{-- ── Configuración ─────────────────────────────────────────────────────────── --}}
        <div x-show="tab === 'configuracion'" x-cloak class="space-y-4">
        @if ($ajustes)
            @can('ecf.configure')
                <div id="datos" class="bmos-card bmos-card-pad scroll-mt-20"
                     x-data="{
                         provincia: @js(old('province', $ajustes->province ?? '')),
                         municipio: @js(old('municipality', $ajustes->municipality ?? '')),
                         municipios: @js(collect($municipios)->map(fn ($n, $c) => ['c' => (string) $c, 'n' => $n])->values()),
                         ambiente: @js(old('environment', $ajustes->environment->value)),
                     }">
                    <p class="font-semibold text-slate-800">Datos fiscales y ambiente</p>
                    <p class="mt-1 text-xs text-slate-500">
                        Lo que aparece como emisor en cada e-CF. Provincia y municipio usan los códigos oficiales de la DGII.
                    </p>

                    <form method="POST" action="{{ route('panel.e-invoicing.settings.update') }}" class="mt-4 space-y-3">
                        @csrf
                        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                            <x-panel.field name="tax_id" label="RNC o cédula" :value="$ajustes->tax_id" />
                            <x-panel.field name="legal_name" label="Razón social" :value="$ajustes->legal_name" />
                            <x-panel.field name="trade_name" label="Nombre comercial (opcional)" :value="$ajustes->trade_name" />
                            <x-panel.field name="address" label="Dirección" :value="$ajustes->address" />
                            <div>
                                <label class="bmos-field-label" for="ecf-provincia">Provincia (opcional)</label>
                                <select id="ecf-provincia" name="province" x-model="provincia" class="bmos-input">
                                    <option value="">—</option>
                                    @foreach ($provincias as $codigo => $nombre)
                                        <option value="{{ $codigo }}">{{ $nombre }}</option>
                                    @endforeach
                                </select>
                                @error('province') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="bmos-field-label" for="ecf-municipio">Municipio (opcional)</label>
                                {{-- Lista filtrada por la provincia (un option oculto no se oculta en Safari). --}}
                                <select id="ecf-municipio" name="municipality" class="bmos-input" x-model="municipio">
                                    <option value="">—</option>
                                    <template x-for="m in municipios.filter(m => provincia && m.c.slice(0, 2) === provincia.slice(0, 2))" :key="m.c">
                                        <option :value="m.c" x-text="m.n" :selected="m.c === municipio"></option>
                                    </template>
                                </select>
                                @error('municipality') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                            </div>
                            <x-panel.field name="phone" label="Teléfono (opcional)" :value="$ajustes->phone" />
                            <x-panel.field name="email" label="Correo (opcional)" :value="$ajustes->email" />
                            <x-panel.field name="ecf_admin_user" label="Usuario administrador e-CF (opcional)" :value="$ajustes->ecf_admin_user" />
                        </div>

                        <div class="grid grid-cols-1 gap-3 border-t border-slate-100 pt-3 sm:grid-cols-2">
                            <div>
                                <label class="bmos-field-label" for="ecf-ambiente">Ambiente</label>
                                <select id="ecf-ambiente" name="environment" x-model="ambiente" class="bmos-input">
                                    @foreach ($ambientes as $amb)
                                        <option value="{{ $amb->value }}">{{ $amb->label() }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="bmos-field-label" for="ecf-proveedor">Proveedor</label>
                                <select id="ecf-proveedor" name="provider" class="bmos-input">
                                    @foreach ($proveedores as $clave => $nombre)
                                        <option value="{{ $clave }}" @selected(old('provider', $ajustes->provider) === $clave)>{{ $nombre }}</option>
                                    @endforeach
                                </select>
                                @error('provider') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                            </div>
                        </div>

                        <label x-show="ambiente === 'produccion' && @js($ajustes->environment->value) !== 'produccion'"
                               class="flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800">
                            <input type="checkbox" name="confirm_authorized" value="1" class="mt-0.5">
                            <span>Confirmo que la DGII ya autorizó a mi empresa a emitir comprobantes fiscales electrónicos.
                                En producción cada e-CF tiene validez fiscal.</span>
                        </label>
                        @error('confirm_authorized') <p class="text-xs text-rose-600">{{ $message }}</p> @enderror

                        <div class="flex justify-end">
                            <button type="submit" class="bmos-btn bmos-btn-primary">Guardar configuración</button>
                        </div>
                    </form>
                </div>
            @endcan
        @endif

        @if ($ajustes && $modoDisponible)
            <div id="emision" class="bmos-card bmos-card-pad scroll-mt-20">
                <p class="font-semibold text-slate-800">Emisión en ventas y facturas</p>
                <p class="mt-1 text-xs text-slate-500">
                    Qué pasa al facturar desde Facturación, el punto de venta, Venta rápida, Cotizaciones y el Mostrador.
                </p>

                <dl class="mt-4 divide-y divide-slate-100 text-sm">
                    <div class="flex items-center justify-between gap-3 py-2">
                        <dt class="text-slate-500">Modo actual</dt>
                        <dd class="text-right text-slate-800">{{ $ajustes->emissionMode()->label() }}</dd>
                    </div>
                </dl>

                @can('ecf.configure')
                    <form method="POST" action="{{ route('panel.e-invoicing.mode.update') }}" class="mt-4 space-y-2 rounded-lg border border-slate-200 p-3">
                        @csrf
                        @foreach ($modos as $modo)
                            @php $permitido = $modo->allowedIn($ajustes->environment); @endphp
                            <label class="flex items-start gap-2 text-sm {{ $permitido ? 'text-slate-800' : 'text-slate-400' }}">
                                <input type="radio" name="emission_mode" value="{{ $modo->value }}" class="mt-0.5"
                                       @checked($ajustes->emissionMode() === $modo) @disabled(! $permitido)>
                                <span>
                                    <b class="font-medium">{{ $modo->label() }}</b>
                                    <span class="block text-xs text-slate-500">
                                        @switch($modo)
                                            @case(\App\Modules\ElectronicInvoicing\Domain\EmissionMode::Apagado)
                                                Solo comprobantes de la serie B, como hasta ahora.
                                                @break
                                            @case(\App\Modules\ElectronicInvoicing\Domain\EmissionMode::Sombra)
                                                La serie B sigue siendo tu comprobante. Con cada factura se genera además un e-CF de prueba para
                                                comprobar que todo funciona; si falla, la venta no se afecta. Solo en pruebas y certificación.
                                                @break
                                            @case(\App\Modules\ElectronicInvoicing\Domain\EmissionMode::Real)
                                                El e-CF sustituye a la serie B. Solo en producción, después de que la DGII te autorice.
                                                @break
                                        @endswitch
                                    </span>
                                </span>
                            </label>
                        @endforeach
                        <div class="flex justify-end pt-1">
                            <button type="submit" class="bmos-btn bmos-btn-primary">Guardar modo</button>
                        </div>
                        @error('emission_mode')
                            <p class="text-xs text-rose-600">{{ $message }}</p>
                        @enderror
                    </form>
                @endcan
            </div>
        @endif
        </div>

        {{-- ── Proveedor y firma ─────────────────────────────────────────────────────── --}}
        <div x-show="tab === 'firma'" x-cloak class="space-y-4">
        {{-- Proveedores certificados (PSFE): VARIOS, en orden. El primero es el principal y los demás,
             respaldos por los que sale la factura si el anterior falla (PsfeProvider). El catálogo y los
             campos salen de cada conector (config/ecf_psfe.php); cada conexión se prueba en vivo y solo
             se guarda si funciona (PsfeConnectionService). Las claves no se vuelven a enseñar nunca: un
             campo secreto vacío conserva la guardada. --}}
        @if ($ajustes)
            @php
                $principal = $psfeConexiones[0] ?? null;
                $firmaProveedor = $principal !== null && $principal['driver']->capabilities()->signs;
                $conectados = array_column($psfeConexiones, 'slug');
                $porConectar = array_diff_key($psfeCatalogo, array_flip($conectados));
                $elegido = old('psfe', count($porConectar) === 1 ? array_key_first($porConectar) : '');
                $fecha = fn (?string $iso) => $iso ? \Illuminate\Support\Carbon::parse($iso)->timezone(config('app.timezone'))->format('d/m/Y H:i') : '—';
            @endphp
            <div id="proveedor" class="bmos-card bmos-card-pad scroll-mt-20" x-data="{ elegido: @js($elegido) }">
                <p class="font-semibold text-slate-800">Proveedores autorizados</p>
                <p class="mt-1 text-xs text-slate-500">
                    Conecta uno o varios proveedores de servicios de facturación electrónica (PSFE) autorizados por la DGII.
                    Las facturas salen por el <b>principal</b>; si falla, salen solas por el siguiente, sin enviarse dos veces.
                    Si tu proveedor firma por ti, no necesitas subir certificado.
                </p>

                @if ($psfeConexiones !== [])
                    <ol class="mt-4 space-y-3">
                        @foreach ($psfeConexiones as $i => $c)
                            <li class="rounded-xl border p-3 {{ $c['check_ok'] ? 'border-slate-200' : 'border-amber-300 bg-amber-50/50' }}">
                                <div class="flex flex-wrap items-start justify-between gap-3">
                                    <div class="flex min-w-0 items-start gap-3">
                                        <span class="mt-1 h-2.5 w-2.5 shrink-0 rounded-full {{ $c['check_ok'] ? 'bg-emerald-500' : 'bg-amber-500' }}"></span>
                                        <div class="min-w-0 text-sm">
                                            <p class="font-semibold text-slate-800">
                                                {{ $c['driver']->label() }}
                                                <span class="bmos-badge {{ $i === 0 ? 'badge-blue' : 'badge-gray' }} ml-1">{{ $i === 0 ? 'Principal' : 'Respaldo '.$i }}</span>
                                            </p>
                                            @if ($c['account'])
                                                <p class="text-xs text-slate-600">{{ $c['account'] }}</p>
                                            @endif
                                            <p class="mt-1 text-xs text-slate-500">
                                                {{ $c['driver']->capabilities()->signs ? 'Firma por ti' : 'Firma con tu certificado' }}
                                                · Última prueba: {{ $fecha($c['checked_at']) }}
                                                <span class="{{ $c['check_ok'] ? 'text-emerald-700' : 'font-semibold text-amber-700' }}">{{ $c['check_ok'] ? 'correcta' : 'falló' }}</span>
                                                @if (! $c['check_ok'] && $c['check_message'])
                                                    — {{ $c['check_message'] }}
                                                @endif
                                            </p>
                                        </div>
                                    </div>

                                    @can('ecf.configure')
                                        <div class="flex flex-wrap items-center gap-2">
                                            @if ($i > 0)
                                                <form method="POST" action="{{ route('panel.e-invoicing.psfe.raise', $c['slug']) }}">
                                                    @csrf
                                                    <button type="submit" class="bmos-btn bmos-btn-suave" title="Subir en el orden">{{ $i === 1 ? 'Hacer principal' : 'Subir' }}</button>
                                                </form>
                                            @endif
                                            <form method="POST" action="{{ route('panel.e-invoicing.psfe.test', $c['slug']) }}">
                                                @csrf
                                                <button type="submit" class="bmos-btn bmos-btn-suave">Probar</button>
                                            </form>
                                            <x-panel.confirm-action
                                                :action="route('panel.e-invoicing.psfe.disconnect', $c['slug'])"
                                                method="DELETE"
                                                :title="'¿Desconectar '.$c['driver']->label().'?'"
                                                :message="'Se borran los datos de tu cuenta de '.$c['driver']->label().' guardados en BMIA.'"
                                                :note="count($psfeConexiones) === 1 && $c['driver']->capabilities()->signs ? 'Es tu único proveedor: si no tienes certificado digital, la emisión de e-CF se apagará.' : null"
                                                confirm="Desconectar"
                                                dismiss="Volver"
                                                tone="danger"
                                                class="bmos-btn border border-rose-200 bg-rose-50 text-rose-700 hover:bg-rose-100">
                                                Desconectar
                                            </x-panel.confirm-action>
                                        </div>
                                    @endcan
                                </div>
                            </li>
                        @endforeach
                    </ol>
                    @if (count($psfeConexiones) === 1)
                        <p class="mt-2 text-xs text-slate-500">Consejo: conecta un segundo proveedor como respaldo para que tus facturas no se detengan si el principal falla.</p>
                    @endif
                @elseif ($psfeCatalogo === [])
                    <p class="mt-4 rounded-lg border border-slate-200 p-3 text-sm text-slate-500">
                        Todavía no hay proveedores disponibles para conectar en el ambiente {{ $ajustes->environment->label() }}.
                    </p>
                @endif

                @can('ecf.configure')
                    @if ($psfeCatalogo !== [])
                        <details class="mt-4 rounded-lg border border-slate-200 p-3" @if ($psfeConexiones === [] || $errors->has('psfe')) open @endif>
                            <summary class="cursor-pointer text-sm font-medium text-slate-800">
                                {{ $psfeConexiones === [] ? 'Elige tu proveedor' : 'Añadir otro proveedor o cambiar los datos de una cuenta' }}
                            </summary>

                            <form method="POST" action="{{ route('panel.e-invoicing.psfe.connect') }}" class="mt-3 space-y-3">
                                @csrf
                                <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                                    @foreach ($psfeCatalogo as $slug => $conector)
                                        <label class="flex cursor-pointer items-start gap-2 rounded-lg border p-3 text-sm"
                                               :class="elegido === @js($slug) ? 'border-indigo-300 bg-indigo-50' : 'border-slate-200'">
                                            <input type="radio" name="psfe" value="{{ $slug }}" x-model="elegido" class="mt-0.5">
                                            <span>
                                                <b class="font-medium text-slate-800">{{ $conector->label() }}</b>
                                                @if (in_array($slug, $conectados, true))
                                                    <span class="bmos-badge badge-green ml-1">Conectado</span>
                                                @endif
                                                <span class="block text-xs text-slate-500">{{ $conector->description() }}</span>
                                                @if ($conector->capabilities()->signs)
                                                    <span class="mt-1 inline-block text-xs font-medium text-emerald-700">Firma por ti: no necesitas certificado</span>
                                                @endif
                                            </span>
                                        </label>
                                    @endforeach
                                </div>

                                @foreach ($psfeCatalogo as $slug => $conector)
                                    @php $guardado = in_array($slug, $conectados, true); @endphp
                                    <div x-show="elegido === @js($slug)" x-cloak class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                        @foreach ($conector->fields() as $campo)
                                            <div>
                                                <label class="bmos-field-label" for="psfe-{{ $slug }}-{{ $campo->name }}">{{ $campo->label }}</label>
                                                {{-- Deshabilitado si no es el proveedor elegido: así solo viajan sus datos. --}}
                                                <input id="psfe-{{ $slug }}-{{ $campo->name }}" name="credentials[{{ $campo->name }}]"
                                                       type="{{ $campo->secret ? 'password' : 'text' }}" autocomplete="off" maxlength="{{ $campo->maxLength }}"
                                                       :disabled="elegido !== @js($slug)"
                                                       placeholder="{{ $campo->secret && $guardado ? 'Guardada · déjala vacía para conservarla' : '' }}"
                                                       class="bmos-input">
                                                @if ($campo->help)
                                                    <p class="mt-1 text-xs text-slate-500">{{ $campo->help }}</p>
                                                @endif
                                            </div>
                                        @endforeach
                                    </div>
                                @endforeach

                                <p class="text-xs text-slate-500">Se guarda cifrado y no se vuelve a mostrar. Antes de guardar, BMIA prueba la conexión con tu proveedor. Un proveedor nuevo entra como respaldo; cambia el orden en la lista.</p>
                                @error('psfe') <p class="text-xs text-rose-600">{{ $message }}</p> @enderror

                                <div class="flex justify-end">
                                    <button type="submit" class="bmos-btn bmos-btn-primary" :disabled="elegido === ''">Conectar y probar</button>
                                </div>
                            </form>
                        </details>
                    @endif
                @endcan
            </div>
        @endif

        <div id="certificado" class="bmos-card bmos-card-pad scroll-mt-20">
            <p class="font-semibold text-slate-800">Certificado digital</p>
            @if ($firmaProveedor ?? false)
                <p class="mt-2 rounded-lg border border-emerald-200 bg-emerald-50 p-2 text-xs text-emerald-800">
                    No hace falta: la firma la hace tu proveedor principal ({{ $principal['driver']->label() }}). Solo súbelo si quieres firmar desde BMIA.
                </p>
            @endif
            <p class="mt-1 text-xs text-slate-500">
                El certificado para procesos tributarios con el que se firman los e-CF, emitido por una prestadora acreditada
                por INDOTEL. Se guarda cifrado y la clave privada nunca se muestra.
            </p>

            @if ($certificado)
                <dl class="mt-4 divide-y divide-slate-100 text-sm">
                    <div class="flex items-start justify-between gap-3 py-2">
                        <dt class="shrink-0 text-slate-500">Titular</dt>
                        <dd class="min-w-0 break-words text-right text-slate-800">{{ $certificado->subject }}</dd>
                    </div>
                    <div class="flex items-start justify-between gap-3 py-2">
                        <dt class="shrink-0 text-slate-500">Emitido por</dt>
                        <dd class="min-w-0 break-words text-right text-slate-600">{{ $certificado->issuer }}</dd>
                    </div>
                    <div class="flex items-center justify-between gap-3 py-2">
                        <dt class="text-slate-500">Válido</dt>
                        <dd class="text-slate-800">{{ $certificado->valid_from->format('d/m/Y') }} – {{ $certificado->valid_to->format('d/m/Y') }}</dd>
                    </div>
                </dl>
            @else
                <p class="mt-4 rounded-lg border border-slate-200 p-3 text-sm text-slate-500">Todavía no hay un certificado cargado.</p>
            @endif

            @can('ecf.configure')
                @if (! $migracionPendiente)
                    <form method="POST" action="{{ route('panel.e-invoicing.certificate.store') }}" enctype="multipart/form-data"
                          class="mt-4 rounded-lg border border-slate-200 p-3">
                        @csrf
                        <p class="text-sm font-medium text-slate-800">{{ $certificado ? 'Reemplazar el certificado' : 'Subir el certificado' }}</p>
                        <div class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-3">
                            <div class="sm:col-span-2">
                                <label class="bmos-field-label" for="cert-archivo">Archivo .p12 o .pfx</label>
                                <input id="cert-archivo" name="certificate" type="file" accept=".p12,.pfx" class="bmos-input">
                            </div>
                            <div>
                                <label class="bmos-field-label" for="cert-clave">Contraseña</label>
                                <input id="cert-clave" name="password" type="password" autocomplete="off" class="bmos-input">
                            </div>
                        </div>
                        <div class="mt-3 flex justify-end">
                            <button type="submit" class="bmos-btn bmos-btn-primary">Guardar certificado</button>
                        </div>
                        @error('certificate')
                            <p class="mt-2 text-xs text-rose-600">{{ $message }}</p>
                        @enderror
                        @error('password')
                            <p class="mt-2 text-xs text-rose-600">{{ $message }}</p>
                        @enderror
                    </form>
                @endif
            @endcan
        </div>
        </div>

        {{-- ── Secuencias ────────────────────────────────────────────────────────────── --}}
        <div x-show="tab === 'secuencias'" x-cloak class="space-y-4">
        <div id="secuencias" class="bmos-card bmos-card-pad scroll-mt-20">
            <p class="font-semibold text-slate-800">Secuencias de e-NCF</p>
            <p class="mt-1 text-xs text-slate-500">
                Los rangos que la DGII te autorizó en su Oficina Virtual. BMIA no pide números: solo anota los autorizados
                para no salirse de ellos. Cada ambiente tiene los suyos.
            </p>

            @if ($secuencias->isEmpty())
                <p class="mt-4 rounded-lg border border-slate-200 p-3 text-sm text-slate-500">Todavía no hay secuencias registradas.</p>
            @else
                <div class="mt-4 overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-slate-200 text-left text-xs text-slate-500">
                                <th class="py-2 pr-3 font-medium">Tipo</th>
                                <th class="py-2 pr-3 font-medium">Ambiente</th>
                                <th class="py-2 pr-3 font-medium">Próximo</th>
                                <th class="py-2 pr-3 font-medium">Quedan</th>
                                <th class="py-2 pr-3 font-medium">Vence</th>
                                <th class="py-2 font-medium">Estado</th>
                                <th class="py-2"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($secuencias as $s)
                                @php
                                    $estado = match (true) {
                                        ! $s->is_active => ['Inactiva', 'badge-gray'],
                                        $s->isExpired() => ['Vencida', 'badge-red'],
                                        ! $s->hasAvailableNumbers() => ['Agotada', 'badge-red'],
                                        default => ['Activa', 'badge-green'],
                                    };
                                @endphp
                                <tr>
                                    <td class="py-2 pr-3 text-slate-800">{{ $s->ecf_type->prefix() }}</td>
                                    <td class="py-2 pr-3 text-slate-600">{{ $s->environment->label() }}</td>
                                    <td class="py-2 pr-3 font-mono text-xs text-slate-700">{{ $s->hasAvailableNumbers() ? $ncf->format($s->ecf_type, $s->next_number) : '—' }}</td>
                                    <td class="py-2 pr-3 text-slate-600">{{ number_format($s->remaining()) }}</td>
                                    <td class="py-2 pr-3 text-slate-600">{{ $s->expires_at?->format('d/m/Y') ?? 'No vence' }}</td>
                                    <td class="py-2"><span class="bmos-badge {{ $estado[1] }}">{{ $estado[0] }}</span></td>
                                    <td class="py-2 text-right">
                                        {{-- Anula ante la DGII los números aún sin usar (ANECF): no se pueden recuperar. --}}
                                        @can('ecf.cancel')
                                            @if ($s->remaining() > 0)
                                                <x-panel.confirm-action
                                                    :action="route('panel.e-invoicing.sequences.void', $s)"
                                                    method="POST"
                                                    title="¿Anular los e-NCF sin usar?"
                                                    :message="'Se anulan ante la DGII los '.number_format($s->remaining()).' números sin usar de '.$s->ecf_type->prefix().', desde '.$ncf->format($s->ecf_type, $s->next_number).'.'"
                                                    note="No se podrán usar nunca más."
                                                    irreversible
                                                    confirm="Anular ante la DGII"
                                                    dismiss="Volver"
                                                    tone="danger"
                                                    class="text-xs font-medium text-rose-600 hover:text-rose-700">
                                                    Anular lo no usado
                                                </x-panel.confirm-action>
                                            @endif
                                        @endcan
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            @can('ecf.configure')
                @if (! $migracionPendiente)
                    <form method="POST" action="{{ route('panel.e-invoicing.sequences.store') }}" class="mt-4 rounded-lg border border-slate-200 p-3">
                        @csrf
                        <p class="text-sm font-medium text-slate-800">Registrar un rango autorizado</p>
                        <div class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-3">
                            <div>
                                <label class="bmos-field-label" for="sec-ambiente">Ambiente</label>
                                <select id="sec-ambiente" name="environment" class="bmos-input">
                                    @foreach ($ambientes as $amb)
                                        <option value="{{ $amb->value }}" @selected(old('environment', 'pruebas') === $amb->value)>{{ $amb->label() }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="bmos-field-label" for="sec-tipo">Tipo</label>
                                <select id="sec-tipo" name="ecf_type" class="bmos-input">
                                    @foreach ($tipos as $tipo)
                                        <option value="{{ $tipo->value }}" @selected((int) old('ecf_type', 32) === $tipo->value)>{{ $tipo->prefix() }} · {{ $tipo->label() }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label class="bmos-field-label" for="sec-desde">Desde</label>
                                    <input id="sec-desde" name="range_from" type="number" min="1" value="{{ old('range_from', 1) }}" class="bmos-input">
                                </div>
                                <div>
                                    <label class="bmos-field-label" for="sec-hasta">Hasta</label>
                                    <input id="sec-hasta" name="range_to" type="number" min="1" value="{{ old('range_to') }}" class="bmos-input">
                                </div>
                            </div>
                            <div>
                                <label class="bmos-field-label" for="sec-autorizada">Autorizada el</label>
                                <input id="sec-autorizada" name="authorized_at" type="date" value="{{ old('authorized_at') }}" class="bmos-input">
                            </div>
                            <div>
                                <label class="bmos-field-label" for="sec-vence">Vence el (vacío si no vence)</label>
                                <input id="sec-vence" name="expires_at" type="date" value="{{ old('expires_at') }}" class="bmos-input">
                            </div>
                            <div class="flex items-end">
                                <button type="submit" class="bmos-btn bmos-btn-primary w-full justify-center">Registrar</button>
                            </div>
                        </div>
                        @php($erroresSecuencia = collect(['environment', 'ecf_type', 'range_from', 'range_to', 'authorized_at', 'expires_at'])->flatMap(fn ($c) => $errors->get($c)))
                        @if ($erroresSecuencia->isNotEmpty())
                            <ul class="mt-3 space-y-1 text-xs text-rose-600">
                                @foreach ($erroresSecuencia as $error)
                                    <li>{{ $error }}</li>
                                @endforeach
                            </ul>
                        @endif
                    </form>
                @endif
            @endcan
        </div>
        </div>

        {{-- ── Técnico ─────────────────────────────────────────────────────────────────── --}}
        <div x-show="tab === 'tecnico'" x-cloak class="space-y-4">
        <div class="bmos-card bmos-card-pad">
            <p class="font-semibold text-slate-800">Requisitos técnicos</p>
            <p class="mt-1 text-xs text-slate-500">Lo que el servidor necesita para generar, validar y firmar e-CF.</p>

            <ul class="mt-4 space-y-2 text-sm">
                @foreach ($requisitos as $req)
                    <li class="flex items-start justify-between gap-3 rounded-lg border border-slate-200 p-3">
                        <span class="min-w-0">
                            <span class="block font-medium text-slate-800">Extensión {{ $req['extension'] }}</span>
                            <span class="block text-xs text-slate-500">{{ $req['motivo'] }}</span>
                        </span>
                        <span class="bmos-badge {{ $req['ok'] ? 'badge-green' : 'badge-red' }}">{{ $req['ok'] ? 'Disponible' : 'Falta' }}</span>
                    </li>
                @endforeach

                @php($esquemasOk = collect($esquemas)->where('estado', 'ok')->count())
                <li class="flex items-start justify-between gap-3 rounded-lg border border-slate-200 p-3">
                    <span class="min-w-0">
                        <span class="block font-medium text-slate-800">Esquemas oficiales de la DGII (versión {{ config('ecf.spec.version') }})</span>
                        <span class="block text-xs text-slate-500">{{ $esquemasOk }} de {{ count($esquemas) }} intactos, tal como los publica la DGII.</span>
                    </span>
                    <span class="bmos-badge {{ $esquemasIntegros ? 'badge-green' : 'badge-red' }}">{{ $esquemasIntegros ? 'Correctos' : 'Revisar' }}</span>
                </li>
            </ul>
        </div>

        <div class="bmos-card bmos-card-pad">
            <p class="font-semibold text-slate-800">Tipos de comprobante</p>
            <p class="mt-1 text-xs text-slate-500">Los e-CF que define la DGII. Se habilitarán por fases.</p>

            <ul class="mt-4 grid grid-cols-1 gap-2 text-sm sm:grid-cols-2">
                @foreach ($tipos as $tipo)
                    <li class="rounded-lg border border-slate-200 p-3">
                        <span class="font-medium text-slate-800">{{ $tipo->prefix() }}</span>
                        <span class="text-slate-600">· {{ $tipo->label() }}</span>
                    </li>
                @endforeach
            </ul>
        </div>

        @if ($pendientes !== [])
            <div class="bmos-card bmos-card-pad">
                <p class="font-semibold text-slate-800">Pendiente de confirmar con la DGII</p>
                <p class="mt-1 text-xs text-slate-500">
                    La documentación oficial no lo aclara. Lo que dependa de esto no se implementa por suposición.
                </p>
                <ul class="mt-3 list-disc space-y-1 pl-5 text-sm text-slate-600">
                    @foreach ($pendientes as $pendiente)
                        <li>{{ $pendiente }}</li>
                    @endforeach
                </ul>
            </div>
        @endif
        </div>
    </div>
</x-layouts.admin>
