@props(['selected' => null])

{{-- Selector de icono de categoría.

     Botones y no un <select>: el icono se elige mirándolo, y una lista desplegable de emojis
     obligaría a abrirla y recorrerla para ver lo mismo que aquí se ve de golpe. El valor viaja en un
     hidden, así que el formulario lo envía como un campo normal. --}}
<div x-data="{ icono: @js($selected) }">
    <input type="hidden" name="icon" :value="icono">

    <div class="flex items-center justify-between gap-2">
        <label class="bmos-field-label">Icono (opcional)</label>
        <button type="button" x-show="icono" x-cloak @click="icono = null"
                class="text-xs font-semibold text-slate-400 hover:text-slate-600">Quitar</button>
    </div>

    <p class="mb-2 text-xs text-slate-400">Se muestra en la barra lateral del punto de venta.</p>

    @foreach (\App\Modules\Inventory\Support\CategoryIcons::GROUPS as $grupo => $iconos)
        <p class="mt-2 mb-1 text-[11px] font-semibold uppercase tracking-wide text-slate-400">{{ $grupo }}</p>
        <div class="flex flex-wrap gap-1.5">
            @foreach ($iconos as $icono)
                <button type="button" @click="icono = @js($icono)"
                        :class="icono === @js($icono) ? 'border-indigo-500 bg-indigo-50' : 'border-slate-200'"
                        class="grid h-11 w-11 place-items-center rounded-lg border text-xl transition hover:border-indigo-300"
                        title="{{ $grupo }}">
                    {{ $icono }}
                </button>
            @endforeach
        </div>
    @endforeach
</div>
