{{--
    El modal «Nueva reserva», compartido entre la lista y el calendario de Alquiler. Espera
    `$vehiculos` en el scope de quien lo incluye (las dos pantallas ya lo pasan a su vista).
--}}
@can('vehicle_rentals.manage')
    <x-panel.create-modal title="Nueva reserva" label="Nueva reserva" form="reserva"
                           action="{{ route('panel.rentals.store') }}">
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div class="sm:col-span-2">
                <label class="bmos-field-label">Vehículo <span class="text-rose-500">&nbsp;*</span></label>
                <select name="vehicle_id" required class="bmos-input">
                    <option value="">Elige la unidad…</option>
                    @foreach ($vehiculos as $v)
                        <option value="{{ $v->id }}" @selected(old('vehicle_id') == $v->id)>
                            {{ $v->code }} — {{ $v->make }} {{ $v->model }} {{ $v->year }}
                            @if ($v->rental_price_daily)
                                ({{ money($v->rental_price_daily) }}/día)
                            @endif
                        </option>
                    @endforeach
                </select>
                @if ($vehiculos->isEmpty())
                    <p class="mt-1 text-xs text-amber-600">
                        No hay unidades marcadas para alquiler. Márcalas desde la ficha del vehículo, en Vehículos → Patio.
                    </p>
                @endif
            </div>

            <div class="sm:col-span-2">
                <label class="bmos-field-label">Cliente <span class="text-rose-500">&nbsp;*</span></label>
                <input type="number" name="customer_id" required class="bmos-input"
                       value="{{ old('customer_id') }}" placeholder="Código del cliente en el CRM">
            </div>

            <x-panel.field name="start_at" label="Recogida" type="datetime-local" required />
            <x-panel.field name="end_at" label="Devolución" type="datetime-local" required />
            <x-panel.field name="discount" label="Descuento" type="number" step="0.01" />
            <x-panel.field name="deposit_amount" label="Depósito (opcional, si no el del vehículo)" type="number" step="0.01" />
        </div>

        <div class="mt-4">
            <label class="bmos-field-label">Notas</label>
            <textarea name="notes" rows="2" class="bmos-input">{{ old('notes') }}</textarea>
        </div>

        <label class="mt-3 flex items-center gap-2 text-sm text-slate-600">
            <input type="checkbox" name="confirm" value="1" class="rounded border-slate-300">
            Confirmar de una vez (el cliente ya validó la reserva)
        </label>
    </x-panel.create-modal>
@endcan
