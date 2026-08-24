<x-layouts.admin title="Cotizaciones" heading="Cotizaciones"
                subheading="Ofrece precios por escrito y cóbralos con un botón">

    @unless ($hayTabla)
        {{-- La tabla todavía no está: el despliegue va por delante de la migración. Se dice, en vez
             de dar un 500 o —peor— una lista vacía que parece que no hay nada cotizado. --}}
        <x-panel.estado tono="aviso" titulo="Las cotizaciones aún no están activas en este servidor"
            nota="La base de datos todavía no tiene la tabla. En cuanto se aplique la actualización, esta pantalla funciona sola." />
    @else
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <p class="text-sm text-slate-500">
                @if ($cotizaciones->total() === 0)
                    Todavía no has hecho ninguna.
                @else
                    {{ $cotizaciones->total() }} {{ $cotizaciones->total() === 1 ? 'cotización' : 'cotizaciones' }}.
                @endif
            </p>

            @can('quotes.manage')
                <a href="{{ route('panel.quotes.create') }}" class="bmos-btn bmos-btn-primary">Nueva cotización</a>
            @endcan
        </div>

        <div class="bmos-card overflow-hidden">
            @forelse ($cotizaciones as $quote)
                @php $estado = $quote->estadoReal(); @endphp

                <a href="{{ route('panel.quotes.show', $quote) }}" class="bmos-cot-fila">
                    <div class="min-w-0">
                        <p class="font-semibold text-slate-800">
                            {{ $quote->code }} · {{ $quote->customer_name }}
                        </p>
                        <p class="text-xs text-slate-500">
                            {{ $quote->items->count() }}
                            {{ $quote->items->count() === 1 ? 'línea' : 'líneas' }}
                            · {{ $quote->created_at?->format('d/m/Y') }}

                            @if ($quote->valid_until)
                                @php $dias = $quote->diasDeVigencia(); @endphp
                                {{-- Los días que quedan, no solo la fecha: es lo que decide si toca
                                     llamar al cliente hoy o puede esperar. --}}
                                @if ($dias !== null && $dias >= 0 && $estado !== \App\Modules\Quotes\Enums\QuoteStatus::Converted)
                                    · <span class="{{ $dias <= 3 ? 'font-semibold text-amber-600' : '' }}">
                                        {{ $dias === 0 ? 'vence hoy' : "quedan {$dias} días" }}
                                    </span>
                                @endif
                            @endif
                        </p>
                    </div>

                    <div class="flex shrink-0 items-center gap-3">
                        <span class="font-semibold tabular-nums text-slate-800">{{ money((float) $quote->total) }}</span>
                        <span class="bmos-badge {{ $estado->tono() }}">{{ $estado->label() }}</span>
                    </div>
                </a>
            @empty
                <p class="bmos-empty">
                    Aquí aparecerán los precios que ofrezcas por escrito.
                    @can('quotes.manage')
                        <a href="{{ route('panel.quotes.create') }}" class="font-semibold text-indigo-600 hover:underline">Crea la primera</a>.
                    @endcan
                </p>
            @endforelse
        </div>

        @if ($cotizaciones->hasPages())
            <div class="mt-4">{{ $cotizaciones->links() }}</div>
        @endif
    @endunless
</x-layouts.admin>
