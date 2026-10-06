@php
    $nombreCliente = $cuenta->customer_name ?? $cuenta->customer?->name ?? 'Cliente';
    $inicial = mb_strtoupper(mb_substr($nombreCliente, 0, 1));
    $porcentaje = $cuenta->porcentajePagado();
    $pagado = bcsub((string) $cuenta->total, (string) $cuenta->balance, 2);

    // El anillo: circunferencia de un círculo r=52, y cuánto de ese trazo se deja "relleno" según
    // el porcentaje. -rotate-90 en el <svg> hace que empiece arriba en vez de a las 3 en punto.
    $radio = 52;
    $circunferencia = round(2 * M_PI * $radio, 2);
    $relleno = round($circunferencia * (1 - $porcentaje / 100), 2);

    // Punto donde termina el trazo índigo (según el %), para degradar ahí mismo hacia el rojo
    // del fondo y que las dos mitades del anillo se combinen sin un corte brusco.
    $anguloFin = 2 * M_PI * $porcentaje / 100;
    $anilloId = 'anillo-cobrar-'.$cuenta->id;
    $anilloX1 = 60 + $radio;
    $anilloY1 = 60;
    $anilloX2 = round(60 + $radio * cos($anguloFin), 2);
    $anilloY2 = round(60 + $radio * sin($anguloFin), 2);

    $vencida = $cuenta->estaVencida();
    [$bannerBg, $bannerBorde, $bannerTexto, $pildora] = match (true) {
        $cuenta->status->value === 'paid' => ['bg-emerald-50', 'border-emerald-200', 'text-emerald-900', ['bg-emerald-600', 'PAGADA']],
        $vencida => ['bg-rose-50', 'border-rose-200', 'text-rose-900', ['bg-rose-600', 'VENCIDA']],
        default => ['bg-indigo-50', 'border-indigo-200', 'text-indigo-900', ['bg-indigo-600', $cuenta->status->label()]],
    };
@endphp

<x-layouts.admin :back="route('panel.receivables')" :back-label="'Cuentas por cobrar'"
                 :title="'Cuenta por cobrar '.$cuenta->code" heading="Cuentas por Cobrar">

    <div x-data="{ editando: false, menu: false }">
        {{-- ── Cabecera: cambia de tono según el estado real (vencida pesa más que "pendiente") ── --}}
        <div class="mb-5 flex flex-wrap items-center justify-between gap-3 rounded-2xl border {{ $bannerBorde }} {{ $bannerBg }} px-5 py-4">
            <div>
                <p class="text-lg font-bold {{ $bannerTexto }}">
                    {{ $cuenta->code }}
                    <span class="text-sm font-medium text-slate-500">(Documento de Cuenta por Cobrar)</span>
                </p>
                <p class="text-sm text-slate-500">Cliente: {{ $nombreCliente }}</p>
            </div>
            <span class="rounded-full {{ $pildora[0] }} px-4 py-1.5 text-xs font-bold uppercase tracking-wide text-white">
                {{ $pildora[1] }}
            </span>
        </div>

        <div class="grid grid-cols-1 gap-5 lg:grid-cols-[minmax(0,1fr)_21rem]">
            <div class="space-y-5">
                {{-- ── Resumen financiero y documental ── --}}
                <div class="bmos-card bmos-card-pad shadow-lg">
                    <div class="mb-4 flex items-center justify-between">
                        <p class="font-semibold text-slate-800">Resumen Financiero y Documental</p>

                        @can('finance.manage')
                            <div class="relative">
                                <button type="button" @click="menu = !menu" @click.outside="menu = false"
                                        class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-600">
                                    <svg viewBox="0 0 24 24" fill="currentColor" class="h-5 w-5">
                                        <circle cx="12" cy="5" r="1.5"/><circle cx="12" cy="12" r="1.5"/><circle cx="12" cy="19" r="1.5"/>
                                    </svg>
                                </button>
                                <div x-show="menu" x-cloak x-transition
                                     class="absolute right-0 z-10 mt-1 w-40 overflow-hidden rounded-lg border border-slate-200 bg-white py-1 shadow-lg">
                                    <button type="button" @click="menu = false; editando = true"
                                            class="flex w-full items-center gap-2 px-3 py-2 text-sm text-slate-700 hover:bg-slate-50">
                                        <x-icono name="doc" class="h-4 w-4" /> Editar
                                    </button>
                                    @if ($cuenta->payments->isNotEmpty())
                                        <button type="button" @click="menu = false"
                                                onclick="window.avisoRapido('Esta cuenta ya tiene abonos registrados: no se puede eliminar sin perder ese historial.')"
                                                class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm text-rose-300 hover:bg-rose-50">
                                            <x-icono name="ban" class="h-4 w-4" /> Eliminar
                                        </button>
                                    @else
                                        <x-panel.confirm-action
                                            :action="route('panel.receivables.destroy', $cuenta)"
                                            title="¿Eliminar esta cuenta?"
                                            message="Todavía no tiene abonos registrados, así que se elimina por completo."
                                            class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm text-rose-600 hover:bg-rose-50">
                                            <x-icono name="ban" class="h-4 w-4" /> Eliminar
                                        </x-panel.confirm-action>
                                    @endif
                                </div>
                            </div>
                        @endcan
                    </div>

                    <div class="mb-4 flex items-center gap-2.5">
                        <span class="flex h-9 w-9 items-center justify-center rounded-full bg-indigo-100 font-bold text-indigo-700">{{ $inicial }}</span>
                        <span class="font-semibold text-slate-700">{{ $nombreCliente }}</span>
                    </div>

                    <div class="flex flex-wrap items-center gap-6">
                        {{-- El anillo de progreso: SVG puro, sin librerías. --}}
                        <div class="relative h-28 w-28 shrink-0">
                            <svg viewBox="0 0 120 120" class="h-28 w-28 -rotate-90">
                                <defs>
                                    <linearGradient id="{{ $anilloId }}" gradientUnits="userSpaceOnUse"
                                                     x1="{{ $anilloX1 }}" y1="{{ $anilloY1 }}" x2="{{ $anilloX2 }}" y2="{{ $anilloY2 }}">
                                        <stop offset="0%" stop-color="#4f46e5"/>
                                        <stop offset="100%" stop-color="#e11d48"/>
                                    </linearGradient>
                                </defs>
                                <circle cx="60" cy="60" r="{{ $radio }}" fill="none" stroke="#e11d48" stroke-width="12"/>
                                <circle cx="60" cy="60" r="{{ $radio }}" fill="none" stroke="url(#{{ $anilloId }})" stroke-width="12"
                                        stroke-linecap="round" stroke-dasharray="{{ $circunferencia }}"
                                        stroke-dashoffset="{{ $relleno }}"/>
                            </svg>
                            <div class="absolute inset-0 flex flex-col items-center justify-center">
                                <span class="text-xl font-bold text-slate-800">{{ $porcentaje }}%</span>
                                <span class="text-xs text-slate-400">Pago</span>
                            </div>
                        </div>

                        <div class="min-w-[12rem] flex-1">
                            <p class="text-xs uppercase tracking-wide text-slate-400">Saldo Pendiente</p>
                            <p class="text-2xl font-bold text-slate-800">{{ money((float) $cuenta->balance) }}</p>
                            <p class="text-xs text-slate-400">de {{ money((float) $cuenta->total) }}</p>

                            <div class="mt-3 h-2 w-full overflow-hidden rounded-full bg-slate-100">
                                <div class="h-full rounded-full bg-gradient-to-r from-indigo-500 to-indigo-700" style="width: {{ $porcentaje }}%"></div>
                            </div>
                            <p class="mt-1 text-right text-xs text-slate-400">{{ $porcentaje }}%</p>
                        </div>
                    </div>

                    <dl class="mt-4 space-y-1 text-sm text-slate-600">
                        <div><span class="text-slate-400">Vencimiento:</span> {{ $cuenta->due_date?->format('d/m/Y') ?? '—' }}</div>
                        @if (filled($cuenta->notes))
                            <div><span class="text-slate-400">Notas:</span> {{ $cuenta->notes }}</div>
                        @endif
                        <div><span class="text-slate-400">Total Documento:</span> {{ money((float) $cuenta->total) }}</div>
                        @if ($cuenta->sale !== null)
                            <div><span class="text-slate-400">Venta de origen:</span> {{ $cuenta->sale->code }}</div>
                        @endif
                    </dl>
                </div>

                {{-- ── Historial ── --}}
                <div class="bmos-card overflow-hidden shadow-lg">
                    <div class="border-b border-slate-100 p-4">
                        <p class="font-semibold text-slate-800">Historial de Cobros Recientes</p>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="bmos-table">
                            <thead>
                                <tr><th>Fecha</th><th>Monto</th><th>Forma</th><th>Registrado por</th><th>Estado</th><th>Nota</th></tr>
                            </thead>
                            <tbody>
                                @forelse ($cuenta->payments as $abono)
                                    <tr>
                                        <td data-rotulo="Fecha">{{ $abono->paid_at->format('d/m/Y H:i') }}</td>
                                        <td data-rotulo="Monto" class="font-semibold">{{ money((float) $abono->amount) }}</td>
                                        <td data-rotulo="Forma">{{ $abono->method ?? '—' }}</td>
                                        <td data-rotulo="Registrado por">{{ $abono->user?->name ?? '—' }}</td>
                                        <td data-rotulo="Estado"><span class="bmos-badge badge-green">Confirmado</span></td>
                                        <td data-rotulo="Nota" class="text-slate-500">{{ $abono->note ?? '—' }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="6" class="py-10 text-center">
                                            <x-icono name="doc" class="mx-auto mb-2 h-10 w-10 text-slate-300" />
                                            <p class="mb-3 text-sm text-slate-400">Todavía no se ha registrado ningún abono.</p>
                                            @can('finance.manage')
                                                @if ($cuenta->status->canBePaid())
                                                    <a href="#registrar-cobro" class="bmos-btn bmos-btn-primary">+ Añadir Abono</a>
                                                @endif
                                            @endcan
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="space-y-4">
                @can('finance.manage')
                    @if ($cuenta->status->canBePaid())
                        <div id="registrar-cobro" class="bmos-card bmos-card-pad shadow-lg" style="scroll-margin-top:1.5rem" x-data="{ forma: 'Efectivo' }">
                            <p class="mb-3 font-semibold text-slate-800">Registrar Cobro Actual</p>
                            <form method="POST" action="{{ route('panel.receivables.pay', $cuenta) }}" class="space-y-3">
                                @csrf
                                <div>
                                    <label class="bmos-field-label">Monto</label>
                                    <input type="number" step="0.01" name="amount" x-ref="monto" class="bmos-input"
                                           value="{{ number_format((float) $cuenta->balance, 2, '.', '') }}" required>
                                    <button type="button" class="mt-1 text-xs font-semibold text-indigo-600 hover:underline"
                                            @click="$refs.monto.value = '{{ number_format((float) $cuenta->balance, 2, '.', '') }}'">
                                        Abonar saldo total
                                    </button>
                                </div>

                                <div>
                                    <label class="bmos-field-label">Cuenta que recibe el dinero</label>
                                    <select name="account_id" class="bmos-input" required>
                                        @foreach ($accounts as $account)
                                            <option value="{{ $account->id }}">{{ $account->name }}</option>
                                        @endforeach
                                    </select>
                                </div>

                                {{-- Forma de pago: tarjetas para elegir de un vistazo, en vez de un
                                     texto libre que cada quien escribe distinto ("efectivo",
                                     "Efectivo", "cash"...). --}}
                                <div>
                                    <label class="bmos-field-label">Forma de pago</label>
                                    <div class="grid grid-cols-3 gap-2">
                                        @foreach (['Efectivo' => 'cash', 'Transferencia bancaria' => 'building', 'Tarjeta de crédito' => 'tarjeta'] as $etiqueta => $icono)
                                            <button type="button" @click="forma = '{{ $etiqueta }}'"
                                                    class="flex flex-col items-center gap-1 rounded-xl border p-2.5 text-center transition"
                                                    :class="forma === '{{ $etiqueta }}' ? 'border-indigo-500 bg-indigo-50 text-indigo-700' : 'border-slate-200 text-slate-500 hover:bg-slate-50'">
                                                <x-icono :name="$icono" class="h-5 w-5" />
                                                <span class="text-[0.65rem] font-medium leading-tight">{{ $etiqueta }}</span>
                                            </button>
                                        @endforeach
                                    </div>
                                    <input type="hidden" name="method" x-bind:value="forma">
                                </div>

                                <div>
                                    <label class="bmos-field-label">Nota de abono</label>
                                    <textarea name="note" rows="2" class="bmos-input" placeholder="Nota de abono" maxlength="255"></textarea>
                                </div>

                                <button type="submit" class="bmos-btn bmos-btn-primary w-full justify-center py-2.5 text-sm font-bold uppercase tracking-wide">
                                    Registrar Abono
                                </button>
                            </form>
                        </div>
                    @else
                        <div class="bmos-card bmos-card-pad shadow-lg">
                            <p class="font-semibold text-slate-800">Ya está saldada</p>
                            <p class="mt-1 text-sm text-slate-500">Esta cuenta no tiene saldo pendiente.</p>
                        </div>
                    @endif
                @endcan
            </div>
        </div>

        {{-- Modal de edición --}}
        <div x-show="editando" x-cloak class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-900/50 p-4 py-10" @keydown.escape.window="editando = false">
            <div @click.outside="editando = false" x-transition class="w-full max-w-lg rounded-2xl bg-white p-6 shadow-2xl">
                <div class="mb-4 flex items-center justify-between">
                    <h3 class="text-lg font-semibold text-slate-800">Editar cuenta por cobrar</h3>
                    <button type="button" @click="editando = false" class="text-slate-400 hover:text-slate-600">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-5 w-5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>
                <form method="POST" action="{{ route('panel.receivables.update', $cuenta) }}" class="space-y-3">
                    @csrf @method('PUT')
                    <div>
                        <label class="bmos-field-label">Nombre del cliente</label>
                        <input type="text" name="customer_name" class="bmos-input" value="{{ old('customer_name', $cuenta->customer_name) }}">
                    </div>
                    <div>
                        <label class="bmos-field-label">Monto</label>
                        <input type="number" step="0.01" name="total" class="bmos-input" value="{{ old('total', $cuenta->total) }}">
                        <p class="mt-1 text-xs text-slate-400">Solo se puede cambiar si la cuenta todavía no tiene abonos.</p>
                    </div>
                    <div>
                        <label class="bmos-field-label">Vencimiento</label>
                        <input type="date" name="due_date" class="bmos-input" value="{{ old('due_date', optional($cuenta->due_date)->toDateString()) }}">
                    </div>
                    <div>
                        <label class="bmos-field-label">Notas</label>
                        <textarea name="notes" rows="2" class="bmos-input">{{ old('notes', $cuenta->notes) }}</textarea>
                    </div>
                    <div class="flex justify-end gap-2 pt-3">
                        <button type="button" @click="editando = false" class="bmos-btn bmos-btn-ghost">Cancelar</button>
                        <button type="submit" class="bmos-btn bmos-btn-primary">Guardar cambios</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</x-layouts.admin>
