<x-layouts.admin title="Alquiler {{ $rental->code }}" heading="Alquiler {{ $rental->code }}" subheading="{{ $rental->vehicle?->nombre() }} — {{ $rental->customer?->name }}">
    <div class="space-y-5">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <a href="{{ route('panel.rentals') }}" class="text-sm text-indigo-600 hover:underline">&larr; Volver a Alquiler</a>
            <div class="flex items-center gap-2">
                <a href="{{ route('panel.rentals.contract', $rental) }}" target="_blank" class="bmos-btn bmos-btn-ghost">
                    <x-icono name="doc" class="h-4 w-4" />
                    Contrato PDF
                </a>
                <span class="bmos-badge {{ $rental->status->badgeClass() }}">{{ $rental->status->label() }}</span>
            </div>
        </div>

        @can('vehicle_rentals.manage')
            <div class="bmos-card bmos-card-pad flex flex-wrap items-center gap-2">
                @if ($rental->status->value === 'pending')
                    <form method="POST" action="{{ route('panel.rentals.confirm', $rental) }}" class="inline">
                        @csrf
                        <button class="bmos-btn bmos-btn-primary">Confirmar reserva</button>
                    </form>
                @endif

                @if (in_array($rental->status->value, ['pending', 'confirmed'], true))
                    <x-panel.create-modal title="Entregar vehículo" label="Entregar vehículo" form="entrega"
                                           enctype="multipart/form-data" width="max-w-2xl"
                                           action="{{ route('panel.rentals.pickup', $rental) }}">
                        @include('panel.rentals.partials.inspection-fields', ['prefijo' => 'entrega'])
                    </x-panel.create-modal>

                    <x-panel.confirm-action
                        :action="route('panel.rentals.cancel', $rental)"
                        method="POST" tone="neutral" confirm="Cancelar reserva"
                        title="¿Cancelar el alquiler {{ $rental->code }}?"
                        message="La fecha queda libre para otra reserva."
                        class="bmos-btn bmos-btn-ghost">
                        Cancelar reserva
                    </x-panel.confirm-action>
                @endif

                @if ($rental->status->value === 'active')
                    <x-panel.create-modal title="Devolver vehículo" label="Devolver vehículo" form="devolucion"
                                           enctype="multipart/form-data" width="max-w-2xl"
                                           action="{{ route('panel.rentals.return', $rental) }}">
                        @include('panel.rentals.partials.inspection-fields', ['prefijo' => 'devolucion'])
                        <p class="mt-3 text-xs text-slate-400">
                            Si el cliente devuelve el vehículo con daños, guarda la devolución primero y anótalos después, aquí abajo en «Daños».
                        </p>
                    </x-panel.create-modal>
                @endif

                @if ($rental->status->value === 'returned')
                    <form method="POST" action="{{ route('panel.rentals.settle', $rental) }}" class="inline">
                        @csrf
                        <button class="bmos-btn bmos-btn-primary">Liquidar y cerrar</button>
                    </form>
                @endif
            </div>
        @endcan

        <div class="grid grid-cols-1 gap-5 lg:grid-cols-3">
            <div class="bmos-card bmos-card-pad lg:col-span-2">
                <p class="mb-3 font-semibold text-slate-800">Detalle</p>
                <dl class="grid grid-cols-2 gap-x-4 gap-y-3 text-sm sm:grid-cols-3">
                    <div><dt class="text-xs uppercase text-slate-400">Vehículo</dt><dd>{{ $rental->vehicle?->code }} — {{ $rental->vehicle?->nombre() }}</dd></div>
                    <div><dt class="text-xs uppercase text-slate-400">Cliente</dt><dd>{{ $rental->customer?->name }}</dd></div>
                    <div><dt class="text-xs uppercase text-slate-400">Días</dt><dd>{{ $rental->days }}</dd></div>
                    <div><dt class="text-xs uppercase text-slate-400">Recogida pactada</dt><dd>{{ $rental->start_at?->format('d/m/Y H:i') }}</dd></div>
                    <div><dt class="text-xs uppercase text-slate-400">Devolución pactada</dt><dd>{{ $rental->end_at?->format('d/m/Y H:i') }}</dd></div>
                    <div><dt class="text-xs uppercase text-slate-400">Tarifa diaria</dt><dd>{{ money($rental->daily_rate) }}</dd></div>
                    <div><dt class="text-xs uppercase text-slate-400">Entregado</dt><dd>{{ $rental->actual_pickup_at?->format('d/m/Y H:i') ?? '—' }}</dd></div>
                    <div><dt class="text-xs uppercase text-slate-400">Devuelto</dt><dd>{{ $rental->actual_return_at?->format('d/m/Y H:i') ?? '—' }}</dd></div>
                    <div><dt class="text-xs uppercase text-slate-400">Km recorridos</dt><dd>{{ $rental->kilometersUsed() ?? '—' }}</dd></div>
                </dl>

                <hr class="my-4 border-slate-100">

                <dl class="space-y-1.5 text-sm">
                    <div class="flex justify-between"><dt class="text-slate-500">Subtotal</dt><dd>{{ money($rental->subtotal) }}</dd></div>
                    @if ($rental->discount > 0)
                        <div class="flex justify-between"><dt class="text-slate-500">Descuento</dt><dd>-{{ money($rental->discount) }}</dd></div>
                    @endif
                    @if ($rental->extra_km_charge > 0)
                        <div class="flex justify-between"><dt class="text-slate-500">Kilómetros de más</dt><dd>{{ money($rental->extra_km_charge) }}</dd></div>
                    @endif
                    @if ($rental->damage_charge > 0)
                        <div class="flex justify-between"><dt class="text-slate-500">Daños cobrados</dt><dd>{{ money($rental->damage_charge) }}</dd></div>
                    @endif
                    <div class="flex justify-between font-semibold text-slate-800"><dt>Total</dt><dd>{{ money($rental->total) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-slate-500">Depósito</dt><dd>{{ money($rental->deposit_amount) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-slate-500">Pagado</dt><dd>{{ money($rental->paid()) }}</dd></div>
                    <div class="flex justify-between font-semibold {{ $rental->balance > 0 ? 'text-amber-600' : 'text-emerald-600' }}"><dt>Saldo</dt><dd>{{ money($rental->balance) }}</dd></div>
                </dl>

                @if ($rental->notes)
                    <p class="mt-4 rounded-lg bg-slate-50 p-3 text-sm text-slate-600">{{ $rental->notes }}</p>
                @endif
            </div>

            <div class="bmos-card bmos-card-pad">
                <p class="mb-3 font-semibold text-slate-800">Abonos y cargos</p>

                @can('vehicle_rentals.manage')
                    @if ($rental->status->admitePago())
                        <form method="POST" action="{{ route('panel.rentals.payments.store', $rental) }}" class="mb-4 space-y-2">
                            @csrf
                            <x-panel.field name="amount" label="Monto" type="number" step="0.01" required />
                            <div class="grid grid-cols-2 gap-2">
                                <select name="method" class="bmos-input">
                                    <option value="cash">Efectivo</option>
                                    <option value="transfer">Transferencia</option>
                                    <option value="card">Tarjeta</option>
                                    <option value="check">Cheque</option>
                                </select>
                                <select name="kind" class="bmos-input">
                                    <option value="rental">Alquiler</option>
                                    <option value="deposit">Depósito</option>
                                    <option value="extra_km">Kilómetros de más</option>
                                    <option value="damage">Daño</option>
                                    <option value="fuel">Combustible</option>
                                    <option value="other">Otro</option>
                                </select>
                            </div>
                            <button type="submit" class="bmos-btn bmos-btn-primary w-full">Registrar abono</button>
                        </form>
                    @endif
                @endcan

                @forelse ($rental->payments as $pago)
                    <div class="flex items-center justify-between border-b border-slate-50 py-2 text-sm last:border-0">
                        <div>
                            <span class="font-medium text-slate-700">{{ money($pago->amount) }}</span>
                            <span class="block text-xs text-slate-400">{{ $pago->kind?->label() }} · {{ $pago->paid_at?->format('d/m/Y H:i') }}</span>
                        </div>
                    </div>
                @empty
                    <p class="text-sm text-slate-400">Sin abonos todavía.</p>
                @endforelse
            </div>
        </div>

        <div class="grid grid-cols-1 gap-5 lg:grid-cols-2">
            <div class="bmos-card bmos-card-pad">
                <p class="mb-3 font-semibold text-slate-800">Inspecciones</p>
                @forelse ($rental->inspections as $inspeccion)
                    <div class="mb-3 rounded-lg border border-slate-100 p-3 text-sm last:mb-0">
                        <p class="font-medium text-slate-700">{{ $inspeccion->type->label() }} — {{ $inspeccion->occurred_at?->format('d/m/Y H:i') }}</p>
                        <p class="text-slate-500">Km: {{ number_format((float) $inspeccion->mileage) }} · Combustible: {{ $inspeccion->fuel_level?->label() }}</p>
                        @if ($inspeccion->observations)
                            <p class="mt-1 text-slate-500">{{ $inspeccion->observations }}</p>
                        @endif
                        @if ($inspeccion->photos->isNotEmpty())
                            <div class="mt-2 flex flex-wrap gap-2">
                                @foreach ($inspeccion->photos as $foto)
                                    <span class="grid h-14 w-14 place-items-center overflow-hidden rounded-md bg-slate-100 text-xs text-slate-400">foto</span>
                                @endforeach
                            </div>
                        @endif
                    </div>
                @empty
                    <p class="text-sm text-slate-400">Todavía no hay inspecciones.</p>
                @endforelse
            </div>

            <div class="bmos-card bmos-card-pad">
                <p class="mb-3 font-semibold text-slate-800">Daños</p>

                @can('vehicle_rentals.manage')
                    <form method="POST" action="{{ route('panel.rentals.damages.store', $rental) }}" class="mb-4 grid grid-cols-2 gap-2">
                        @csrf
                        <select name="category" required class="bmos-input col-span-2">
                            <option value="">Tipo de daño…</option>
                            <option value="scratch">Rayón</option>
                            <option value="dent">Golpe</option>
                            <option value="glass">Vidrio</option>
                            <option value="tire">Neumático</option>
                            <option value="interior">Interior</option>
                            <option value="paint">Pintura</option>
                            <option value="mechanical">Mecánica</option>
                            <option value="other">Otro</option>
                        </select>
                        <input type="text" name="description" required placeholder="Descripción" class="bmos-input col-span-2">
                        <input type="number" name="amount" step="0.01" placeholder="Monto" class="bmos-input">
                        <input type="text" name="responsible" placeholder="Responsable" class="bmos-input">
                        <button type="submit" class="bmos-btn bmos-btn-ghost col-span-2">Anotar daño</button>
                    </form>
                @endcan

                @forelse ($rental->damages as $dano)
                    <div class="mb-2 flex items-center justify-between border-b border-slate-50 pb-2 text-sm last:border-0">
                        <div>
                            <span class="font-medium text-slate-700">{{ $dano->category->label() }}</span>
                            <span class="block text-xs text-slate-400">{{ $dano->description }} @if ($dano->amount) · {{ money($dano->amount) }} @endif</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="bmos-badge {{ $dano->status->badgeClass() }}">{{ $dano->status->label() }}</span>
                            @can('vehicle_rentals.manage')
                                @if ($dano->status->value === 'pending')
                                    <form method="POST" action="{{ route('panel.rentals.damages.charge', $dano) }}">
                                        @csrf
                                        <button class="text-xs text-indigo-600 hover:underline">Cobrar</button>
                                    </form>
                                    <form method="POST" action="{{ route('panel.rentals.damages.waive', $dano) }}">
                                        @csrf
                                        <button class="text-xs text-slate-500 hover:underline">Condonar</button>
                                    </form>
                                @endif
                            @endcan
                        </div>
                    </div>
                @empty
                    <p class="text-sm text-slate-400">Sin daños registrados.</p>
                @endforelse
            </div>
        </div>
    </div>
</x-layouts.admin>
