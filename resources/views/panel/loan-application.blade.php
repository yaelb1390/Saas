@use('App\Modules\Loans\Enums\LoanApplicationStatus')

@php
    $puedeEditar = $application->status->admiteEdicion();
    $puedeDecidir = $application->status->admiteDecision();
    $capacidad = $application->capacidadDePago();
    $peso = $application->pesoDeLaCuota();
@endphp

<x-layouts.admin title="Solicitud {{ $application->code }}"
                 heading="Solicitud {{ $application->code }}"
                 :subheading="'Cliente: ' . ($application->customer_name ?? $application->customer?->name ?? '—')">
    <div class="grid grid-cols-1 gap-5 lg:grid-cols-3">

        {{-- ------------------------------------------------------- Columna izquierda: la decisión --}}
        <div class="space-y-5">
            <div class="bmos-card bmos-card-pad">
                <div class="flex items-center justify-between">
                    <span class="bmos-badge {{ $application->status->badge() }}">{{ $application->status->label() }}</span>
                    <span class="text-xs text-slate-400">{{ $application->created_at?->format('d/m/Y') }}</span>
                </div>

                <dl class="mt-4 space-y-2 text-sm">
                    <div class="flex justify-between"><dt class="text-slate-500">Capital solicitado</dt>
                        <dd class="font-semibold text-slate-800">{{ money($application->principal) }}</dd></div>

                    @if ($application->seAjustaronLosTerminos())
                        {{-- Lo aprobado va aparte y resaltado. Sustituir la cifra pedida por la aprobada
                             borraría del expediente qué vino a pedir el cliente, que es la mitad de la
                             historia cuando se aprueba menos. --}}
                        <div class="flex justify-between rounded-lg bg-indigo-50 px-2 py-1.5">
                            <dt class="font-medium text-indigo-700">Capital aprobado</dt>
                            <dd class="font-bold text-indigo-700">{{ money($application->capitalEfectivo()) }}</dd>
                        </div>
                    @endif

                    <div class="flex justify-between"><dt class="text-slate-500">Cuotas</dt>
                        <dd>{{ $application->cuotasEfectivas() }} · {{ $application->frequency->label() }}</dd></div>
                    <div class="flex justify-between"><dt class="text-slate-500">Tasa</dt>
                        <dd>{{ number_format((float) $application->tasaEfectiva(), 2) }} %</dd></div>
                    <div class="flex justify-between"><dt class="text-slate-500">Interés</dt>
                        <dd>{{ money($application->interesEstimado()) }}</dd></div>
                    <div class="flex justify-between border-t border-slate-100 pt-2">
                        <dt class="font-medium text-slate-600">Total a devolver</dt>
                        <dd class="font-bold text-slate-800">{{ money($application->totalEstimado()) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-slate-500">Cuota</dt>
                        <dd class="font-semibold">{{ money($application->cuotaEstimada()) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-slate-500">Primer vencimiento</dt>
                        <dd>{{ $application->start_date?->format('d/m/Y') }}</dd></div>
                    @if ($application->purpose)
                        <div class="flex justify-between gap-3"><dt class="text-slate-500">Destino</dt>
                            <dd class="text-right">{{ $application->purpose }}</dd></div>
                    @endif
                    @if ($application->collateral)
                        <div class="flex justify-between gap-3"><dt class="text-slate-500">Garantía</dt>
                            <dd class="text-right">{{ $application->collateral }}</dd></div>
                    @endif
                </dl>

                @if ($application->decided_at)
                    <div class="mt-4 rounded-lg bg-slate-50 p-3 text-xs text-slate-600">
                        <p><b>{{ $application->status->label() }}</b> el {{ $application->decided_at->format('d/m/Y H:i') }}
                            @if ($application->decider) por {{ $application->decider->name }} @endif
                        </p>
                        @if ($application->decision_notes)
                            <p class="mt-1 text-slate-500">{{ $application->decision_notes }}</p>
                        @endif
                    </div>
                @endif
            </div>

            {{-- Acciones. El orden importa: desembolsar es la única que mueve dinero, así que va sola
                 y con su propio aviso. --}}
            @can('loans.manage')
                @if ($puedeDecidir)
                    <div class="bmos-card bmos-card-pad space-y-3">
                        <p class="font-semibold text-slate-800">Decidir</p>
                        <p class="text-xs text-slate-500">
                            Aprobar <b>no entrega el dinero</b>: después habrá que desembolsar la solicitud.
                        </p>

                        {{-- Aprobar con ajuste: los tres campos vacíos aprueban lo que se pidió. --}}
                        <form method="POST" action="{{ route('panel.loan-applications.approve', $application) }}" class="space-y-2">
                            @csrf
                            <div class="grid grid-cols-3 gap-2">
                                <div>
                                    <label class="bmos-field-label text-xs">Capital</label>
                                    <input type="number" step="0.01" min="0" name="principal" class="bmos-input"
                                           placeholder="{{ number_format((float) $application->principal, 2, '.', '') }}">
                                </div>
                                <div>
                                    <label class="bmos-field-label text-xs">Cuotas</label>
                                    <input type="number" step="1" min="1" name="installments_count" class="bmos-input"
                                           placeholder="{{ $application->installments_count }}">
                                </div>
                                <div>
                                    <label class="bmos-field-label text-xs">Tasa %</label>
                                    <input type="number" step="0.01" min="0" name="interest_rate" class="bmos-input"
                                           placeholder="{{ number_format((float) $application->interest_rate, 2, '.', '') }}">
                                </div>
                            </div>
                            <p class="text-xs text-slate-400">Deja los campos vacíos para aprobar lo solicitado.</p>
                            <input type="text" name="notes" class="bmos-input" placeholder="Nota de la decisión (opcional)">
                            <button type="submit" class="bmos-btn bmos-btn-primary w-full justify-center">Aprobar</button>
                        </form>

                        <form method="POST" action="{{ route('panel.loan-applications.reject', $application) }}" class="space-y-2 border-t border-slate-100 pt-3">
                            @csrf
                            <input type="text" name="notes" class="bmos-input" placeholder="Motivo del rechazo (opcional)">
                            <button type="submit" class="bmos-btn bmos-btn-ghost w-full justify-center text-rose-600 hover:bg-rose-50">
                                Rechazar
                            </button>
                        </form>
                    </div>
                @endif

                @if ($application->status === LoanApplicationStatus::Approved)
                    <div class="bmos-card bmos-card-pad space-y-3 ring-2 ring-indigo-200">
                        <p class="font-semibold text-slate-800">Entregar el dinero</p>
                        <p class="text-sm text-slate-600">
                            Se creará el préstamo por <b>{{ money($application->capitalEfectivo()) }}</b> y ese
                            importe <b>saldrá de la caja</b>.
                        </p>
                        <x-panel.confirm-action
                            :action="route('panel.loan-applications.disburse', $application)"
                            method="POST"
                            tone="neutral"
                            title="¿Desembolsar {{ money($application->capitalEfectivo()) }}?"
                            message="Se crea el préstamo con su calendario de cuotas y el capital sale de la caja como egreso."
                            note="No hay botón para deshacerlo: revertirlo obliga a anular el préstamo, y solo se puede mientras no tenga ningún cobro."
                            irreversible
                            confirm="Sí, entregué el dinero"
                            class="bmos-btn bmos-btn-primary w-full justify-center">
                            Desembolsar
                        </x-panel.confirm-action>
                    </div>
                @endif

                @if ($application->status->admiteReapertura())
                    <x-panel.confirm-action
                        :action="route('panel.loan-applications.reopen', $application)"
                        method="POST"
                        tone="neutral"
                        title="¿Devolver la solicitud a evaluación?"
                        message="Se borra la decisión y los términos aprobados, y vuelve a poder editarse."
                        confirm="Reabrir"
                        class="bmos-btn bmos-btn-ghost w-full justify-center">
                        Reabrir
                    </x-panel.confirm-action>
                @endif
            @endcan

            @if ($application->loan)
                <a href="{{ route('panel.loans.show', $application->loan) }}"
                   class="bmos-card bmos-card-pad block transition hover:shadow-md">
                    <p class="text-xs text-slate-400">Préstamo desembolsado</p>
                    <p class="font-mono font-bold text-indigo-600">{{ $application->loan->code }}</p>
                    <p class="mt-1 text-xs text-slate-500">Saldo {{ money($application->loan->balance) }} →</p>
                </a>
            @endif
        </div>

        {{-- ------------------------------------------------ Columna derecha: evaluación e historial --}}
        <div class="space-y-5 lg:col-span-2">

            {{-- Historial del cliente con la casa.

                 Va ARRIBA del formulario a propósito: es lo único de esta pantalla que no depende de
                 lo que declare el cliente. Lo que dijo que gana está por debajo; lo que hizo con el
                 dinero de la casa, aquí. --}}
            <div class="bmos-card bmos-card-pad">
                <p class="font-semibold text-slate-800">Historial con nosotros</p>
                @if ($historial['prestamos'] === 0)
                    <p class="mt-2 text-sm text-slate-500">Cliente nuevo: no ha tenido préstamos antes.</p>
                @else
                    <div class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-5">
                        <div>
                            <p class="text-xs text-slate-400">Préstamos</p>
                            <p class="text-xl font-bold text-slate-800">{{ $historial['prestamos'] }}</p>
                        </div>
                        <div>
                            <p class="text-xs text-slate-400">Saldados</p>
                            <p class="text-xl font-bold text-emerald-600">{{ $historial['saldados'] }}</p>
                        </div>
                        <div>
                            <p class="text-xs text-slate-400">Vigentes</p>
                            <p class="text-xl font-bold text-slate-800">{{ $historial['vigentes'] }}</p>
                        </div>
                        <div>
                            <p class="text-xs text-slate-400">En atraso hoy</p>
                            <p class="text-xl font-bold {{ $historial['en_atraso_hoy'] > 0 ? 'text-rose-600' : 'text-slate-800' }}">
                                {{ $historial['en_atraso_hoy'] }}
                            </p>
                        </div>
                        <div>
                            <p class="text-xs text-slate-400">Pagó tarde</p>
                            <p class="text-xl font-bold {{ $historial['pagadas_tarde'] > 0 ? 'text-amber-600' : 'text-slate-800' }}">
                                {{ $historial['pagadas_tarde'] }}
                            </p>
                        </div>
                    </div>

                    @if ((float) $historial['saldo_vivo'] > 0)
                        <p class="mt-3 text-sm text-slate-600">
                            Ya debe <b>{{ money($historial['saldo_vivo']) }}</b> de sus préstamos vigentes.
                        </p>
                    @endif

                    @if ($historial['pagadas_tarde'] > 0 && $historial['en_atraso_hoy'] === 0)
                        {{-- Este es el caso que se escapa si solo se mira la deuda de hoy: está al día,
                             pero cada cuota hubo que perseguirla. --}}
                        <p class="mt-2 text-xs text-amber-700">
                            Está al día, pero {{ $historial['pagadas_tarde'] }}
                            {{ $historial['pagadas_tarde'] === 1 ? 'cuota se pagó' : 'cuotas se pagaron' }} después de la fecha.
                        </p>
                    @endif
                @endif
            </div>

            {{-- Capacidad de pago --}}
            <div class="bmos-card bmos-card-pad">
                <p class="font-semibold text-slate-800">Capacidad de pago</p>

                @if ($capacidad === null)
                    <p class="mt-2 text-sm text-slate-500">
                        Sin evaluar: falta declarar el ingreso mensual.
                    </p>
                @elseif ($application->noLeSobraNada())
                    <p class="mt-2 rounded-lg bg-rose-50 px-3 py-2 text-sm font-semibold text-rose-700">
                        Con lo declarado no le sobra nada al mes ({{ money($capacidad) }}).
                    </p>
                @else
                    <div class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-3">
                        <div>
                            <p class="text-xs text-slate-400">Le queda libre al mes</p>
                            <p class="text-xl font-bold text-slate-800">{{ money($capacidad) }}</p>
                        </div>
                        <div>
                            <p class="text-xs text-slate-400">Cuota</p>
                            <p class="text-xl font-bold text-slate-800">{{ money($application->cuotaEstimada()) }}</p>
                        </div>
                        <div>
                            <p class="text-xs text-slate-400">Se lleva</p>
                            <p class="text-xl font-bold {{ (float) $peso > 100 ? 'text-rose-600' : ((float) $peso > 50 ? 'text-amber-600' : 'text-emerald-600') }}">
                                {{ number_format((float) $peso, 1) }} %
                            </p>
                        </div>
                    </div>
                    @if ((float) $peso > 100)
                        <p class="mt-3 rounded-lg bg-rose-50 px-3 py-2 text-sm font-semibold text-rose-700">
                            La cuota es mayor que lo que le sobra: con lo declarado, no le da.
                        </p>
                    @endif
                @endif
            </div>

            {{-- Formulario de evaluación --}}
            <div class="bmos-card bmos-card-pad">
                <div class="flex items-center justify-between">
                    <p class="font-semibold text-slate-800">Evaluación</p>
                    @unless ($puedeEditar)
                        <span class="text-xs text-slate-400">Congelada: la solicitud ya está decidida</span>
                    @endunless
                </div>

                @can('loan_applications.manage')
                    <form method="POST" action="{{ route('panel.loan-applications.evaluate', $application) }}" class="mt-4 space-y-3">
                        @csrf @method('PUT')
                        <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                            <div>
                                <label class="bmos-field-label">Ingreso mensual (RD$)</label>
                                <input type="number" step="0.01" min="0" name="monthly_income" class="bmos-input"
                                       value="{{ old('monthly_income', $application->monthly_income) }}" @disabled(! $puedeEditar)>
                            </div>
                            <div>
                                <label class="bmos-field-label">Gastos mensuales</label>
                                <input type="number" step="0.01" min="0" name="monthly_expenses" class="bmos-input"
                                       value="{{ old('monthly_expenses', $application->monthly_expenses) }}" @disabled(! $puedeEditar)>
                            </div>
                            <div>
                                <label class="bmos-field-label">Otras deudas (cuota/mes)</label>
                                <input type="number" step="0.01" min="0" name="other_debts" class="bmos-input"
                                       value="{{ old('other_debts', $application->other_debts) }}" @disabled(! $puedeEditar)>
                            </div>
                            <div class="sm:col-span-3">
                                <label class="bmos-field-label">Ocupación / dónde trabaja</label>
                                <input type="text" name="employment" class="bmos-input"
                                       value="{{ old('employment', $application->employment) }}" @disabled(! $puedeEditar)>
                            </div>
                            <div>
                                <label class="bmos-field-label">Garante</label>
                                <input type="text" name="guarantor_name" class="bmos-input"
                                       value="{{ old('guarantor_name', $application->guarantor_name) }}" @disabled(! $puedeEditar)>
                            </div>
                            <div>
                                <label class="bmos-field-label">Cédula del garante</label>
                                <input type="text" name="guarantor_cedula" class="bmos-input"
                                       value="{{ old('guarantor_cedula', $application->guarantor_cedula) }}" @disabled(! $puedeEditar)>
                            </div>
                            <div>
                                <label class="bmos-field-label">Teléfono del garante</label>
                                <input type="text" name="guarantor_phone" class="bmos-input"
                                       value="{{ old('guarantor_phone', $application->guarantor_phone) }}" @disabled(! $puedeEditar)>
                            </div>
                            <div class="sm:col-span-3">
                                <label class="bmos-field-label">Notas de la evaluación</label>
                                <textarea name="evaluation_notes" rows="3" class="bmos-input" @disabled(! $puedeEditar)>{{ old('evaluation_notes', $application->evaluation_notes) }}</textarea>
                            </div>
                        </div>

                        @if ($puedeEditar)
                            <div class="flex justify-end">
                                <button type="submit" class="bmos-btn bmos-btn-primary">Guardar evaluación</button>
                            </div>
                        @endif
                    </form>
                @else
                    <p class="mt-2 text-sm text-slate-500">No tienes permiso para editar la evaluación.</p>
                @endcan
            </div>

            @can('loan_applications.manage')
                @if ($puedeDecidir)
                    <div class="flex justify-end">
                        <x-panel.confirm-action
                            :action="route('panel.loan-applications.cancel', $application)"
                            method="POST"
                            tone="neutral"
                            title="¿Marcar la solicitud como desistida?"
                            message="Se usa cuando es el cliente quien se echa atrás, no cuando la agencia dice que no. Para eso está «Rechazar»."
                            confirm="Marcar desistida"
                            class="bmos-btn bmos-btn-ghost text-xs">
                            El cliente desistió
                        </x-panel.confirm-action>
                    </div>
                @endif
            @endcan
        </div>
    </div>
</x-layouts.admin>
