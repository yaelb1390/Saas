@use('App\Modules\Loans\Enums\LoanStatus')

<x-layouts.admin title="Préstamo {{ $loan->code }}" heading="Préstamo {{ $loan->code }}" :subheading="'Cliente: ' . ($loan->customer_name ?? $loan->customer?->name ?? '—')">
    <div class="mb-4">
        <a href="{{ route('panel.loans') }}" class="text-sm text-slate-500 hover:text-indigo-600">&larr; Volver a la cartera</a>
    </div>

    @if (session('loan_receipt_payment_id'))
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm">
            <span class="font-medium text-emerald-800">Cobro registrado. Imprime el recibo para el cliente.</span>
            <a href="{{ route('panel.loans.receipt', [$loan, session('loan_receipt_payment_id')]) }}?print=1"
               target="_blank" rel="noopener" class="bmos-btn bmos-btn-primary text-xs">🖨️ Imprimir recibo</a>
        </div>
    @endif

    <div class="grid grid-cols-1 gap-5 lg:grid-cols-3">
        {{-- Resumen + acciones --}}
        <div class="space-y-5 lg:col-span-1">
            <div class="bmos-card bmos-card-pad">
                <div class="mb-3 flex items-center justify-between">
                    <p class="font-semibold text-slate-800">Resumen</p>
                    <span class="bmos-badge {{ $loan->status->badgeClass() }}">{{ $loan->status->label() }}</span>
                </div>
                @if ($loan->customer)
                    <a href="{{ route('panel.customers.show', $loan->customer) }}" class="mb-2 inline-block text-xs text-indigo-600 hover:underline">Ver perfil del cliente →</a>
                @endif
                <dl class="space-y-2 text-sm">
                    <div class="flex justify-between"><dt class="text-slate-500">Capital</dt><dd class="font-medium">{{ money($loan->principal) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-slate-500">Interés ({{ number_format((float) $loan->interest_rate, 2) }}%)</dt><dd class="font-medium">{{ money($loan->interest_amount) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-slate-500">Total a pagar</dt><dd class="font-semibold text-indigo-600">{{ money($loan->total) }}</dd></div>
                    <div class="flex justify-between border-t border-slate-100 pt-2"><dt class="text-slate-500">Saldo pendiente</dt><dd class="text-lg font-bold text-slate-800">{{ money($loan->balance) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-slate-500">Cuota</dt><dd>{{ money($loan->installment_amount) }} · {{ $loan->frequency->label() }}</dd></div>
                    <div class="flex justify-between"><dt class="text-slate-500">Cuotas</dt><dd>{{ $loan->installments_count }}</dd></div>
                    @if ($loan->collateral)
                        <div class="flex justify-between"><dt class="text-slate-500">Garantía</dt><dd class="text-right">{{ $loan->collateral }}</dd></div>
                    @endif
                    <div class="flex justify-between"><dt class="text-slate-500">Desembolsado</dt><dd>{{ $loan->disbursed_at?->format('d/m/Y') ?? '—' }}</dd></div>
                </dl>
            </div>

            @can('loans.manage')
                @if ($loan->status === LoanStatus::Active)
                    <div class="bmos-card bmos-card-pad">
                        <p class="mb-3 font-semibold text-slate-800">Registrar abono</p>
                        <form method="POST" action="{{ route('panel.loans.payments.store', $loan) }}" class="space-y-3">
                            @csrf
                            <div>
                                <label class="bmos-field-label">Monto (RD$)</label>
                                <input type="number" step="0.01" min="0.01" name="amount" class="bmos-input" required placeholder="0.00">
                            </div>
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label class="bmos-field-label">Método</label>
                                    <input type="text" name="method" class="bmos-input" placeholder="Efectivo">
                                </div>
                                <div>
                                    <label class="bmos-field-label">Nota</label>
                                    <input type="text" name="note" class="bmos-input" placeholder="Opcional">
                                </div>
                            </div>
                            <button type="submit" class="bmos-btn bmos-btn-primary w-full justify-center">Registrar cobro</button>
                        </form>
                    </div>
                @endif

                @if ($loan->status === LoanStatus::Active && $loan->payments->isEmpty())
                    <form method="POST" action="{{ route('panel.loans.cancel', $loan) }}"
                          onsubmit="return confirm('¿Anular el préstamo {{ $loan->code }}? Solo se puede si no tiene cobros.')">
                        @csrf
                        <button type="submit" class="bmos-btn bmos-btn-ghost w-full justify-center text-rose-600 hover:bg-rose-50">Anular préstamo</button>
                    </form>
                @endif
            @endcan
        </div>

        {{-- Amortización + historial --}}
        <div class="space-y-5 lg:col-span-2">
            <div class="bmos-card overflow-hidden">
                <div class="border-b border-slate-100 p-4"><p class="font-semibold text-slate-800">Calendario de cuotas</p></div>
                <div class="overflow-x-auto">
                    <table class="bmos-table">
                        <thead>
                            <tr>
                                <th>#</th><th>Vencimiento</th><th>Monto</th><th>Mora</th><th>Pagado</th><th>Saldo</th><th>Estado</th>
                                @can('loans.manage')<th class="text-right">Mora</th>@endcan
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($loan->installments as $inst)
                                @php $overdue = $inst->isOverdue(); @endphp
                                <tr class="{{ $overdue ? 'bg-rose-50/50' : '' }}">
                                    <td class="text-slate-500">{{ $inst->number }}</td>
                                    <td class="{{ $overdue ? 'font-medium text-rose-600' : '' }}">{{ $inst->due_date->format('d/m/Y') }}</td>
                                    <td>{{ number_format((float) $inst->amount, 2) }}</td>
                                    <td>{{ number_format((float) $inst->late_fee, 2) }}</td>
                                    <td>{{ number_format((float) $inst->paid_amount, 2) }}</td>
                                    <td class="font-semibold">{{ number_format((float) $inst->outstanding(), 2) }}</td>
                                    <td>
                                        @if ($overdue)
                                            <span class="bmos-badge badge-red">Vencida</span>
                                        @else
                                            <span class="bmos-badge {{ $inst->status->value === 'paid' ? 'badge-green' : ($inst->status->value === 'partial' ? 'badge-amber' : 'badge-gray') }}">{{ $inst->status->label() }}</span>
                                        @endif
                                    </td>
                                    @can('loans.manage')
                                        <td>
                                            <form method="POST" action="{{ route('panel.loans.installments.fee', [$loan, $inst]) }}" class="flex items-center justify-end gap-1">
                                                @csrf
                                                <input type="number" step="0.01" min="0" name="amount" value="{{ (float) $inst->late_fee }}"
                                                       class="w-20 rounded border-slate-200 px-2 py-1 text-right text-xs" title="Mora de la cuota">
                                                <button type="submit" class="rounded-lg p-1 text-slate-500 hover:bg-slate-100 hover:text-indigo-600" title="Guardar mora">
                                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" style="width:1rem;height:1rem"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5"/></svg>
                                                </button>
                                            </form>
                                        </td>
                                    @endcan
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="bmos-card overflow-hidden">
                <div class="border-b border-slate-100 p-4"><p class="font-semibold text-slate-800">Cobros registrados</p></div>
                <div class="overflow-x-auto">
                    <table class="bmos-table">
                        <thead><tr><th>Fecha</th><th>Monto</th><th>Saldo</th><th>Método</th><th class="text-right">Recibo</th></tr></thead>
                        <tbody>
                            @forelse ($loan->payments as $payment)
                                <tr>
                                    <td class="text-slate-500">{{ $payment->paid_at->format('d/m/Y H:i') }}</td>
                                    <td class="font-semibold text-emerald-600">{{ number_format((float) $payment->amount, 2) }}</td>
                                    <td>{{ number_format((float) $payment->balance_after, 2) }}</td>
                                    <td>{{ $payment->method ?? '—' }}</td>
                                    <td class="text-right">
                                        <a href="{{ route('panel.loans.receipt', [$loan, $payment]) }}?print=1" target="_blank" rel="noopener"
                                           class="text-indigo-600 hover:underline">🖨️ Imprimir</a>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="bmos-empty">Aún no hay cobros.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</x-layouts.admin>
