@use('App\Modules\Loans\Enums\LoanApplicationStatus')
@use('App\Modules\Loans\Enums\LoanFrequency')

@php
    // `tryFrom` y no `from`: el estado llega de la URL y basta con teclear «?estado=loquesea» para
    // reventar la pantalla con un error de enumerado. Si no vale, se trata como si no hubiera filtro.
    $filtro = $estadoActivo ? LoanApplicationStatus::tryFrom((string) $estadoActivo) : null;
@endphp

<x-layouts.admin title="Solicitudes" heading="Solicitudes de préstamo"
                 subheading="Quién pidió, con qué respaldo y qué se decidió">
    <div>
        {{-- Filtro por estado.

             Se pinta como pestañas y no como un desplegable porque el trabajo del día ES el estado:
             lo primero que se hace por la mañana es mirar qué entró sin evaluar y qué está aprobado
             esperando el dinero. Un desplegable escondería justo eso. --}}
        <div class="mb-5 flex flex-wrap items-center gap-2">
            <a href="{{ route('panel.loan-applications', ['q' => request('q')]) }}"
               class="bmos-btn {{ $estadoActivo ? 'bmos-btn-ghost' : 'bmos-btn-primary' }} text-xs">
                Todas
                @if ($porEstado->sum() > 0)
                    <span class="ml-1.5 opacity-60">{{ $porEstado->sum() }}</span>
                @endif
            </a>
            @foreach ($statuses as $estado)
                @php $cuantas = (int) ($porEstado[$estado->value] ?? 0); @endphp
                <a href="{{ route('panel.loan-applications', ['estado' => $estado->value, 'q' => request('q')]) }}"
                   class="bmos-btn text-xs {{ $estadoActivo === $estado->value ? 'bmos-btn-primary' : 'bmos-btn-ghost' }} {{ $cuantas === 0 && $estadoActivo !== $estado->value ? 'opacity-50' : '' }}">
                    {{ $estado->label() }}
                    {{-- La cifra solo cuando hay algo. Un «0» en cada pestaña es ruido; la pestaña
                         atenuada ya dice que ahí no hay nada. --}}
                    @if ($cuantas > 0)
                        <span class="ml-1.5 opacity-60">{{ $cuantas }}</span>
                    @endif
                </a>
            @endforeach
        </div>

        <div class="bmos-card overflow-hidden">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 p-4">
                <p class="font-semibold text-slate-800">Solicitudes</p>
                <div class="flex flex-wrap items-center gap-3">
                    <x-panel.search-bar placeholder="Buscar por código, cliente o cédula..." />
                    @can('loan_applications.manage')
                    <x-panel.create-modal title="Nueva solicitud" label="Nueva solicitud" form="application_create"
                                          :action="route('panel.loan-applications.store')" width="max-w-3xl">
                        <div x-data="solicitudCalc()">
                            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                                <div class="sm:col-span-2 lg:col-span-3">
                                    <div class="mb-1 flex items-center justify-between">
                                        <label class="bmos-field-label">Cliente</label>
                                        <label class="flex items-center gap-1.5 text-xs font-medium text-slate-500">
                                            <input type="checkbox" x-model="newCustomer" class="rounded border-slate-300 text-indigo-600">
                                            Cliente nuevo
                                        </label>
                                    </div>
                                    <select name="customer_id" class="bmos-input" x-show="!newCustomer" :required="!newCustomer">
                                        <option value="">— Selecciona un cliente —</option>
                                        @foreach ($customers as $c)
                                            <option value="{{ $c->id }}" @selected(old('customer_id') == $c->id)>
                                                {{ $c->name }}@if ($c->cedula) — {{ $c->cedula }}@endif
                                            </option>
                                        @endforeach
                                    </select>
                                    <div x-show="newCustomer" x-cloak class="grid grid-cols-1 gap-2 sm:grid-cols-3">
                                        <input type="text" name="new_customer_name" value="{{ old('new_customer_name') }}"
                                               placeholder="Nombre del cliente" class="bmos-input" :required="newCustomer">
                                        <input type="text" name="new_customer_cedula" value="{{ old('new_customer_cedula') }}"
                                               placeholder="Cédula (opcional)" class="bmos-input">
                                        <input type="text" name="new_customer_phone" value="{{ old('new_customer_phone') }}"
                                               placeholder="Teléfono (opcional)" class="bmos-input">
                                    </div>
                                </div>

                                <div>
                                    <label class="bmos-field-label">Capital solicitado (RD$)</label>
                                    <input type="number" step="0.01" min="0" name="principal" x-model="principal"
                                           value="{{ old('principal') }}" class="bmos-input" required>
                                </div>
                                <div>
                                    <label class="bmos-field-label">Tasa de interés (%)</label>
                                    <input type="number" step="0.01" min="0" name="interest_rate" x-model="rate"
                                           value="{{ old('interest_rate', 0) }}" class="bmos-input" placeholder="Ej: 20">
                                </div>
                                <div>
                                    <label class="bmos-field-label">Interés (monto, opcional)</label>
                                    <input type="number" step="0.01" min="0" name="interest_amount" x-model="amount"
                                           value="{{ old('interest_amount') }}" class="bmos-input" placeholder="Manda sobre la tasa">
                                </div>
                                <div>
                                    <label class="bmos-field-label">Nº de cuotas</label>
                                    <input type="number" step="1" min="1" name="installments_count" x-model="count"
                                           value="{{ old('installments_count', 1) }}" class="bmos-input" required>
                                </div>
                                <div>
                                    <label class="bmos-field-label">Frecuencia</label>
                                    <select name="frequency" class="bmos-input" required>
                                        @foreach ($frequencies as $f)
                                            <option value="{{ $f->value }}" @selected(old('frequency', LoanFrequency::Monthly->value) === $f->value)>{{ $f->label() }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div>
                                    <label class="bmos-field-label">Primer vencimiento</label>
                                    <input type="date" name="start_date" value="{{ old('start_date', now()->toDateString()) }}" class="bmos-input" required>
                                </div>
                                <div>
                                    <label class="bmos-field-label">Mora (%) opcional</label>
                                    <input type="number" step="0.01" min="0" name="late_fee_rate" value="{{ old('late_fee_rate') }}" class="bmos-input" placeholder="Ej: 5">
                                </div>
                                <div>
                                    <label class="bmos-field-label">Garantía (opcional)</label>
                                    <input type="text" name="collateral" value="{{ old('collateral') }}" class="bmos-input" placeholder="Cédula, prenda...">
                                </div>
                                <div>
                                    <label class="bmos-field-label">¿Para qué lo quiere?</label>
                                    <input type="text" name="purpose" value="{{ old('purpose') }}" class="bmos-input" placeholder="Mercancía, salud, estudios...">
                                </div>
                            </div>

                            {{-- Lo que terminaría pagando, en vivo. No se guarda: es la misma cuenta que
                                 hace el servidor, aquí solo para que quien atiende pueda decírselo al
                                 cliente sin sacar la calculadora. --}}
                            <div class="mt-4 grid grid-cols-3 gap-2 rounded-xl bg-slate-50 p-3 text-center ring-1 ring-slate-100">
                                <div>
                                    <p class="text-xs text-slate-400">Interés</p>
                                    <p class="font-bold text-slate-700" x-text="rd(interest)"></p>
                                </div>
                                <div>
                                    <p class="text-xs text-slate-400">Total a pagar</p>
                                    <p class="font-bold text-indigo-600" x-text="rd(total)"></p>
                                </div>
                                <div>
                                    <p class="text-xs text-slate-400">Cuota</p>
                                    <p class="font-bold text-slate-700" x-text="rd(installment)"></p>
                                </div>
                            </div>

                            <p class="mt-3 text-xs text-slate-500">
                                Registrar la solicitud <b>no entrega dinero</b>. La evaluación y la decisión
                                se hacen después, desde su ficha.
                            </p>
                        </div>
                    </x-panel.create-modal>
                    @endcan
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="bmos-table">
                    <thead>
                        <tr>
                            <th>Código</th><th>Cliente</th><th>Solicita</th><th>Cuotas</th>
                            <th>Frecuencia</th><th>Estado</th><th>Recibida</th><th class="text-right">Ver</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($applications as $solicitud)
                            <tr>
                                <td class="font-mono text-xs text-slate-500">
                                    <a href="{{ route('panel.loan-applications.show', $solicitud) }}" class="text-indigo-600 hover:underline">{{ $solicitud->code }}</a>
                                </td>
                                <td class="font-medium text-slate-800">{{ $solicitud->customer_name ?? $solicitud->customer?->name ?? '—' }}</td>
                                <td>
                                    {{ number_format((float) $solicitud->principal, 2) }}
                                    @if ($solicitud->seAjustaronLosTerminos())
                                        {{-- «Pidió 50.000, le aprobamos 30.000» es lo primero que se quiere ver
                                             de una solicitud decidida, así que va en el listado y no escondido
                                             en la ficha. --}}
                                        <span class="block text-xs font-semibold text-indigo-600">
                                            aprobado {{ number_format((float) $solicitud->capitalEfectivo(), 2) }}
                                        </span>
                                    @endif
                                </td>
                                <td>{{ $solicitud->cuotasEfectivas() }}</td>
                                <td>{{ $solicitud->frequency->label() }}</td>
                                <td><span class="bmos-badge {{ $solicitud->status->badge() }}">{{ $solicitud->status->label() }}</span></td>
                                <td class="text-xs text-slate-500">{{ $solicitud->created_at?->format('d/m/Y') }}</td>
                                <td>
                                    <div class="flex items-center justify-end">
                                        <a href="{{ route('panel.loan-applications.show', $solicitud) }}"
                                           class="rounded-lg p-1.5 text-slate-500 hover:bg-slate-100 hover:text-indigo-600" title="Ver solicitud">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" style="width:1.15rem;height:1.15rem"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/></svg>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="8" class="bmos-empty">
                                {{-- Tres mensajes distintos porque son tres situaciones distintas.
                                     Decir «ninguna coincide con lo que buscas» cuando no existe
                                     ninguna solicitud manda al cliente a buscar un filtro que no
                                     tiene puesto. --}}
                                @if (! $hayAlguna)
                                    Sin solicitudes todavía. Registra la primera con «Nueva solicitud».
                                @elseif (request('q'))
                                    Ninguna solicitud coincide con «{{ request('q') }}».
                                    <a href="{{ route('panel.loan-applications', ['estado' => $filtro?->value]) }}" class="text-indigo-600 hover:underline">Quitar la búsqueda</a>
                                @elseif ($filtro)
                                    Ninguna solicitud está «{{ $filtro->label() }}».
                                    <a href="{{ route('panel.loan-applications') }}" class="text-indigo-600 hover:underline">Ver todas</a>
                                @else
                                    No hay nada en esta página.
                                    <a href="{{ route('panel.loan-applications') }}" class="text-indigo-600 hover:underline">Volver al principio</a>
                                @endif
                            </td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($applications->hasPages())
                <div class="border-t border-slate-100 p-4">{{ $applications->links() }}</div>
            @endif
        </div>
    </div>

    <script>
        function solicitudCalc() {
            return {
                newCustomer: {{ old('new_customer_name') ? 'true' : 'false' }},
                principal: {{ (float) old('principal', 0) }},
                rate: {{ (float) old('interest_rate', 0) }},
                amount: '{{ old('interest_amount') }}',
                count: {{ (int) old('installments_count', 1) }},
                rd(n) { return 'RD$ ' + (parseFloat(n) || 0).toLocaleString('es-DO', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); },
                get interest() {
                    // El monto escrito a mano manda sobre la tasa, igual que en el servidor.
                    const manual = parseFloat(this.amount);
                    if (!isNaN(manual) && this.amount !== '') return manual;
                    return (parseFloat(this.principal) || 0) * (parseFloat(this.rate) || 0) / 100;
                },
                get total() { return (parseFloat(this.principal) || 0) + this.interest; },
                get installment() {
                    const c = Math.max(1, parseInt(this.count) || 1);
                    return this.total / c;
                },
            };
        }
    </script>
</x-layouts.admin>
