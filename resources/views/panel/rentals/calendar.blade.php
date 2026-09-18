<x-layouts.admin title="Calendario de alquiler" heading="Calendario de alquiler" subheading="Reservas, alquileres activos y mantenimiento programado">
    <div x-data="calendarioAlquiler()" x-init="init()" class="relative">
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <a href="{{ route('panel.rentals') }}" class="text-sm text-indigo-600 hover:underline">&larr; Volver a Alquiler</a>

            <div class="flex flex-wrap items-center gap-2">
                <div class="relative">
                    <input type="search" x-model="search" @input.debounce.400ms="recargar()"
                           placeholder="Cliente, teléfono, placa, código…" class="bmos-input" style="min-width:15rem">
                </div>

                <select x-model="vehicleId" @change="recargar()" class="bmos-input">
                    <option value="">Todos los vehículos</option>
                    @foreach ($vehiculos as $v)
                        <option value="{{ $v->id }}">{{ $v->code }} — {{ $v->make }} {{ $v->model }}</option>
                    @endforeach
                </select>

                <select x-model="estado" @change="recargar()" class="bmos-input">
                    <option value="">Reservas y alquileres activos</option>
                    @foreach ($estados as $e)
                        <option value="{{ $e->value }}">{{ $e->label() }}</option>
                    @endforeach
                </select>

                @include('panel.rentals.partials.new-rental-modal')
            </div>
        </div>

        {{-- Resumen: cuántos vehículos de la flota de alquiler están libres, reservados, fuera o en
             el taller AHORA MISMO. Se recarga junto con el calendario, nunca son cifras fijas. --}}
        <div class="mb-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
            <div class="bmos-stat">
                <div class="bmos-stat-icon tone-emerald"><x-icono name="truck" /></div>
                <p class="bmos-stat-label">Disponibles</p>
                <p class="bmos-stat-value" x-text="stats.disponibles"></p>
            </div>
            <div class="bmos-stat">
                <div class="bmos-stat-icon tone-amber"><x-icono name="reloj" /></div>
                <p class="bmos-stat-label">Reservados</p>
                <p class="bmos-stat-value" x-text="stats.reservados"></p>
            </div>
            <div class="bmos-stat">
                <div class="bmos-stat-icon tone-violet"><x-icono name="pulse" /></div>
                <p class="bmos-stat-label">En curso</p>
                <p class="bmos-stat-value" x-text="stats.en_curso"></p>
            </div>
            <div class="bmos-stat">
                <div class="bmos-stat-icon tone-rose"><x-icono name="wrench" /></div>
                <p class="bmos-stat-label">Mantenimiento</p>
                <p class="bmos-stat-value" x-text="stats.mantenimiento"></p>
            </div>
        </div>

        {{-- Pestañas de vista. «Vehículos» no es FullCalendar: es una franja por unidad construida a
             mano (ver más abajo) — la alternativa gratuita a «Resource Timeline», que es de pago. --}}
        <div class="mb-4 flex flex-wrap items-center gap-1 rounded-xl bg-slate-100 p-1" style="width:fit-content">
            <template x-for="v in vistas" :key="v.id">
                <button type="button" @click="cambiarVista(v.id)"
                        class="rounded-lg px-3 py-1.5 text-sm font-medium transition"
                        :class="vista === v.id ? 'bg-white text-indigo-600 shadow-sm' : 'text-slate-500 hover:text-slate-700'"
                        x-text="v.label"></button>
            </template>
        </div>

        <div class="mb-4 flex flex-wrap items-center gap-4 text-xs text-slate-500">
            <span class="flex items-center gap-1.5">🟡 Reservado</span>
            <span class="flex items-center gap-1.5">🔵 Confirmado</span>
            <span class="flex items-center gap-1.5">🟣 En curso</span>
            <span class="flex items-center gap-1.5">⚫ Devuelto</span>
            <span class="flex items-center gap-1.5">🔴 Mantenimiento</span>
        </div>

        {{-- FullCalendar: mes, semana, día y lista. --}}
        <div x-show="vista !== 'flota'" class="bmos-card bmos-card-pad">
            <div x-show="!listo" class="p-10 text-center text-sm text-slate-400">Cargando calendario…</div>
            <div x-show="listo" x-cloak x-ref="calendario"></div>
        </div>

        {{-- Vista «Vehículos»: una franja por unidad con sus alquileres/mantenimientos del rango, en
             plan Gantt sencillo — responde de un vistazo qué está ocupado y qué está libre. --}}
        <div x-show="vista === 'flota'" x-cloak class="bmos-card bmos-card-pad">
            <div class="mb-3 flex items-center justify-between">
                <div class="flex items-center gap-2">
                    <button type="button" @click="moverFlota(-7)" class="bmos-btn bmos-btn-ghost">&larr;</button>
                    <button type="button" @click="irAHoyFlota()" class="bmos-btn bmos-btn-ghost">Hoy</button>
                    <button type="button" @click="moverFlota(7)" class="bmos-btn bmos-btn-ghost">&rarr;</button>
                </div>
                <p class="text-sm text-slate-500" x-text="flota.start && flota.end ? (flota.start + ' — ' + flota.end) : ''"></p>
            </div>

            <div x-show="flota.cargando" class="p-10 text-center text-sm text-slate-400">Cargando flota…</div>

            <div x-show="!flota.cargando && flota.vehiculos.length === 0" class="p-10 text-center text-sm text-slate-400">
                No hay vehículos que coincidan con el filtro.
            </div>

            <div x-show="!flota.cargando" class="space-y-4 overflow-x-auto">
                <template x-for="v in flota.vehiculos" :key="v.id">
                    <div class="min-w-[36rem]">
                        <div class="mb-1 flex items-center gap-2">
                            <span class="font-medium text-slate-700" x-text="v.nombre"></span>
                            <span class="bmos-badge"
                                  :class="{
                                      disponible: 'badge-green', ocupado: 'badge-violet',
                                      mantenimiento: 'badge-red', no_disponible: 'badge-gray',
                                  }[v.disponibilidad]"
                                  x-text="{
                                      disponible: 'DISPONIBLE', ocupado: 'OCUPADO',
                                      mantenimiento: 'MANTENIMIENTO', no_disponible: 'NO DISPONIBLE',
                                  }[v.disponibilidad]"></span>
                        </div>
                        <div class="relative h-9 rounded-lg bg-slate-100">
                            <template x-for="s in v.segmentos" :key="s.start + s.label">
                                <div class="absolute top-0.5 h-8 cursor-pointer rounded-md px-2 text-xs font-medium leading-8 text-white"
                                     :style="segmentoEstilo(s)"
                                     :title="s.label"
                                     @click="s.url ? (window.location.href = s.url) : null">
                                    <span class="truncate" x-text="s.label"></span>
                                </div>
                            </template>
                        </div>
                    </div>
                </template>
            </div>
        </div>

        {{-- Tooltip flotante al pasar el ratón por un evento. --}}
        <div x-show="tooltip.visible" x-cloak
             class="pointer-events-none fixed z-50 w-64 rounded-xl border border-slate-200 bg-white p-3 text-xs shadow-xl"
             :style="'left:'+tooltip.x+'px; top:'+tooltip.y+'px'">
            <template x-if="tooltip.data">
                <div class="space-y-1">
                    <p class="font-semibold text-slate-800" x-text="tooltip.data.statusIcon + ' ' + tooltip.data.statusLabel"></p>
                    <template x-if="tooltip.data.kind === 'rental'">
                        <div class="space-y-1 text-slate-600">
                            <p><b>Vehículo:</b> <span x-text="tooltip.data.vehicle"></span></p>
                            <p><b>Cliente:</b> <span x-text="tooltip.data.customer"></span></p>
                            <p x-show="tooltip.data.phone"><b>Teléfono:</b> <span x-text="tooltip.data.phone"></span></p>
                            <p><b>Inicio:</b> <span x-text="tooltip.data.startLabel"></span></p>
                            <p><b>Devolución:</b> <span x-text="tooltip.data.endLabel"></span></p>
                            <p><b>Total:</b> <span x-text="tooltip.data.totalLabel"></span></p>
                        </div>
                    </template>
                    <template x-if="tooltip.data.kind === 'maintenance'">
                        <p class="text-slate-600" x-text="tooltip.data.vehicle"></p>
                    </template>
                </div>
            </template>
        </div>

        {{-- Modal al hacer clic en un alquiler. --}}
        <div x-show="modal.open" x-cloak @keydown.escape.window="modal.open = false"
             class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4">
            <div @click.outside="modal.open = false" x-transition class="w-full max-w-md rounded-2xl bg-white p-6 shadow-2xl">
                <template x-if="modal.data">
                    <div>
                        <div class="mb-4 flex items-start justify-between">
                            <div>
                                <p class="text-xs font-semibold uppercase tracking-wide text-slate-400" x-text="modal.data.code"></p>
                                <h3 class="text-lg font-semibold text-slate-800" x-text="modal.data.vehicle"></h3>
                            </div>
                            <span class="bmos-badge" :class="badgeClase(modal.data.status)"
                                  x-text="modal.data.statusIcon + ' ' + modal.data.statusLabel"></span>
                        </div>

                        <dl class="space-y-2 text-sm">
                            <div class="flex justify-between"><dt class="text-slate-500">Cliente</dt><dd x-text="modal.data.customer"></dd></div>
                            <div class="flex justify-between" x-show="modal.data.phone"><dt class="text-slate-500">Teléfono</dt><dd x-text="modal.data.phone"></dd></div>
                            <div class="flex justify-between"><dt class="text-slate-500">Recogida</dt><dd x-text="modal.data.startLabel"></dd></div>
                            <div class="flex justify-between"><dt class="text-slate-500">Devolución</dt><dd x-text="modal.data.endLabel"></dd></div>
                            <div class="flex justify-between font-semibold"><dt>Total</dt><dd x-text="modal.data.totalLabel"></dd></div>
                            <div class="flex justify-between"><dt class="text-slate-500">Saldo</dt><dd x-text="modal.data.balanceLabel"></dd></div>
                        </dl>

                        <div class="mt-5 flex flex-wrap gap-2">
                            <a :href="modal.data.showUrl" class="bmos-btn bmos-btn-primary">Ver ficha completa</a>

                            @can('vehicle_rentals.manage')
                                <template x-if="modal.data.status === 'pending'">
                                    <button type="button" class="bmos-btn bmos-btn-suave" @click="accionRapida('confirmar')">Confirmar</button>
                                </template>
                                <template x-if="['pending', 'confirmed'].includes(modal.data.status)">
                                    <button type="button" class="bmos-btn bmos-btn-ghost" @click="accionRapida('cancelar')">Cancelar</button>
                                </template>
                                <template x-if="modal.data.status === 'returned'">
                                    <button type="button" class="bmos-btn bmos-btn-suave" @click="accionRapida('liquidar')">Liquidar</button>
                                </template>
                            @endcan
                        </div>

                        {{-- Entregar, devolver, registrar pago y demás piden fotos, checklist y
                             firma: ese formulario ya existe en la ficha completa y no se duplica
                             aquí — un enlace directo es mejor que dos copias de lo mismo. --}}
                        <p class="mt-3 text-xs text-slate-400">
                            Entregar, devolver, registrar pagos y anotar daños se hacen desde la ficha completa.
                        </p>

                        <div class="mt-4 border-t border-slate-100 pt-3">
                            <button type="button" @click="modal.open = false" class="bmos-btn bmos-btn-ghost w-full">Cerrar</button>
                        </div>
                    </div>
                </template>
            </div>
        </div>
    </div>

    <script>
        function calendarioAlquiler() {
            return {
                listo: false,
                calendario: null,
                vehicleId: '',
                estado: '',
                search: '',
                vista: 'dayGridMonth',
                vistas: [
                    { id: 'dayGridMonth', label: 'Mes' },
                    { id: 'timeGridWeek', label: 'Semana' },
                    { id: 'timeGridDay', label: 'Día' },
                    { id: 'listWeek', label: 'Lista' },
                    { id: 'flota', label: 'Vehículos' },
                ],
                stats: { disponibles: 0, reservados: 0, en_curso: 0, mantenimiento: 0 },
                tooltip: { visible: false, x: 0, y: 0, data: null },
                modal: { open: false, data: null },
                flota: { cargando: false, vehiculos: [], start: '', end: '', desde: null },

                async init() {
                    // En pantallas angostas, la lista es más legible que un mes apretado en columnas.
                    if (window.innerWidth < 640) this.vista = 'listWeek';

                    const { Calendar, dayGridPlugin, timeGridPlugin, listPlugin, interactionPlugin, esLocale } = await window.loadRentalCalendar();

                    this.calendario = new Calendar(this.$refs.calendario, {
                        plugins: [dayGridPlugin, timeGridPlugin, listPlugin, interactionPlugin],
                        initialView: this.vista,
                        locale: esLocale,
                        height: 'auto',
                        editable: true,
                        eventStartEditable: true,
                        eventDurationEditable: true,
                        headerToolbar: { left: 'prev,next today', center: 'title', right: '' },
                        events: (info, exito, fallo) => this.pedirEventos(info, exito, fallo),
                        eventContent: (arg) => this.pintarEvento(arg),
                        eventClick: (info) => this.alHacerClick(info),
                        eventMouseEnter: (info) => this.mostrarTooltip(info),
                        eventMouseLeave: () => this.ocultarTooltip(),
                        eventDrop: (info) => this.alArrastrar(info),
                        eventResize: (info) => this.alRedimensionar(info),
                    });

                    this.calendario.render();
                    this.listo = true;

                    this.cargarResumen();
                },

                async pedirEventos(info, exito, fallo) {
                    const params = this.parametrosComunes();
                    params.set('start', info.startStr.slice(0, 10));
                    params.set('end', info.endStr.slice(0, 10));

                    try {
                        const r = await fetch('{{ route('panel.rentals.calendar.data') }}?' + params.toString(), {
                            headers: { Accept: 'application/json' },
                        });
                        exito(await r.json());
                    } catch (e) {
                        fallo(e);
                    }
                },

                parametrosComunes() {
                    const params = new URLSearchParams();
                    if (this.vehicleId) params.set('vehicle_id', this.vehicleId);
                    if (this.estado) params.set('estado', this.estado);
                    if (this.search) params.set('search', this.search);
                    return params;
                },

                async cargarResumen() {
                    const r = await fetch('{{ route('panel.rentals.calendar.summary') }}', { headers: { Accept: 'application/json' } });
                    this.stats = await r.json();
                },

                recargar() {
                    if (this.vista === 'flota') { this.cargarFlota(); return; }
                    if (this.calendario) this.calendario.refetchEvents();
                    this.cargarResumen();
                },

                cambiarVista(id) {
                    this.vista = id;
                    if (id === 'flota') {
                        this.cargarFlota();
                        return;
                    }
                    if (this.calendario) this.calendario.changeView(id);
                },

                // ---------------------------------------------------------------- Vista «Vehículos»

                async cargarFlota() {
                    this.flota.cargando = true;
                    const inicio = this.flota.desde ? new Date(this.flota.desde) : new Date();
                    inicio.setHours(0, 0, 0, 0);
                    const fin = new Date(inicio);
                    fin.setDate(fin.getDate() + 14);

                    const params = this.parametrosComunes();
                    params.set('start', inicio.toISOString().slice(0, 10));
                    params.set('end', fin.toISOString().slice(0, 10));

                    const r = await fetch('{{ route('panel.rentals.calendar.fleet') }}?' + params.toString(), { headers: { Accept: 'application/json' } });
                    const datos = await r.json();

                    this.flota.vehiculos = datos.vehiculos;
                    this.flota.start = new Date(datos.start + 'T00:00:00').toLocaleDateString('es-DO', { day: 'numeric', month: 'short' });
                    this.flota.end = new Date(datos.end + 'T00:00:00').toLocaleDateString('es-DO', { day: 'numeric', month: 'short' });
                    this.flota._inicioMs = inicio.getTime();
                    this.flota._finMs = fin.getTime();
                    this.flota.desde = inicio.toISOString();
                    this.flota.cargando = false;
                },

                moverFlota(dias) {
                    const base = this.flota.desde ? new Date(this.flota.desde) : new Date();
                    base.setDate(base.getDate() + dias);
                    this.flota.desde = base.toISOString();
                    this.cargarFlota();
                },

                irAHoyFlota() {
                    this.flota.desde = null;
                    this.cargarFlota();
                },

                /** La posición y el ancho de la barra, en % del rango visible de la franja. */
                segmentoEstilo(s) {
                    const total = this.flota._finMs - this.flota._inicioMs;
                    if (!total) return 'left:0;width:0';

                    const inicio = Math.max(new Date(s.start).getTime(), this.flota._inicioMs);
                    const fin = Math.min(new Date(s.end).getTime(), this.flota._finMs);

                    const izquierda = Math.max(0, ((inicio - this.flota._inicioMs) / total) * 100);
                    const ancho = Math.max(2, ((fin - inicio) / total) * 100);

                    return `left:${izquierda}%; width:${ancho}%; background:${s.color}`;
                },

                // ---------------------------------------------------------------- Presentación de eventos

                pintarEvento(arg) {
                    const p = arg.event.extendedProps;
                    const icono = p.statusIcon || '';

                    // `flex-direction:column` y no `<br>`: el marco del evento de FullCalendar es
                    // flex por defecto, y un `<br>` entre dos `<span>` flex no salta de línea — las
                    // dos quedaban pegadas en una sola fila apretada.
                    if (p.kind === 'maintenance') {
                        return { html: `<div class="fc-event-main-frame" style="flex-direction:column;align-items:flex-start;line-height:1.25">`
                            + `<div>${icono} 🔧 ${this.escapar(arg.event.title)}</div></div>` };
                    }

                    const total = p.total ? this.pesos(p.total) : '';
                    return {
                        html: `<div class="fc-event-main-frame" style="flex-direction:column;align-items:flex-start;line-height:1.25">
                            <div>${icono} ${this.escapar(p.vehicle || '')}</div>
                            <div style="opacity:.9">${this.escapar(p.customer || '')} · ${total}</div>
                        </div>`,
                    };
                },

                escapar(texto) {
                    const div = document.createElement('div');
                    div.textContent = texto;
                    return div.innerHTML;
                },

                pesos(n) {
                    return '{{ trim(currency_symbol()) }} ' + Number(n).toLocaleString('es-DO', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                },

                fechaHora(iso) {
                    if (!iso) return '';
                    return new Date(iso).toLocaleString('es-DO', { day: '2-digit', month: '2-digit', year: 'numeric', hour: 'numeric', minute: '2-digit' });
                },

                /**
                 * La hora LOCAL del navegador, sin pasar por UTC (`YYYY-MM-DDTHH:mm:ss`).
                 *
                 * El formulario de «Nueva reserva» manda un `datetime-local` tal cual, en hora local,
                 * y el servidor lo guarda así. Si aquí se mandara `date.toISOString()` —que SIEMPRE es
                 * UTC— un alquiler arrastrado quedaría desplazado por el huso horario del servidor
                 * respecto a como se creó la primera vez.
                 */
                horaLocal(date) {
                    const p = (n) => String(n).padStart(2, '0');
                    return `${date.getFullYear()}-${p(date.getMonth() + 1)}-${p(date.getDate())}`
                        + `T${p(date.getHours())}:${p(date.getMinutes())}:${p(date.getSeconds())}`;
                },

                // ---------------------------------------------------------------- Tooltip

                mostrarTooltip(info) {
                    const p = info.event.extendedProps;

                    this.tooltip.data = {
                        kind: p.kind,
                        statusIcon: p.statusIcon,
                        statusLabel: p.statusLabel,
                        vehicle: p.vehicle,
                        customer: p.customer,
                        phone: p.phone,
                        startLabel: this.fechaHora(info.event.startStr),
                        endLabel: this.fechaHora(info.event.endStr),
                        totalLabel: p.total ? this.pesos(p.total) : '',
                    };

                    const r = info.jsEvent.target.getBoundingClientRect();
                    this.tooltip.x = Math.min(r.left, window.innerWidth - 270);
                    this.tooltip.y = r.bottom + 6;
                    this.tooltip.visible = true;
                },

                ocultarTooltip() {
                    this.tooltip.visible = false;
                },

                // ---------------------------------------------------------------- Clic → modal

                alHacerClick(info) {
                    info.jsEvent.preventDefault();
                    const p = info.event.extendedProps;

                    if (p.kind !== 'rental') return; // el mantenimiento no abre ficha

                    this.modal.data = {
                        rentalId: p.rentalId,
                        code: p.code,
                        vehicle: p.vehicle,
                        customer: p.customer,
                        phone: p.phone,
                        status: p.status,
                        statusLabel: p.statusLabel,
                        statusIcon: p.statusIcon,
                        startLabel: this.fechaHora(info.event.startStr),
                        endLabel: this.fechaHora(info.event.endStr),
                        totalLabel: this.pesos(p.total),
                        balanceLabel: this.pesos(p.balance),
                        showUrl: p.showUrl,
                    };
                    this.modal.open = true;
                },

                badgeClase(estado) {
                    return {
                        pending: 'badge-amber', confirmed: 'badge-blue', active: 'badge-violet',
                        returned: 'badge-gray', completed: 'badge-green', cancelled: 'badge-red',
                    }[estado] || 'badge-gray';
                },

                async accionRapida(accion) {
                    const textos = {
                        confirmar: { titulo: '¿Confirmar esta reserva?', confirmar: 'Confirmar', tono: 'neutro' },
                        cancelar: { titulo: '¿Cancelar este alquiler?', mensaje: 'La fecha queda libre para otra reserva.', confirmar: 'Cancelar alquiler', tono: 'peligro' },
                        liquidar: { titulo: '¿Liquidar y cerrar este alquiler?', confirmar: 'Liquidar', tono: 'neutro' },
                    };
                    const t = textos[accion];

                    const ok = await window.confirmarAccion({
                        titulo: t.titulo, mensaje: t.mensaje || '', confirmar: t.confirmar,
                        tono: t.tono === 'peligro' ? 'peligro' : 'neutro',
                    });
                    if (!ok) return;

                    const rutas = {
                        confirmar: 'confirmar', cancelar: 'cancelar', liquidar: 'liquidar',
                    };
                    const url = '{{ url('panel/alquiler') }}/' + this.modal.data.rentalId + '/' + rutas[accion];

                    const r = await fetch(url, {
                        method: 'POST',
                        headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '' },
                    });

                    if (r.ok) {
                        window.avisoRapido?.('Listo.', 'ok');
                        this.modal.open = false;
                        this.recargar();
                    } else {
                        window.avisoRapido?.('No se pudo completar la acción.');
                    }
                },

                // ---------------------------------------------------------------- Arrastrar / redimensionar

                async alArrastrar(info) {
                    await this.confirmarYGuardarFechas(info, info.event.start, info.event.end);
                },

                async alRedimensionar(info) {
                    await this.confirmarYGuardarFechas(info, info.event.start, info.event.end);
                },

                async confirmarYGuardarFechas(info, nuevoInicio, nuevoFin) {
                    const p = info.event.extendedProps;
                    if (p.kind !== 'rental') { info.revert(); return; }

                    const ok = await window.confirmarAccion({
                        titulo: '¿Cambiar las fechas de este alquiler?',
                        mensaje: `De <b>${this.fechaHora(info.oldEvent.start)} → ${this.fechaHora(info.oldEvent.end)}</b>`
                            + ` a <b>${this.fechaHora(nuevoInicio)} → ${this.fechaHora(nuevoFin)}</b>.`,
                        confirmar: 'Cambiar fechas',
                        tono: 'neutro',
                    });

                    if (!ok) { info.revert(); return; }

                    try {
                        const r = await fetch('{{ url('panel/alquiler') }}/' + p.rentalId + '/reprogramar', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                                Accept: 'application/json',
                            },
                            body: JSON.stringify({ start_at: this.horaLocal(nuevoInicio), end_at: this.horaLocal(nuevoFin) }),
                        });

                        if (!r.ok) {
                            const cuerpo = await r.json().catch(() => null);
                            window.avisoRapido?.(cuerpo?.message || 'El vehículo no está disponible en estas fechas.');
                            info.revert();
                            return;
                        }

                        window.avisoRapido?.('Fechas actualizadas.', 'ok');
                        this.cargarResumen();
                    } catch (e) {
                        window.avisoRapido?.('No se pudo cambiar la fecha.');
                        info.revert();
                    }
                },
            };
        }
    </script>
</x-layouts.admin>
