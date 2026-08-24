@php
    use App\Modules\Quotes\Enums\QuoteStatus;

    $estado = $quote->estadoReal();
    $dias = $quote->diasDeVigencia();

    /*
     * El tono y la nota se calculan AQUÍ y no dentro del atributo del componente.
     *
     * En un atributo HTML no se pueden escapar comillas con barra: el navegador corta el valor en la
     * primera comilla y el resto del texto se cuela en el marcado como si fueran atributos sueltos.
     * Costó tres errores de JavaScript en la consola —«Unexpected token '{'»— que no señalaban a
     * ninguna parte, porque el fallo estaba en el HTML y no en el código.
     */
    $tonoEstado = match (true) {
        $estado === QuoteStatus::Converted => 'ok',
        in_array($estado, [QuoteStatus::Expired, QuoteStatus::Rejected], true) => 'grave',
        default => 'aviso',
    };

    $notaEstado = match (true) {
        $estado === QuoteStatus::Converted && $quote->sale !== null
            => 'Se cobró en la venta '.$quote->sale->code.'.',
        $estado === QuoteStatus::Expired
            => 'Caducó el '.$quote->valid_until?->format('d/m/Y').'. Cambia la fecha si el precio sigue en pie.',
        $quote->valid_until !== null && $dias !== null
            => $dias === 0 ? 'Vence hoy.' : 'Quedan '.$dias.' días de validez.',
        default => 'Sin fecha de caducidad.',
    };
@endphp

<x-layouts.admin :title="'Cotización '.$quote->code" :heading="'Cotización '.$quote->code"
                 :subheading="'Para '.$quote->customer_name">

    {{-- El estado, arriba del todo y en una línea: es lo primero que se viene a saber. --}}
    <x-panel.estado class="mb-4" :tono="$tonoEstado" :nota="$notaEstado"
        :titulo="$estado->label().' · '.money((float) $quote->total)" />

    <div class="grid grid-cols-1 gap-5 lg:grid-cols-[minmax(0,1fr)_21rem]">
        {{-- ── El documento ──────────────────────────────────────────────────────────── --}}
        <div class="bmos-card overflow-hidden">
            <div class="border-b border-slate-100 p-5">
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Detalle</p>
                <p class="mt-0.5 font-semibold text-slate-800">Lo que se le ofreció</p>
            </div>

            <table class="bmos-table">
                <thead>
                    <tr>
                        <th>Concepto</th>
                        <th class="text-right">Cant.</th>
                        <th class="text-right">Precio</th>
                        <th class="text-right">Importe</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($quote->items as $item)
                        <tr>
                            <td>
                                {{ $item->description }}
                                @if ($item->product_id === null)
                                    {{-- Se marca porque cambia lo que pasa al cobrar: un concepto
                                         libre no descuenta existencias ni entra en la venta. --}}
                                    <span class="bmos-badge badge-gray">sin producto</span>
                                @endif
                            </td>
                            <td class="text-right tabular-nums">
                                {{ rtrim(rtrim(number_format((float) $item->quantity, 3, '.', ''), '0'), '.') }}
                            </td>
                            <td class="whitespace-nowrap text-right tabular-nums">{{ money((float) $item->unit_price) }}</td>
                            <td class="whitespace-nowrap text-right tabular-nums">{{ money((float) $item->subtotal) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            <div class="border-t border-slate-100 p-5">
                <dl class="ml-auto max-w-xs space-y-1 text-sm">
                    <div class="flex justify-between"><dt class="text-slate-500">Subtotal</dt><dd class="tabular-nums">{{ money((float) $quote->subtotal) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-slate-500">ITBIS</dt><dd class="tabular-nums">{{ money((float) $quote->tax) }}</dd></div>
                    @if (bccomp((string) $quote->discount_total, '0', 2) > 0)
                        <div class="flex justify-between"><dt class="text-slate-500">Descuento</dt><dd class="tabular-nums">−{{ money((float) $quote->discount_total) }}</dd></div>
                    @endif
                    <div class="flex justify-between border-t border-slate-200 pt-1 text-base font-bold">
                        <dt>Total</dt><dd class="tabular-nums">{{ money((float) $quote->total) }}</dd>
                    </div>
                </dl>

                @if (filled($quote->notes))
                    <p class="mt-4 whitespace-pre-line text-sm text-slate-600">{{ $quote->notes }}</p>
                @endif
            </div>
        </div>

        {{-- ── Qué se puede hacer con ella ───────────────────────────────────────────── --}}
        <div class="space-y-4">
            <div class="bmos-card bmos-card-pad space-y-3">
                <p class="font-semibold text-slate-800">Mandársela al cliente</p>

                @if (blank($quote->customer_phone))
                    <p class="text-sm text-amber-600">
                        Esta cotización no tiene teléfono. Puedes descargar el PDF y mandarlo a mano.
                    </p>
                @else
                    @can('quotes.send')
                        <form method="POST" action="{{ route('panel.quotes.send', $quote) }}">
                            @csrf
                            <button type="submit" class="bmos-btn bmos-btn-primary w-full justify-center">
                                {{ $puedeAdjuntar ? 'Enviar el PDF por WhatsApp' : 'Enviar el enlace por WhatsApp' }}
                            </button>
                        </form>
                    @endcan

                    {{-- Siempre disponible, y a propósito.
                         Por la vía oficial de WhatsApp no se puede escribir a quien no ha escrito
                         antes —es la regla de Meta, no un fallo—, así que el envío desde el sistema
                         puede no ser posible. Esto abre TU WhatsApp con el mensaje ya escrito, que es
                         además como manda las cotizaciones un negocio pequeño: desde su número, con
                         su nombre y su foto. --}}
                    <a href="{{ $enlaceWa }}" target="_blank" rel="noopener"
                       class="bmos-btn w-full justify-center">Abrir en mi WhatsApp</a>
                @endif

                <div class="flex gap-2">
                    <a href="{{ route('panel.quotes.pdf', $quote) }}" target="_blank" rel="noopener"
                       class="bmos-btn flex-1 justify-center">Ver PDF</a>
                    <a href="{{ route('panel.quotes.pdf', ['quote' => $quote, 'mode' => 'descargar']) }}"
                       class="bmos-btn flex-1 justify-center">Descargar</a>
                </div>

                {{-- El enlace que ve el cliente, para poder pegarlo donde haga falta. --}}
                <div x-data="{ copiado: false }">
                    <label class="bmos-field-label">Enlace para el cliente</label>
                    <div class="flex gap-2">
                        <input type="text" readonly :value="$el.dataset.enlace" data-enlace="{{ $enlace }}"
                               class="bmos-input text-xs" x-ref="enlace" @focus="$refs.enlace.select()">
                        <button type="button" class="bmos-btn"
                                @click="navigator.clipboard.writeText($refs.enlace.value); copiado = true; setTimeout(() => copiado = false, 1800)">
                            <span x-text="copiado ? '¡Copiado!' : 'Copiar'"></span>
                        </button>
                    </div>
                    <p class="mt-1 text-xs text-slate-400">Caduca a los 30 días.</p>
                </div>
            </div>

            {{-- ── Cobrarla ──────────────────────────────────────────────────────────── --}}
            @if ($estado === QuoteStatus::Converted)
                <div class="bmos-card bmos-card-pad">
                    <p class="font-semibold text-slate-800">Ya está cobrada</p>
                    @if ($quote->sale !== null)
                        <a href="{{ route('panel.sales') }}" class="mt-1 inline-block text-sm font-semibold text-indigo-600 hover:underline">
                            Ver la venta {{ $quote->sale->code }} →
                        </a>
                    @endif
                </div>
            @elseif ($quote->sePuedeConvertir())
                @can('quotes.convert')
                    <div class="bmos-card bmos-card-pad space-y-3" x-data="{ abierto: false }">
                        <p class="font-semibold text-slate-800">El cliente dijo que sí</p>

                        @foreach ($diferencias as $aviso)
                            {{-- Lo que hay que saber ANTES de cobrar. No se corrige solo: quien decide
                                 es quien tiene al cliente delante. --}}
                            <p class="rounded-lg border border-amber-200 bg-amber-50 px-2.5 py-1.5 text-xs text-amber-800">
                                {{ $aviso }}
                            </p>
                        @endforeach

                        <button type="button" @click="abierto = !abierto" class="bmos-btn bmos-btn-primary w-full justify-center">
                            Cobrar y registrar la venta
                        </button>

                        <form x-show="abierto" x-cloak method="POST"
                              action="{{ route('panel.quotes.convert', $quote) }}" class="space-y-2">
                            @csrf
                            <div>
                                <label class="bmos-field-label">¿Cómo paga?</label>
                                <select name="payment_method" class="bmos-input">
                                    @foreach (\App\Modules\Sales\Enums\PaymentMethod::counterOptions() as $forma)
                                        <option value="{{ $forma->value }}">{{ $forma->label() }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="bmos-field-label">¿Cuánto recibiste?</label>
                                <input type="number" step="0.01" min="0" name="paid" class="bmos-input"
                                       value="{{ number_format((float) $quote->total, 2, '.', '') }}">
                            </div>
                            <button type="submit" class="bmos-btn bmos-btn-primary w-full justify-center">
                                Confirmar el cobro
                            </button>
                            <p class="text-xs text-slate-400">
                                Descuenta existencias y entra en la caja abierta, igual que una venta de mostrador.
                            </p>
                        </form>
                    </div>
                @endcan
            @endif

            {{-- ── El estado, a mano ─────────────────────────────────────────────────── --}}
            @can('quotes.manage')
                @if ($estado !== QuoteStatus::Converted)
                    <div class="bmos-card bmos-card-pad">
                        <p class="mb-2 font-semibold text-slate-800">Marcar</p>
                        <div class="flex flex-wrap gap-2">
                            @foreach ([QuoteStatus::Accepted, QuoteStatus::Rejected] as $opcion)
                                <form method="POST" action="{{ route('panel.quotes.status', $quote) }}">
                                    @csrf @method('PUT')
                                    <input type="hidden" name="status" value="{{ $opcion->value }}">
                                    <button type="submit" class="bmos-btn">{{ $opcion->label() }}</button>
                                </form>
                            @endforeach
                        </div>
                    </div>
                @endif
            @endcan
        </div>
    </div>
</x-layouts.admin>
