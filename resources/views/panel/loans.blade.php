@use('App\Modules\Loans\Enums\LoanStatus')
@use('App\Modules\Loans\Enums\LoanFrequency')

<x-layouts.admin title="Préstamos" heading="Préstamos" subheading="Cartera de préstamos, cuotas y cobros">
    <div>
        @if (request('filter') === 'overdue')
            <div class="mb-4 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">
                <span class="flex items-center gap-2 font-medium">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-5 w-5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z"/></svg>
                    Mostrando solo préstamos con <b>cuotas vencidas</b>.
                </span>
                <a href="{{ route('panel.loans') }}" class="bmos-btn bmos-btn-ghost text-xs">Ver todos</a>
            </div>
        @endif

        <div class="bmos-card overflow-hidden">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 p-4">
                <p class="font-semibold text-slate-800">Cartera</p>
                <div class="flex flex-wrap items-center gap-3">
                    <x-panel.search-bar placeholder="Buscar por código o cliente..." />
                    @can('loans.manage')
                    <x-panel.create-modal title="Nuevo préstamo" label="Nuevo préstamo" form="loan_create" :action="route('panel.loans.store')">
                        <div x-data="loanCalc()">
                            <div>
                                <label class="bmos-field-label">Cliente</label>
                                <select name="customer_id" class="bmos-input" required>
                                    <option value="">— Selecciona un cliente —</option>
                                    @foreach ($customers as $c)
                                        <option value="{{ $c->id }}" @selected(old('customer_id') == $c->id)>{{ $c->name }}</option>
                                    @endforeach
                                </select>
                            </div>

                            <div class="mt-3 grid grid-cols-2 gap-3">
                                <div>
                                    <label class="bmos-field-label">Capital (RD$)</label>
                                    <input type="number" step="0.01" min="0" name="principal" x-model="principal"
                                           value="{{ old('principal') }}" class="bmos-input" required>
                                </div>
                                <div>
                                    <label class="bmos-field-label">Tasa de interés (%)</label>
                                    <input type="number" step="0.01" min="0" name="interest_rate" x-model="rate"
                                           value="{{ old('interest_rate', 0) }}" class="bmos-input" placeholder="Ej: 20">
                                </div>
                            </div>

                            <div class="mt-3 grid grid-cols-2 gap-3">
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
                            </div>

                            <div class="mt-3 grid grid-cols-2 gap-3">
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
                            </div>

                            <div class="mt-3 grid grid-cols-2 gap-3">
                                <div>
                                    <label class="bmos-field-label">Mora (%) opcional</label>
                                    <input type="number" step="0.01" min="0" name="late_fee_rate" value="{{ old('late_fee_rate') }}" class="bmos-input" placeholder="Ej: 5">
                                </div>
                                <div>
                                    <label class="bmos-field-label">Garantía (opcional)</label>
                                    <input type="text" name="collateral" value="{{ old('collateral') }}" class="bmos-input" placeholder="Cédula, prenda...">
                                </div>
                            </div>

                            {{-- Vista previa en vivo de lo que el cliente terminará pagando. --}}
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
                        </div>
                    </x-panel.create-modal>
                    @endcan
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="bmos-table">
                    <thead>
                        <tr>
                            <th>Código</th><th>Cliente</th><th>Capital</th><th>Total</th><th>Saldo</th>
                            <th>Cuota</th><th>Frecuencia</th><th>Estado</th><th>Próx. venc.</th><th class="text-right">Ver</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($loans as $loan)
                            <tr>
                                <td class="font-mono text-xs text-slate-500">
                                    <a href="{{ route('panel.loans.show', $loan) }}" class="text-indigo-600 hover:underline">{{ $loan->code }}</a>
                                </td>
                                <td class="font-medium text-slate-800">{{ $loan->customer_name ?? $loan->customer?->name ?? '—' }}</td>
                                <td>{{ number_format((float) $loan->principal, 2) }}</td>
                                <td>{{ number_format((float) $loan->total, 2) }}</td>
                                <td class="font-semibold">{{ number_format((float) $loan->balance, 2) }}</td>
                                <td>{{ number_format((float) $loan->installment_amount, 2) }}</td>
                                <td>{{ $loan->frequency->label() }}</td>
                                <td><span class="bmos-badge {{ $loan->status->badgeClass() }}">{{ $loan->status->label() }}</span></td>
                                <td class="text-xs text-slate-500">
                                    @if ($loan->installments_min_due_date)
                                        {{ \Illuminate\Support\Carbon::parse($loan->installments_min_due_date)->format('d/m/Y') }}
                                    @else
                                        —
                                    @endif
                                </td>
                                <td>
                                    <div class="flex items-center justify-end">
                                        <a href="{{ route('panel.loans.show', $loan) }}" class="rounded-lg p-1.5 text-slate-500 hover:bg-slate-100 hover:text-indigo-600" title="Ver detalle">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" style="width:1.15rem;height:1.15rem"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/></svg>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="10" class="bmos-empty">Sin préstamos todavía. Crea el primero con «Nuevo préstamo».</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($loans->hasPages())
                <div class="border-t border-slate-100 p-4">{{ $loans->links() }}</div>
            @endif
        </div>
    </div>

    <script>
        function loanCalc() {
            return {
                principal: {{ (float) old('principal', 0) }},
                rate: {{ (float) old('interest_rate', 0) }},
                amount: '{{ old('interest_amount') }}',
                count: {{ (int) old('installments_count', 1) }},
                rd(n) { return 'RD$ ' + (parseFloat(n) || 0).toLocaleString('es-DO', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); },
                get interest() {
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
