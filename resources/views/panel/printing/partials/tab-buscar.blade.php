{{--
    Buscar impresoras: las que ya están registradas, con su estado, y el alta manual.

    NO HAY UN BOTÓN QUE «BUSQUE» SOLO. Una página no puede escanear USB ni la red por su cuenta —ver
    la nota en el plan—; lo más cerca que se puede estar es abrir el selector nativo de Bluetooth
    (pestaña aparte) o registrar la impresora a mano, que es justo lo que ofrece este alta.
--}}
<div class="bmos-card overflow-hidden">
    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 p-4">
        <p class="font-semibold text-slate-800">Impresoras registradas</p>
        @can('printing.manage')
            <div id="printer-create-wrap">
                <x-panel.create-modal title="Nueva impresora" label="Nueva impresora" form="printer_create"
                                       :action="route('panel.printing.printers.store')">
                    @include('panel.printing.partials.printer-fields', ['printer' => null])
                </x-panel.create-modal>
            </div>
        @endcan
    </div>

    @if ($printers->isEmpty())
        <p class="bmos-empty">Todavía no hay impresoras registradas.</p>
    @else
        <div class="grid gap-4 p-4 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($printers as $printer)
                <div class="rounded-xl border border-slate-200 p-4">
                    <div class="flex items-start justify-between gap-2">
                        <div class="flex items-center gap-2">
                            <span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-slate-100 text-slate-500">
                                <x-icono :name="$printer->connection_type->icon()" class="h-5 w-5" />
                            </span>
                            <div>
                                <p class="font-semibold text-slate-800">{{ $printer->name }}</p>
                                <p class="text-xs text-slate-500">
                                    {{ collect([$printer->manufacturer, $printer->model])->filter()->implode(' · ') ?: $printer->connection_type->label() }}
                                </p>
                            </div>
                        </div>
                        @if ($printer->id === $miPredeterminada?->id)
                            <span class="bmos-badge badge-blue shrink-0" title="Tu impresora predeterminada">Mía</span>
                        @endif
                    </div>

                    <div class="mt-3 flex flex-wrap items-center gap-1.5">
                        <span class="bmos-badge {{ $printer->last_status->badgeClass() }}">{{ $printer->last_status->label() }}</span>
                        <span class="bmos-badge badge-gray">{{ \App\Modules\Printing\Support\PaperSize::label($printer->paper_size) }}</span>
                        {{-- La batería es un dato EN VIVO —cambia constantemente—, así que no se guarda: solo
                             aparece tras conectar por Bluetooth en esta misma sesión (pestaña Bluetooth). --}}
                        <template x-for="d in bt.dispositivos.filter(x => x.deviceId === @js($printer->bt_device_id) && x.bateria !== null)" :key="d.deviceId">
                            <span class="bmos-badge badge-green" x-text="'🔋 ' + d.bateria + '%'"></span>
                        </template>
                    </div>

                    <div class="mt-3 flex flex-wrap gap-1.5 text-xs">
                        @if ($printer->last_status->value !== 'conectada')
                            <button type="button" @click="marcarEstado({{ $printer->id }}, 'conectada')" class="bmos-btn bmos-btn-ghost">Conectar</button>
                        @else
                            <button type="button" @click="marcarEstado({{ $printer->id }}, 'desconectada')" class="bmos-btn bmos-btn-ghost">Desconectar</button>
                        @endif
                        <button type="button" @click="marcarPredeterminada({{ $printer->id === $miPredeterminada?->id ? 'null' : $printer->id }})"
                                class="bmos-btn bmos-btn-ghost">
                            {{ $printer->id === $miPredeterminada?->id ? 'Quitar predeterminada' : 'Predeterminada' }}
                        </button>
                        <button type="button" @click="pruebaDeImpresion(@js(['id' => $printer->id, 'connection_type' => $printer->connection_type->value, 'bt_device_id' => $printer->bt_device_id]))"
                                class="bmos-btn bmos-btn-ghost">Prueba de impresión</button>
                    </div>

                    @can('printing.manage')
                        <div class="mt-3 flex justify-end gap-1 border-t border-slate-100 pt-2">
                            <x-panel.edit-modal title="Editar «{{ $printer->name }}»" :action="route('panel.printing.printers.update', $printer)"
                                                 form="printer_edit_{{ $printer->id }}" trigger="Editar">
                                @include('panel.printing.partials.printer-fields', ['printer' => $printer])
                            </x-panel.edit-modal>
                            <x-panel.confirm-action :action="route('panel.printing.printers.destroy', $printer)"
                                title="¿Retirar «{{ $printer->name }}»?"
                                message="Deja de aparecer para elegirla al imprimir."
                                note="El historial de lo que ya se imprimió con ella no se borra."
                                confirm="Retirar" tooltip="Retirar"
                                class="bmos-btn bmos-btn-ghost text-rose-600">
                                Retirar
                            </x-panel.confirm-action>
                        </div>
                    @endcan
                </div>
            @endforeach
        </div>
    @endif
</div>
