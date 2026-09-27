<x-layouts.admin title="Cotizaciones" heading="Cotizaciones"
                subheading="Ofrece precios por escrito y cóbralos con un botón">

    @unless ($hayTabla)
        {{-- La tabla todavía no está: el despliegue va por delante de la migración. Se dice, en vez
             de dar un 500 o —peor— una lista vacía que parece que no hay nada cotizado. --}}
        <x-panel.estado tono="aviso" titulo="Las cotizaciones aún no están activas en este servidor"
            nota="La base de datos todavía no tiene la tabla. En cuanto se aplique la actualización, esta pantalla funciona sola." />
    @else
        <div @can('quotes.manage') x-data="cotizacionesCrud()" @endcan>
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

            @can('quotes.manage')
                @if ($cotizaciones->isNotEmpty())
                    {{-- Cabecera de selección: solo aparece si hay algo que seleccionar. Ni una
                         casilla de "marcar todo" ni la barra tienen sentido sobre una lista vacía. --}}
                    <div class="mb-2 flex items-center gap-2 px-1">
                        <input type="checkbox" @change="alternarPagina($event.target.checked)"
                               :checked="paginaCompleta()" aria-label="Seleccionar todas las de esta página"
                               class="rounded border-slate-300 text-indigo-600">
                        <span class="text-xs text-slate-400">Seleccionar esta página</span>
                    </div>

                    {{-- Barra de selección. Solo aparece con algo marcado: si estuviera siempre,
                         sería un botón de borrado permanente sobre las cotizaciones. --}}
                    <div x-show="marcados.length > 0" x-cloak
                         class="mb-3 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-indigo-100 bg-indigo-50/60 px-4 py-3">
                        <span class="text-sm font-semibold text-slate-700" x-text="etiquetaSeleccion()"></span>
                        <button type="button" @click="confirmarBorrado()"
                                class="inline-flex items-center gap-2 rounded-xl bg-rose-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-rose-700">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-4 w-4"><path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0"/></svg>
                            Eliminar
                        </button>
                    </div>

                    <form method="POST" action="{{ route('panel.quotes.destroyMultiple') }}" id="borrar_cotizaciones" class="hidden">
                        @csrf @method('DELETE')
                        <template x-for="id in marcados" :key="id">
                            <input type="hidden" name="ids[]" :value="id">
                        </template>
                    </form>
                @endif
            @endcan

            <div class="bmos-card overflow-hidden">
                @forelse ($cotizaciones as $quote)
                    @php $estado = $quote->estadoReal(); @endphp

                    <div class="bmos-cot-fila" @can('quotes.manage') :class="marcados.includes({{ $quote->id }}) ? 'bg-indigo-50/50' : ''" @endcan>
                        <div class="flex min-w-0 items-center gap-3">
                            @can('quotes.manage')
                                <input type="checkbox" value="{{ $quote->id }}" x-model.number="marcados"
                                       aria-label="Seleccionar {{ $quote->code }}"
                                       class="shrink-0 rounded border-slate-300 text-indigo-600">
                            @endcan

                            <a href="{{ route('panel.quotes.show', $quote) }}" class="min-w-0 text-inherit no-underline">
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
                            </a>
                        </div>

                        <div class="flex shrink-0 items-center gap-3">
                            <a href="{{ route('panel.quotes.show', $quote) }}" class="flex items-center gap-3 text-inherit no-underline">
                                <span class="font-semibold tabular-nums text-slate-800">{{ money((float) $quote->total) }}</span>
                                <span class="bmos-badge {{ $estado->tono() }}">{{ $estado->label() }}</span>
                            </a>

                            @can('quotes.manage')
                                @if ($quote->sePuedeEditar() || $quote->sePuedeEliminar())
                                    <div class="flex items-center gap-0.5 border-l border-slate-100 pl-2.5">
                                        @if ($quote->sePuedeEditar())
                                            <a href="{{ route('panel.quotes.edit', $quote) }}" title="Editar"
                                               class="rounded-lg p-1.5 text-slate-500 hover:bg-indigo-50 hover:text-indigo-600">
                                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" style="width:1.05rem;height:1.05rem"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125"/></svg>
                                            </a>
                                        @endif

                                        @if ($quote->sePuedeEliminar())
                                            {{-- Borrado lógico: sigue en la base, solo deja de aparecer aquí.
                                                 El aviso se calcula ANTES de la etiqueta: un @if/@endif partiendo
                                                 los atributos de un componente <x-…> no compila bien —Blade
                                                 procesa la etiqueta entera de una vez—, así que aquí solo se
                                                 pasa una expresión ya resuelta. --}}
                                            <x-panel.confirm-action
                                                :action="route('panel.quotes.destroy', $quote)"
                                                title="¿Eliminar {{ $quote->code }}?"
                                                message="Deja de aparecer en la lista de cotizaciones."
                                                :note="$quote->status === \App\Modules\Quotes\Enums\QuoteStatus::Accepted
                                                    ? 'El cliente ya había dicho que sí. Si prefieres conservarla, márcala como rechazada en vez de eliminarla.'
                                                    : null"
                                                irreversible
                                                tooltip="Eliminar"
                                                class="rounded-lg p-1.5 text-slate-500 hover:bg-rose-50 hover:text-rose-600">
                                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" style="width:1.05rem;height:1.05rem"><path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0"/></svg>
                                            </x-panel.confirm-action>
                                        @endif
                                    </div>
                                @endif
                            @endcan
                        </div>
                    </div>
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
        </div>
    @endunless

    @can('quotes.manage')
        {{-- El script va aquí mismo: este layout no tiene pila de scripts, así que apilarlo se
             perdería en silencio y la selección quedaría muerta sin dar un solo error. --}}
        <script>
            function cotizacionesCrud() {
                return {
                    marcados: [],
                    enPagina: @js($hayTabla ? $cotizaciones->pluck('id')->all() : []),

                    paginaCompleta() {
                        return this.enPagina.length > 0 && this.enPagina.every((id) => this.marcados.includes(id));
                    },

                    alternarPagina(marcar) {
                        this.marcados = marcar ? [...this.enPagina] : [];
                    },

                    etiquetaSeleccion() {
                        const n = this.marcados.length;
                        return n === 1 ? '1 cotización seleccionada' : `${n} cotizaciones seleccionadas`;
                    },

                    async confirmarBorrado() {
                        await window.confirmarBorrarCotizaciones({
                            cantidad: this.marcados.length,
                            formulario: 'borrar_cotizaciones',
                        });
                    },
                };
            }
        </script>
    @endcan
</x-layouts.admin>
