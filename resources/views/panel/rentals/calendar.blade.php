<x-layouts.admin title="Calendario de alquiler" heading="Calendario de alquiler" subheading="Reservas, alquileres activos y mantenimiento programado">
    <div x-data="calendarioAlquiler()" x-init="init()">
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <a href="{{ route('panel.rentals') }}" class="text-sm text-indigo-600 hover:underline">&larr; Volver a Alquiler</a>

            <div class="flex flex-wrap items-center gap-2">
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

                <input type="number" x-model="customerId" @change="recargar()" placeholder="Código de cliente"
                       class="bmos-input" style="max-width:10rem">
            </div>
        </div>

        <div class="mb-4 flex flex-wrap items-center gap-4 text-xs text-slate-500">
            <span class="flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-full" style="background:#f59e0b"></span>Reservado</span>
            <span class="flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-full" style="background:#3b82f6"></span>Confirmado</span>
            <span class="flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-full" style="background:#8b5cf6"></span>En curso</span>
            <span class="flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-full" style="background:#64748b"></span>Devuelto</span>
            <span class="flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-full" style="background:#dc2626"></span>Mantenimiento</span>
        </div>

        <div class="bmos-card bmos-card-pad">
            <div x-show="!listo" class="p-10 text-center text-sm text-slate-400">Cargando calendario…</div>
            <div x-show="listo" x-cloak x-ref="calendario"></div>
        </div>
    </div>

    <script>
        function calendarioAlquiler() {
            return {
                listo: false,
                calendario: null,
                vehicleId: '',
                estado: '',
                customerId: '',

                async init() {
                    const { Calendar, dayGridPlugin, interactionPlugin, esLocale } = await window.loadRentalCalendar();

                    this.calendario = new Calendar(this.$refs.calendario, {
                        plugins: [dayGridPlugin, interactionPlugin],
                        initialView: 'dayGridMonth',
                        locale: esLocale,
                        height: 'auto',
                        headerToolbar: { left: 'prev,next today', center: 'title', right: '' },
                        events: (info, exito, fallo) => this.pedir(info, exito, fallo),
                    });

                    this.calendario.render();
                    this.listo = true;
                },

                async pedir(info, exito, fallo) {
                    const params = new URLSearchParams({
                        start: info.startStr.slice(0, 10),
                        end: info.endStr.slice(0, 10),
                    });
                    if (this.vehicleId) params.set('vehicle_id', this.vehicleId);
                    if (this.estado) params.set('estado', this.estado);
                    if (this.customerId) params.set('customer_id', this.customerId);

                    try {
                        const r = await fetch('{{ route('panel.rentals.calendar.data') }}?' + params.toString(), {
                            headers: { Accept: 'application/json' },
                        });
                        exito(await r.json());
                    } catch (e) {
                        fallo(e);
                    }
                },

                recargar() {
                    if (this.calendario) this.calendario.refetchEvents();
                },
            };
        }
    </script>
</x-layouts.admin>
